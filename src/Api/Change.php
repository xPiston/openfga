<?php

declare(strict_types=1);

namespace Piston\OpenFga\Api;

use DateTimeImmutable;
use Piston\OpenFga\Model\Timestamp;
use Piston\OpenFga\Model\TupleKey;

/**
 * One entry from the change log: a tuple written or deleted, and when.
 *
 * This is how an integration mirrors OpenFGA without polling every object -
 * read the log from a stored continuation token and apply what happened. The
 * operation arrives as `TUPLE_OPERATION_WRITE` or `TUPLE_OPERATION_DELETE`,
 * a protobuf enum name, which is turned into something worth reading.
 */
final class Change
{
    public const WRITE = 'write';

    public const DELETE = 'delete';

    public function __construct(
        public readonly TupleKey $key,
        public readonly string $operation,
        public readonly ?DateTimeImmutable $at = null,
    ) {}

    /** @param array<string, mixed> $json */
    public static function fromArray(array $json): self
    {
        $key = $json['tuple_key'] ?? [];
        $operation = $json['operation'] ?? '';
        $timestamp = $json['timestamp'] ?? null;

        /** @var array<string, mixed> $key */
        $key = is_array($key) ? $key : [];

        return new self(
            TupleKey::fromArray($key),
            self::operation(is_string($operation) ? $operation : ''),
            Timestamp::parse(is_string($timestamp) ? $timestamp : null),
        );
    }

    public function isWrite(): bool
    {
        return $this->operation === self::WRITE;
    }

    public function isDelete(): bool
    {
        return $this->operation === self::DELETE;
    }

    private static function operation(string $wire): string
    {
        return match (strtoupper($wire)) {
            'TUPLE_OPERATION_WRITE', 'WRITE' => self::WRITE,
            'TUPLE_OPERATION_DELETE', 'DELETE' => self::DELETE,
            default => strtolower($wire),
        };
    }
}
