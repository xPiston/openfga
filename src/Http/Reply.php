<?php

declare(strict_types=1);

namespace Piston\OpenFga\Http;

/** A successful response, decoded. */
final class Reply
{
    /** @param array<string, mixed> $body */
    public function __construct(
        public readonly int $status,
        public readonly array $body,
    ) {}

    public function get(string $key): mixed
    {
        return $this->body[$key] ?? null;
    }

    public function string(string $key): ?string
    {
        $value = $this->body[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array<array-key, mixed> */
    public function array(string $key): array
    {
        $value = $this->body[$key] ?? null;

        return is_array($value) ? $value : [];
    }
}
