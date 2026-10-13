<?php

declare(strict_types=1);

namespace Piston\OpenFga\Model;

use Piston\OpenFga\Exception\InvalidArgument;

/**
 * Relation names, validated in one place.
 *
 * Not a value object: a relation is a plain string everywhere in the API and
 * wrapping it would buy nothing. What is worth having in one place is the
 * check, because a relation that does not exist on a type produces a 400 that
 * reads the same as half a dozen other mistakes.
 */
final class Relation
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function assertName(string $relation): string
    {
        $trimmed = trim($relation);

        if ($trimmed === '') {
            throw InvalidArgument::because('a relation name', 'is empty');
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_\-]*$/', $trimmed) !== 1) {
            throw InvalidArgument::because(
                'a relation name',
                'may only hold letters, digits, underscores and hyphens, and may not start with a digit',
                $trimmed,
            );
        }

        return $trimmed;
    }
}
