<?php

declare(strict_types=1);

namespace Piston\OpenFga\Api;

use Generator;
use Piston\OpenFga\Configuration\StoreId;
use Piston\OpenFga\Exception\InvalidArgument;
use Piston\OpenFga\Http\Client;
use Piston\OpenFga\Http\Request;

/**
 * Stores: create, find, delete.
 *
 * A store is a namespace - one model lineage and its tuples. Most
 * applications have exactly one and create it once, by hand or in a
 * deployment script, which is why these are the only methods here and why
 * none of them is on the hot path.
 *
 * Note that store names are **not unique**: OpenFGA will happily hold three
 * stores called "production". `findByName` therefore returns the first match
 * and says so, rather than pretending the name is a key.
 */
final class StoreClient
{
    public function __construct(private readonly Client $client) {}

    /**
     * Create a store.
     *
     * The name is checked against the rule the server enforces - three to
     * sixty-four characters of letters, digits, whitespace and
     * `. - / ^ _ & @` - because the 400 it answers with is a protobuf
     * validation message quoting a regex, and a two-character name is an
     * easy mistake to make from a test fixture.
     */
    public function create(string $name): Store
    {
        $name = trim($name);

        if (preg_match('#^[a-zA-Z0-9\s./\-^_&@]{3,64}$#', $name) !== 1) {
            throw InvalidArgument::because(
                'a store name',
                'has to be 3 to 64 characters of letters, digits, whitespace or . - / ^ _ & @',
                $name,
            );
        }

        $reply = $this->client->send(Request::post('stores', ['name' => $name])->atRoot());

        return Store::fromArray($reply->body);
    }

    public function get(StoreId|string $id): Store
    {
        $store = $id instanceof StoreId ? $id : StoreId::of($id);

        $reply = $this->client->send(Request::get('stores/'.$store->value())->atRoot());

        return Store::fromArray($reply->body);
    }

    /** @return Page<Store> */
    public function list(?int $pageSize = null, ?string $continuationToken = null): Page
    {
        $query = [];

        if ($pageSize !== null) {
            $query['page_size'] = $pageSize;
        }

        if ($continuationToken !== null && $continuationToken !== '') {
            $query['continuation_token'] = $continuationToken;
        }

        $reply = $this->client->send(Request::get('stores', $query)->atRoot());

        $stores = [];

        foreach ($reply->array('stores') as $store) {
            if (! is_array($store)) {
                continue;
            }

            /** @var array<string, mixed> $store */
            $stores[] = Store::fromArray($store);
        }

        return new Page($stores, Page::token($reply->get('continuation_token')));
    }

    /** @return Generator<int, Store> */
    public function all(?int $pageSize = null): Generator
    {
        $token = null;

        do {
            $page = $this->list($pageSize, $token);

            foreach ($page->items as $store) {
                yield $store;
            }

            $token = $page->continuationToken;
        } while ($token !== null);
    }

    /**
     * The first store with this name, or null.
     *
     * Walks the pages, because the API has no name filter. Fine for a
     * deployment script; not something to call per request.
     */
    public function findByName(string $name): ?Store
    {
        $wanted = trim($name);

        if ($wanted === '') {
            throw InvalidArgument::because('a store name', 'is empty');
        }

        foreach ($this->all(100) as $store) {
            if ($store->name === $wanted) {
                return $store;
            }
        }

        return null;
    }

    /**
     * Delete a store, and everything in it.
     *
     * Irreversible, and it takes the tuples and every model version with it.
     */
    public function delete(StoreId|string $id): void
    {
        $store = $id instanceof StoreId ? $id : StoreId::of($id);

        $this->client->send(Request::delete('stores/'.$store->value())->atRoot());
    }
}
