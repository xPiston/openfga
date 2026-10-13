<?php

declare(strict_types=1);

namespace Piston\OpenFga\Api;

use DateTimeImmutable;
use Piston\OpenFga\Configuration\StoreId;
use Piston\OpenFga\Exception\InvalidArgument;
use Piston\OpenFga\Model\Timestamp;

/** A store: a namespace for one model and its tuples. */
final class Store
{
    public function __construct(
        public readonly StoreId $id,
        public readonly string $name,
        public readonly ?DateTimeImmutable $createdAt = null,
        public readonly ?DateTimeImmutable $updatedAt = null,
        public readonly ?DateTimeImmutable $deletedAt = null,
    ) {}

    /** @param array<string, mixed> $json */
    public static function fromArray(array $json): self
    {
        $id = $json['id'] ?? null;
        $name = $json['name'] ?? '';

        if (! is_string($id) || $id === '') {
            throw InvalidArgument::because('a store', 'came back without an id');
        }

        return new self(
            StoreId::of($id),
            is_string($name) ? $name : '',
            Timestamp::parse(is_string($json['created_at'] ?? null) ? $json['created_at'] : null),
            Timestamp::parse(is_string($json['updated_at'] ?? null) ? $json['updated_at'] : null),
            Timestamp::parse(is_string($json['deleted_at'] ?? null) ? $json['deleted_at'] : null),
        );
    }
}
