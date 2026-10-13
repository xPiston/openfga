<?php

declare(strict_types=1);

namespace Piston\OpenFga\Model;

use DateTimeImmutable;

/**
 * A tuple as it is stored: the key, plus when it was written.
 *
 * Reads return these; writes take bare keys. The distinction is not
 * bureaucracy - the timestamp is the server's and cannot be set, and a single
 * class for both would invite code that tries.
 */
final class Tuple
{
    public function __construct(
        public readonly TupleKey $key,
        public readonly ?DateTimeImmutable $writtenAt = null,
    ) {}

    /** @param array<string, mixed> $json */
    public static function fromArray(array $json): self
    {
        $key = $json['key'] ?? null;
        $timestamp = $json['timestamp'] ?? null;

        /** @var array<string, mixed> $key */
        $key = is_array($key) ? $key : [];

        return new self(TupleKey::fromArray($key), Timestamp::parse(is_string($timestamp) ? $timestamp : null));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $json = ['key' => $this->key->toArray()];

        if ($this->writtenAt !== null) {
            $json['timestamp'] = $this->writtenAt->format('Y-m-d\TH:i:s.v\Z');
        }

        return $json;
    }

    public function __toString(): string
    {
        return (string) $this->key;
    }
}
