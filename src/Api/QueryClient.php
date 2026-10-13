<?php

declare(strict_types=1);

namespace Piston\OpenFga\Api;

use Generator;
use Piston\OpenFga\Exception\InvalidArgument;
use Piston\OpenFga\Http\Client;
use Piston\OpenFga\Http\Request;
use Piston\OpenFga\Model\Relation;
use Piston\OpenFga\Model\TupleKey;
use Piston\OpenFga\Model\TypedObject;
use Piston\OpenFga\Model\User;

/**
 * The questions: may this user do this to this object, and what else?
 *
 * Five of them, and which one to ask is the decision that decides whether an
 * application is fast or slow:
 *
 *   check              one yes/no. Cheap. On the request path.
 *   batchCheck         many yes/nos in one call. What a list page needs.
 *   listObjects        "which documents may Anne see?" Expensive, and bounded
 *                      by a server-side limit - see the method.
 *   listUsers          "who may see this document?" The mirror image.
 *   expand             why. A tree, for debugging rather than for serving.
 *
 * Every one of them takes an **authorization model id**, and passing it is the
 * single most important thing an integration does here. Without it OpenFGA
 * uses whichever model is newest - so deploying a model change silently
 * changes the answers a running application gets, mid-request, with no
 * deployment of its own. With it, the answers are pinned to a model you
 * shipped. The id is therefore a constructor argument of this client rather
 * than an optional afterthought on each call.
 */
final class QueryClient
{
    public function __construct(
        private readonly Client $client,
        private readonly ?string $modelId = null,
    ) {}

    public function withModel(string $modelId): self
    {
        return new self($this->client, $modelId);
    }

    /**
     * May this user do this to this object?
     *
     * `context` and `contextualTuples` are what make conditions usable: the
     * first supplies the values a condition's CEL expression reads, and the
     * second supplies tuples that exist only for this one question - which is
     * how you answer "could Anne see it *if* we added her to this group"
     * without writing anything.
     *
     * @param  array<string, mixed>  $context
     * @param  list<TupleKey>  $contextualTuples
     */
    public function check(
        User|string $user,
        string $relation,
        TypedObject|string $object,
        array $context = [],
        array $contextualTuples = [],
        ?string $modelId = null,
    ): CheckResult {
        $body = [
            'tuple_key' => TupleKey::of($user, $relation, $object)->toArray(),
        ];

        $body += $this->extras($context, $contextualTuples, $modelId);

        return CheckResult::fromArray($this->client->send(Request::post('check', $body))->body);
    }

    /**
     * The same question, answered as a plain bool.
     *
     * @param  array<string, mixed>  $context
     * @param  list<TupleKey>  $contextualTuples
     */
    public function allows(
        User|string $user,
        string $relation,
        TypedObject|string $object,
        array $context = [],
        array $contextualTuples = [],
        ?string $modelId = null,
    ): bool {
        return $this->check($user, $relation, $object, $context, $contextualTuples, $modelId)->allowed;
    }

    /**
     * Many checks in one call, answers in the order asked.
     *
     * OpenFGA correlates batch results by an id rather than by position, and
     * requires one per check. Generating them here and mapping back means the
     * caller gets a list that lines up with the list they passed, which is
     * what a list page actually needs - and removes a class of bug where
     * results are read in whatever order the server returned them.
     *
     * @param  list<TupleKey>  $keys
     * @param  array<string, mixed>  $context
     * @param  list<TupleKey>  $contextualTuples
     * @return list<CheckResult>
     */
    public function batchCheck(
        array $keys,
        array $context = [],
        array $contextualTuples = [],
        ?string $modelId = null,
    ): array {
        if ($keys === []) {
            return [];
        }

        $checks = [];
        $order = [];

        foreach (array_values($keys) as $position => $key) {
            $correlationId = 'c'.$position;
            $order[$correlationId] = $position;

            $check = ['tuple_key' => $key->toArray(), 'correlation_id' => $correlationId];

            if ($context !== []) {
                $check['context'] = $context;
            }

            if ($contextualTuples !== []) {
                $check['contextual_tuples'] = [
                    'tuple_keys' => array_map(static fn (TupleKey $t): array => $t->toArray(), $contextualTuples),
                ];
            }

            $checks[] = $check;
        }

        $body = ['checks' => $checks];
        $model = $modelId ?? $this->modelId;

        if ($model !== null) {
            $body['authorization_model_id'] = $model;
        }

        $reply = $this->client->send(Request::post('batch-check', $body));
        $raw = $reply->array('result');

        $results = [];

        foreach ($order as $correlationId => $position) {
            $one = $raw[$correlationId] ?? null;

            /** @var array<string, mixed> $one */
            $one = is_array($one) ? $one : [];

            $results[$position] = CheckResult::fromArray($one, (string) $correlationId);
        }

        ksort($results);

        return array_values($results);
    }

