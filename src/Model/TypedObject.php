<?php

declare(strict_types=1);

namespace Piston\OpenFga\Model;

use Piston\OpenFga\Exception\InvalidArgument;
use Stringable;

/**
 * The thing being protected: `document:budget-2026`.
 *
 * Deliberately *not* the same type as User, because OpenFGA does not treat
 * them the same. The rules below were measured against a real server rather
 * than read off a page, and the one that surprises people is the wildcard:
 *
 *     user:*       a perfectly good *user*
 *     document:*   refused - "the 'object' field cannot reference a typed wildcard"
 *
 * An SDK with one Identifier class for both fields cannot express that, and so
 * discovers it in production.
 *
 * What is checked here is the shape. Whether `document` is a type your model
 * declares is the server's business - it has the model and this does not, and
 * a client that guessed would be wrong every time a model is deployed.
 */
final class TypedObject implements Stringable
{
    private function __construct(
        public readonly string $type,
        public readonly string $id,
    ) {}

    public static function of(string $type, string $id): self
    {
        return new self(self::assertType($type), self::assertId($id));
    }

    /** From `type:id`. */
    public static function parse(string $value): self
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw InvalidArgument::because('an object', 'is empty');
        }

        $parts = explode(':', $trimmed);

        if (count($parts) !== 2) {
            throw InvalidArgument::because(
                'an object',
                count($parts) < 2
                    ? 'has to be written "type:id"'
                    : 'has more than one colon, and OpenFGA allows exactly one',
                $trimmed,
            );
        }

        return self::of($parts[0], $parts[1]);
    }

    public static function from(self|string $value): self
    {
        return $value instanceof self ? $value : self::parse($value);
    }

    public function value(): string
    {
        return $this->type.':'.$this->id;
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->id === $other->id;
    }

    public function __toString(): string
    {
        return $this->value();
    }

    private static function assertType(string $type): string
    {
        $trimmed = trim($type);

        if ($trimmed === '') {
            throw InvalidArgument::because('an object type', 'is empty');
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_\-]*$/', $trimmed) !== 1) {
            throw InvalidArgument::because(
                'an object type',
                'may only hold letters, digits, underscores and hyphens, and may not start with a digit',
                $trimmed,
            );
        }

        return $trimmed;
    }

    private static function assertId(string $id): string
    {
        if ($id === '') {
            throw InvalidArgument::because('an object id', 'is empty - "document:" is not an object');
        }

        if (preg_match('/\s/', $id) === 1) {
            throw InvalidArgument::because('an object id', 'contains whitespace, which OpenFGA refuses', $id);
        }

        if ($id === '*') {
            throw InvalidArgument::because(
                'an object id',
                'cannot be the wildcard "*" - OpenFGA allows `user:*` as a *user* but never as an object',
                $id,
            );
        }

        if (str_contains($id, '#')) {
            throw InvalidArgument::because('an object id', 'cannot contain "#"', $id);
        }

        return $id;
    }
}
