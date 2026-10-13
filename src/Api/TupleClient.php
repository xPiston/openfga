<?php

declare(strict_types=1);

namespace Piston\OpenFga\Api;

use Generator;
use Piston\OpenFga\Exception\InvalidArgument;
use Piston\OpenFga\Http\Client;
use Piston\OpenFga\Http\Request;
use Piston\OpenFga\Model\Tuple;
use Piston\OpenFga\Model\TupleKey;
use Piston\OpenFga\Model\TypedObject;
use Piston\OpenFga\Model\User;

/**
 * Writing and reading relationship tuples.
 *
 * The thing to understand about `write` is that it is **transactional**: every
 * tuple in the call succeeds or none does. That is a feature - "add Anne to
 * the group and remove Bob" is one atomic change - and it is also why this
 * client does not quietly split a large list into several calls. Splitting
 * would turn one atomic change into several that can half-fail, which is
 * worse than an error, so a list over the server's limit is refused here with
 * an explanation rather than chunked behind your back.
 *
 * The limit is the server's `--max-tuples-per-write`, 100 by default. It is a
 * constructor argument because a deployment can change it, and a client that
 * hard-coded 100 would refuse writes a tuned server would have accepted.
 */
final class TupleClient
{
    /** OpenFGA's own default for --max-tuples-per-write. */
    public const DEFAULT_MAX_PER_WRITE = 100;

    public function __construct(
        private readonly Client $client,
        private readonly ?string $modelId = null,
        private readonly int $maxPerWrite = self::DEFAULT_MAX_PER_WRITE,
    ) {}

    public function withModel(string $modelId): self
    {
        return new self($this->client, $modelId, $this->maxPerWrite);
    }

    /**
     * Write and delete tuples, atomically.
     *
     * @param  list<TupleKey>  $writes
     * @param  list<TupleKey>  $deletes
     */
    public function write(array $writes, array $deletes = [], ?string $modelId = null): void
    {
        if ($writes === [] && $deletes === []) {
            throw InvalidArgument::because('a write', 'has nothing to write and nothing to delete');
        }

        $total = count($writes) + count($deletes);

        if ($total > $this->maxPerWrite) {
            throw InvalidArgument::because(
                'a write',
                sprintf(
                    'carries %d tuples and the server accepts %d per call. This is not split automatically '
                    .'because a write is transactional: splitting it would turn one atomic change into '
                    .'several that can half-fail. Either send fewer, or loop and accept that the parts are '
                    .'independent',
                    $total,
                    $this->maxPerWrite,
                ),
            );
        }

        $body = [];

        if ($writes !== []) {
            $body['writes'] = ['tuple_keys' => array_map(
                static fn (TupleKey $t): array => $t->toArray(),
                array_values($writes),
            )];
        }

        if ($deletes !== []) {
            // A delete identifies a tuple; it carries no condition, and
            // sending one is an error rather than a filter.
            $body['deletes'] = ['tuple_keys' => array_map(
                static fn (TupleKey $t): array => [
                    'user' => $t->user->value(),
                    'relation' => $t->relation,
                    'object' => $t->object->value(),
                ],
                array_values($deletes),
            )];
        }

        $model = $modelId ?? $this->modelId;

        if ($model !== null) {
            $body['authorization_model_id'] = $model;
        }

        $this->client->send(Request::post('write', $body));
    }

    /**
     * One tuple, written.
     *
     * @param  array<string, mixed>  $conditionContext
     */
    public function grant(
        User|string $user,
        string $relation,
        TypedObject|string $object,
        ?string $conditionName = null,
        array $conditionContext = [],
    ): void {
        $key = TupleKey::of($user, $relation, $object);

        if ($conditionName !== null) {
            $key = $key->withCondition($conditionName, $conditionContext);
        }

        $this->write([$key]);
    }

    /** One tuple, deleted. */
    public function revoke(User|string $user, string $relation, TypedObject|string $object): void
    {
        $this->write([], [TupleKey::of($user, $relation, $object)]);
    }

