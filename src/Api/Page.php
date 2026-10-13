<?php

declare(strict_types=1);

namespace Piston\OpenFga\Api;

/**
 * One page of results, and how to ask for the next.
 *
 * @template T
 */
final class Page
{
    /** @var list<T> */
    public readonly array $items;

    /** @param list<T> $items */
    public function __construct(array $items, public readonly ?string $continuationToken = null)
    {
        $this->items = $items;
    }

    /**
     * Is there more?
     *
     * OpenFGA signals "no more" with an **empty string**, not a missing key -
     * so `isset($body['continuation_token'])` is true on the last page, and
     * code that trusts it loops forever. That empty string is turned into null
     * on the way in, and this is the method to ask.
     */
    public function hasMore(): bool
    {
        return $this->continuationToken !== null;
    }

    public function count(): int
    {
        return count($this->items);
    }

    /** @return list<T> */
    public function all(): array
    {
        return $this->items;
    }

    public static function token(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
