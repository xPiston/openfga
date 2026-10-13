<?php

declare(strict_types=1);

namespace Piston\OpenFga\Api;

use Generator;
use Piston\OpenFga\Dsl\Ast\AuthorizationModel;
use Piston\OpenFga\Dsl\Dsl;
use Piston\OpenFga\Exception\InvalidArgument;
use Piston\OpenFga\Http\Client;
use Piston\OpenFga\Http\Request;

/**
 * Authorization models: write one, read one back, list them.
 *
 * Models are **immutable and append-only**. Writing one does not replace the
 * last; it adds a version and returns its id. Nothing is ever edited and
 * nothing is ever deleted, which is what makes pinning a model id safe - the
 * id you shipped with keeps answering the same way for ever.
 *
 * `write()` takes the DSL directly, which is the point of this package:
 *
 *     $id = $fga->models()->write(file_get_contents('authorization-model.fga'));
 *
 * A string that starts with `{` is treated as JSON and anything else as DSL.
 * That is not a guess - the DSL cannot begin with a brace, and JSON cannot
 * begin with anything else.
 */
final class ModelClient
{
    public function __construct(private readonly Client $client) {}

    /**
     * Add a model version. Returns its id.
     *
     * @param  AuthorizationModel|array<string, mixed>|string  $model  DSL text, JSON, or a parsed model
     */
    public function write(AuthorizationModel|array|string $model): string
    {
        $parsed = self::coerce($model);

        if ($parsed->types === []) {
            throw InvalidArgument::because(
                'the authorization model',
                'declares no types, and OpenFGA refuses a model with none - this is valid DSL but not a '
                .'deployable model',
            );
        }

        $reply = $this->client->send(Request::post('authorization-models', $parsed->toArray()));
        $id = $reply->string('authorization_model_id');

        if ($id === null) {
            throw InvalidArgument::because('the write', 'came back without an authorization_model_id');
        }

        return $id;
    }

    public function read(string $id): AuthorizationModel
    {
        if (trim($id) === '') {
            throw InvalidArgument::because('the authorization model id', 'is empty');
        }

        $reply = $this->client->send(Request::get('authorization-models/'.rawurlencode(trim($id))));
        $model = $reply->array('authorization_model');

        /** @var array<string, mixed> $model */
        return AuthorizationModel::fromArray($model);
    }

    /**
     * Model versions, newest first - which is the order OpenFGA returns.
     *
     * @return Page<AuthorizationModel>
     */
    public function list(?int $pageSize = null, ?string $continuationToken = null): Page
    {
        $query = [];

        if ($pageSize !== null) {
            $query['page_size'] = $pageSize;
        }

        if ($continuationToken !== null && $continuationToken !== '') {
            $query['continuation_token'] = $continuationToken;
        }

        $reply = $this->client->send(Request::get('authorization-models', $query));

        $models = [];

        foreach ($reply->array('authorization_models') as $model) {
            if (! is_array($model)) {
                continue;
            }

            /** @var array<string, mixed> $model */
            $models[] = AuthorizationModel::fromArray($model);
        }

        return new Page($models, Page::token($reply->get('continuation_token')));
    }

    /**
     * The newest model, or null if the store has none.
     *
     * Useful for a first deployment and for tooling. Not useful on a request
     * path: calling this to find an id and then checking against it is two
     * round trips, and the answer can change between them. Pin the id at
     * deploy time instead.
     */
    public function latest(): ?AuthorizationModel
    {
        $page = $this->list(1);

        return $page->items[0] ?? null;
    }

    /** @return Generator<int, AuthorizationModel> */
    public function all(?int $pageSize = null): Generator
    {
        $token = null;

        do {
            $page = $this->list($pageSize, $token);

            foreach ($page->items as $model) {
                yield $model;
            }

            $token = $page->continuationToken;
        } while ($token !== null);
    }

    /**
     * @param  AuthorizationModel|array<string, mixed>|string  $model
     */
    private static function coerce(AuthorizationModel|array|string $model): AuthorizationModel
    {
        if ($model instanceof AuthorizationModel) {
            return $model;
        }

        if (is_array($model)) {
            return AuthorizationModel::fromArray($model);
        }

        $trimmed = ltrim($model);

        return str_starts_with($trimmed, '{') ? Dsl::fromJson($model) : Dsl::parse($model);
    }
}
