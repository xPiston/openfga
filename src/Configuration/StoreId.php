<?php

declare(strict_types=1);

namespace Piston\OpenFga\Configuration;

use Piston\OpenFga\Exception\InvalidArgument;
use Stringable;

/**
 * A store id, which OpenFGA generates as a ULID.
 *
 * Validated loosely but not blindly: 26 characters of Crockford base32, which
 * is what every id the server has ever handed out looks like. The point is not
 * to re-implement ULID - it is to fail on the two mistakes people actually
 * make, which are passing the store *name* and passing an authorization model
 * id that happens to be in the next variable.
 *
 * The second of those cannot be caught here, since both are ULIDs. It is
 * caught by the method signatures instead: nothing in this library takes two
 * bare strings where one is a store and the other a model.
 */
final class StoreId implements Stringable
{
    private function __construct(private readonly string $value) {}

    public static function of(string $value): self
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw InvalidArgument::because('the store id', 'is empty');
        }

        // Crockford base32 excludes I, L, O and U so they cannot be confused
        // with 1, 1, 0 and V. A 26-character ULID is what OpenFGA issues.
        if (preg_match('/^[0-7][0-9ABCDEFGHJKMNPQRSTVWXYZ]{25}$/i', $trimmed) !== 1) {
            throw InvalidArgument::because(
                'the store id',
                'is not a ULID - OpenFGA issues 26-character ids like 01M4T8JQ0KTTDRR0CGQZ3R2RPE; '
                .'if this is a store *name*, look its id up with stores()->findByName()',
                $trimmed,
            );
        }

        return new self($trimmed);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