    /**
     * Read tuples, filtered and paginated.
     *
     * The filter is a partial tuple: an object alone, an object and a
     * relation, or a user. Unlike a check this does no graph traversal at all
     * - it returns tuples that were written, not permissions that follow from
     * them. `document:1` having no `viewer` tuples does not mean nobody can
     * view it.
     *
     * @return Page<Tuple>
     */
    public function read(
        TypedObject|string|null $object = null,
        ?string $relation = null,
        User|string|null $user = null,
        ?int $pageSize = null,
        ?string $continuationToken = null,
    ): Page {
        $filter = [];

        if ($object !== null) {
            $filter['object'] = TypedObject::from($object)->value();
        }

        if ($relation !== null && trim($relation) !== '') {
            $filter['relation'] = trim($relation);
        }

        if ($user !== null) {
            $filter['user'] = User::from($user)->value();
        }

        $body = [];

        if ($filter !== []) {
            $body['tuple_key'] = $filter;
        }

        if ($pageSize !== null) {
            $body['page_size'] = $pageSize;
        }

        if ($continuationToken !== null && $continuationToken !== '') {
            $body['continuation_token'] = $continuationToken;
        }

        $reply = $this->client->send(Request::post('read', $body));

        $tuples = [];

        foreach ($reply->array('tuples') as $tuple) {
            if (! is_array($tuple)) {
                continue;
            }

            /** @var array<string, mixed> $tuple */
            $tuples[] = Tuple::fromArray($tuple);
        }

        return new Page($tuples, Page::token($reply->get('continuation_token')));
    }

    /**
     * Every matching tuple, following the pages.
     *
     * A generator rather than an array, because "every tuple on this object"
     * is a number nobody knows in advance.
     *
     * @return Generator<int, Tuple>
     */
    public function readAll(
        TypedObject|string|null $object = null,
        ?string $relation = null,
        User|string|null $user = null,
        ?int $pageSize = null,
    ): Generator {
        $token = null;

        do {
            $page = $this->read($object, $relation, $user, $pageSize, $token);

            foreach ($page->items as $tuple) {
                yield $tuple;
            }

            $token = $page->continuationToken;
        } while ($token !== null);
    }

    /**
     * The change log.
     *
     * This is the right way to mirror OpenFGA into something else - a search
     * index, a cache, a report. Store the continuation token, come back later,
     * and apply what happened since.
     *
     * @return Page<Change>
     */
    public function changes(
        ?string $type = null,
        ?int $pageSize = null,
        ?string $continuationToken = null,
        ?string $startTime = null,
    ): Page {
        $query = [];

        if ($type !== null && trim($type) !== '') {
            $query['type'] = trim($type);
        }

        if ($pageSize !== null) {
            $query['page_size'] = $pageSize;
        }

        if ($continuationToken !== null && $continuationToken !== '') {
            $query['continuation_token'] = $continuationToken;
        }

        if ($startTime !== null && trim($startTime) !== '') {
            $query['start_time'] = trim($startTime);
        }

        $reply = $this->client->send(Request::get('changes', $query));

        $changes = [];

        foreach ($reply->array('changes') as $change) {
            if (! is_array($change)) {
                continue;
            }

            /** @var array<string, mixed> $change */
            $changes[] = Change::fromArray($change);
        }

        return new Page($changes, Page::token($reply->get('continuation_token')));
    }

    /**
     * Every change since a token, following the pages.
     *
     * Ends when the server stops handing out a new token. Note that the change
     * log is append-only and the final token stays valid, so a mirror can
     * keep the last one and resume from it on the next run.
     *
     * @return Generator<int, Change>
     */
    public function changesSince(?string $continuationToken = null, ?string $type = null, ?int $pageSize = null): Generator
    {
        $token = $continuationToken;

        do {
            $page = $this->changes($type, $pageSize, $token);

            foreach ($page->items as $change) {
                yield $change;
            }

            $previous = $token;
            $token = $page->continuationToken;

            // The log's last token is returned again with no new entries,
            // which would otherwise be an infinite loop.
            if ($page->items === [] || $token === $previous) {
                return;
            }
        } while ($token !== null);
    }
}