    /**
     * Which objects of this type may the user touch?
     *
     * Two warnings, because this is the method that surprises people in
     * production. It is **bounded**: OpenFGA stops at
     * `--listObjects-max-results` (1000 by default) and at
     * `--listObjects-deadline` (3s by default), and it does not tell you it
     * truncated. And it is **expensive** compared with a check, because it
     * explores the graph rather than walking one path.
     *
     * For a paged list of objects you already have, `batchCheck` is almost
     * always the right call instead. This one is for "show me everything I can
     * see", and for that, prefer `streamListObjects`, which is not bounded by
     * the result limit.
     *
     * @param  array<string, mixed>  $context
     * @param  list<TupleKey>  $contextualTuples
     * @return list<TypedObject>
     */
    public function listObjects(
        User|string $user,
        string $relation,
        string $type,
        array $context = [],
        array $contextualTuples = [],
        ?string $modelId = null,
    ): array {
        $reply = $this->client->send(Request::post('list-objects', $this->listObjectsBody(
            $user,
            $relation,
            $type,
            $context,
            $contextualTuples,
            $modelId,
        )));

        $objects = [];

        foreach ($reply->array('objects') as $object) {
            if (is_string($object) && $object !== '') {
                $objects[] = TypedObject::parse($object);
            }
        }

        return $objects;
    }

    /**
     * The same question, streamed - one object at a time, no result cap.
     *
     * The server answers with a sequence of JSON documents rather than one
     * array, so a result set larger than memory is readable and the first
     * result arrives without waiting for the last.
     *
     * @param  array<string, mixed>  $context
     * @param  list<TupleKey>  $contextualTuples
     * @return Generator<int, TypedObject>
     */
    public function streamListObjects(
        User|string $user,
        string $relation,
        string $type,
        array $context = [],
        array $contextualTuples = [],
        ?string $modelId = null,
    ): Generator {
        $request = Request::post('streamed-list-objects', $this->listObjectsBody(
            $user,
            $relation,
            $type,
            $context,
            $contextualTuples,
            $modelId,
        ));

        foreach ($this->client->stream($request) as $line) {
            // Each line is {"result": {"object": "doc:1"}}; an error mid-stream
            // arrives as {"error": {...}} after a 200, which is the one case
            // where a failure cannot be a status code.
            $error = $line['error'] ?? null;

            if (is_array($error)) {
                /** @var array<string, mixed> $error */
                throw \Piston\OpenFga\Exception\ApiError::from(200, $error, 'POST', 'streamed-list-objects');
            }

            $result = $line['result'] ?? null;
            $object = is_array($result) ? ($result['object'] ?? null) : null;

            if (is_string($object) && $object !== '') {
                yield TypedObject::parse($object);
            }
        }
    }

