<?php

declare(strict_types=1);

namespace Piston\OpenFga\Http;

/**
 * One call, before it becomes PSR-7.
 *
 * `inStore` is the only unusual field: OpenFGA splits its API between paths
 * above a store (`/stores`, to create and list them) and paths inside one
 * (`/stores/{id}/check` and everything else). The caller knows which it
 * wants; the transport should not have to guess, and an SDK that guessed
 * would work until the first `createStore`.
 */
final class Request
{
    /**
     * @param  array<string, scalar|null>  $query
     * @param  array<string, mixed>|null  $body
     */
    private function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly bool $inStore,
        public readonly array $query = [],
        public readonly ?array $body = null,
    ) {}

    /** @param array<string, scalar|null> $query */
    public static function get(string $path, array $query = []): self
    {
        return new self('GET', $path, true, $query);
    }

    /** @param array<string, mixed> $body */
    public static function post(string $path, array $body = []): self
    {
        return new self('POST', $path, true, [], $body);
    }

    /** @param array<string, mixed> $body */
    public static function put(string $path, array $body = []): self
    {
        return new self('PUT', $path, true, [], $body);
    }

    public static function delete(string $path): self
    {
        return new self('DELETE', $path, true);
    }

    /** The same call against a path above any store. */
    public function atRoot(): self
    {
        return new self($this->method, $this->path, false, $this->query, $this->body);
    }
}
