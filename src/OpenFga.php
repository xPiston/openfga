<?php

declare(strict_types=1);

namespace Piston\OpenFga;

use Piston\OpenFga\Api\AssertionClient;
use Piston\OpenFga\Api\CheckResult;
use Piston\OpenFga\Api\ModelClient;
use Piston\OpenFga\Api\QueryClient;
use Piston\OpenFga\Api\StoreClient;
use Piston\OpenFga\Api\TupleClient;
use Piston\OpenFga\Configuration\Credentials;
use Piston\OpenFga\Configuration\Endpoint;
use Piston\OpenFga\Configuration\NoCredentials;
use Piston\OpenFga\Configuration\StoreId;
use Piston\OpenFga\Exception\MissingHttpClient;
use Piston\OpenFga\Http\Client;
use Piston\OpenFga\Model\TupleKey;
use Piston\OpenFga\Model\TypedObject;
use Piston\OpenFga\Model\User;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * OpenFGA, in one object.
 *
 *     $fga = OpenFga::at('http://localhost:8080', store: $storeId, model: $modelId);
 *
 *     if ($fga->check('user:anne', 'viewer', 'document:budget')) {
 *         // ...
 *     }
 *
 * Five doors - stores, models, tuples, queries, assertions - and a `check`
 * shortcut on the front, because that is the one call an application makes a
 * hundred times for every time it makes any of the others.
 *
 * The constructor takes a configured Client; `create()` and `at()` build one.
 * That split is what makes `withStore()` and `withModel()` cheap and honest:
 * they hand the same transport along rather than going back to discovery for
 * a new one, so credentials survive, and so does the connection pool.
 *
 * ## The model id is not optional, morally
 *
 * Every query takes an authorization model id, and leaving it out is the
 * mistake this class is shaped to discourage. Without one, OpenFGA evaluates
 * against whichever model version is newest. Models are append-only, so
 * deploying a change breaks nothing retroactively - but a running
 * application's answers change the moment somebody writes a new model, with
 * no deployment of its own and no way to notice. Pin it:
 *
 *     OpenFga::at($url, store: $id, model: '01HQ...')
 *
 * and the answers are the ones you tested against.
 */
final class OpenFga
{
    public function __construct(
        private readonly Client $client,
        private readonly ?string $modelId = null,
        private readonly int $maxTuplesPerWrite = TupleClient::DEFAULT_MAX_PER_WRITE,
    ) {}

    /**
     * Build one, discovering a PSR-18 client if none is given.
     */
    public static function create(
        ?Endpoint $endpoint = null,
        ?Credentials $credentials = null,
        StoreId|string|null $store = null,
        ?string $model = null,
        ?ClientInterface $http = null,
        ?RequestFactoryInterface $requests = null,
        ?StreamFactoryInterface $streams = null,
        int $maxTuplesPerWrite = TupleClient::DEFAULT_MAX_PER_WRITE,
    ): self {
        $transport = $http ?? Discovery::client();
        $requestFactory = $requests ?? Discovery::requestFactory();
        $streamFactory = $streams ?? Discovery::streamFactory();

        if ($transport === null || $requestFactory === null || $streamFactory === null) {
            throw MissingHttpClient::create();
        }

        return new self(
            new Client(
                $endpoint ?? new Endpoint,
                $credentials ?? NoCredentials::create(),
                $transport,
                $requestFactory,
                $streamFactory,
                self::storeId($store),
            ),
            $model,
            $maxTuplesPerWrite,
        );
    }

    /** The short way: a URL, and optionally the store and model to work in. */
    public static function at(
        string $url = Endpoint::DEFAULT_URL,
        StoreId|string|null $store = null,
        ?string $model = null,
        ?Credentials $credentials = null,
    ): self {
        return self::create(
            endpoint: new Endpoint($url),
            credentials: $credentials,
            store: $store,
            model: $model,
        );
    }

    /** The same connection, pointed at another store. */
    public function withStore(StoreId|string $store): self
    {
        $id = self::storeId($store);

        if ($id === null) {
            throw Exception\InvalidArgument::because('withStore', 'was given no store');
        }

        return new self($this->client->withStore($id), $this->modelId, $this->maxTuplesPerWrite);
    }

    /**
     * The same connection, pinned to an authorization model version.
     *
     * The call to make once at boot, with an id from your deployment, so that
     * every query below is answered by the model you tested.
     */
    public function withModel(string $modelId): self
    {
        return new self($this->client, $modelId, $this->maxTuplesPerWrite);
    }

    public function stores(): StoreClient
    {
        return new StoreClient($this->client);
    }

    public function models(): ModelClient
    {
        return new ModelClient($this->client);
    }

    public function tuples(): TupleClient
    {
        return new TupleClient($this->client, $this->modelId, $this->maxTuplesPerWrite);
    }

    public function query(): QueryClient
    {
        return new QueryClient($this->client, $this->modelId);
    }

    public function assertions(): AssertionClient
    {
        return new AssertionClient($this->client, $this->modelId);
    }

    /**
     * The one question, as a bool.
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
    ): bool {
        return $this->query()->allows($user, $relation, $object, $context, $contextualTuples);
    }

    /**
     * The same question with the server's reasoning attached.
     *
     * @param  array<string, mixed>  $context
     * @param  list<TupleKey>  $contextualTuples
     */
    public function checkResult(
        User|string $user,
        string $relation,
        TypedObject|string $object,
        array $context = [],
        array $contextualTuples = [],
    ): CheckResult {
        return $this->query()->check($user, $relation, $object, $context, $contextualTuples);
    }

    /** Write one tuple. */
    public function grant(User|string $user, string $relation, TypedObject|string $object): void
    {
        $this->tuples()->grant($user, $relation, $object);
    }

    /** Delete one tuple. */
    public function revoke(User|string $user, string $relation, TypedObject|string $object): void
    {
        $this->tuples()->revoke($user, $relation, $object);
    }

    public function store(): ?StoreId
    {
        return $this->client->store();
    }

    public function modelId(): ?string
    {
        return $this->modelId;
    }

    /** For a call this SDK has no method for yet. */
    public function http(): Client
    {
        return $this->client;
    }

    private static function storeId(StoreId|string|null $store): ?StoreId
    {
        return match (true) {
            $store === null => null,
            $store instanceof StoreId => $store,
            default => StoreId::of($store),
        };
    }
}