    /**
     * Who may do this to this object?
     *
     * `$filter` says which kind of user to return - `'user'` for subjects, or
     * `'group#member'` for usersets. **One kind per call**: the wire format is
     * an array, and the server rejects anything but exactly one item with
     * "invalid ListUsersRequest.UserFilters: value must contain exactly 1
     * item(s)". Measured, not read - the OpenAPI definition declares a plain
     * array with no such constraint, so a signature taking a list would
     * invite a 400 on the second element. Ask twice instead.
     *
     * The wildcard comes back as a user, not as a separate field: a relation
     * granted `[user:*]` yields `user:*` under the plain `user` filter.
     *
     * @param  array<string, mixed>  $context
     * @param  list<TupleKey>  $contextualTuples
     * @return list<User>
     */
    public function listUsers(
        TypedObject|string $object,
        string $relation,
        string $filter,
        array $context = [],
        array $contextualTuples = [],
        ?string $modelId = null,
    ): array {
        $filter = trim($filter);

        if ($filter === '') {
            throw InvalidArgument::because(
                'listUsers',
                'needs a user filter, such as "user" or "group#member"',
            );
        }

        $object = TypedObject::from($object);
        $parts = explode('#', $filter, 2);

        $body = [
            'object' => ['type' => $object->type, 'id' => $object->id],
            'relation' => Relation::assertName($relation),
            'user_filters' => [
                count($parts) === 2
                    ? ['type' => $parts[0], 'relation' => $parts[1]]
                    : ['type' => $parts[0]],
            ],
        ];

        $body += $this->extras($context, $contextualTuples, $modelId);

        $reply = $this->client->send(Request::post('list-users', $body));

        $users = [];

        foreach ($reply->array('users') as $user) {
            if (! is_array($user)) {
                continue;
            }

            /** @var array<string, mixed> $user */
            $parsed = self::userFromArray($user);

            if ($parsed !== null) {
                $users[] = $parsed;
            }
        }

        return $users;
    }

    /**
     * Why - as a tree.
     *
     * For working out why a check said no. Note that expand takes no user: it
     * expands a relation on an object into everybody who holds it, which is a
     * different question from `check` and answers it one level at a time.
     */
    public function expand(
        TypedObject|string $object,
        string $relation,
        ?string $modelId = null,
    ): ExpandTree {
        $object = TypedObject::from($object);

        $body = [
            'tuple_key' => [
                'object' => $object->value(),
                'relation' => Relation::assertName($relation),
            ],
        ];

        $model = $modelId ?? $this->modelId;

        if ($model !== null) {
            $body['authorization_model_id'] = $model;
        }

        return ExpandTree::fromArray($this->client->send(Request::post('expand', $body))->body);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<TupleKey>  $contextualTuples
     * @return array<string, mixed>
     */
    private function listObjectsBody(
        User|string $user,
        string $relation,
        string $type,
        array $context,
        array $contextualTuples,
        ?string $modelId,
    ): array {
        if (trim($type) === '') {
            throw InvalidArgument::because('listObjects', 'needs an object type');
        }

        $body = [
            'user' => User::from($user)->value(),
            'relation' => Relation::assertName($relation),
            'type' => trim($type),
        ];

        return $body + $this->extras($context, $contextualTuples, $modelId);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<TupleKey>  $contextualTuples
     * @return array<string, mixed>
     */
    private function extras(array $context, array $contextualTuples, ?string $modelId): array
    {
        $extras = [];
        $model = $modelId ?? $this->modelId;

        if ($model !== null) {
            $extras['authorization_model_id'] = $model;
        }

        if ($context !== []) {
            $extras['context'] = $context;
        }

        if ($contextualTuples !== []) {
            $extras['contextual_tuples'] = [
                'tuple_keys' => array_map(static fn (TupleKey $t): array => $t->toArray(), $contextualTuples),
            ];
        }

        return $extras;
    }

    /** @param array<string, mixed> $json */
    private static function userFromArray(array $json): ?User
    {
        $object = $json['object'] ?? null;

        if (is_array($object)) {
            $type = $object['type'] ?? null;
            $id = $object['id'] ?? null;

            if (is_string($type) && $type !== '' && is_string($id) && $id !== '') {
                return User::of($type, $id);
            }
        }

        $userset = $json['userset'] ?? null;

        if (is_array($userset)) {
            $type = $userset['type'] ?? null;
            $id = $userset['id'] ?? null;
            $relation = $userset['relation'] ?? null;

            if (is_string($type) && $type !== '' && is_string($id) && $id !== '' && is_string($relation) && $relation !== '') {
                return User::userset($type, $id, $relation);
            }
        }

        $wildcard = $json['wildcard'] ?? null;

        if (is_array($wildcard)) {
            $type = $wildcard['type'] ?? null;

            if (is_string($type) && $type !== '') {
                return User::everyone($type);
            }
        }

        return null;
    }
}
