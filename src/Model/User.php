<?php

declare(strict_types=1);

namespace Piston\OpenFga\Model;

use Piston\OpenFga\Exception\InvalidArgument;
use Stringable;

/**
 * Whoever is being granted something - and in OpenFGA that is three things.
 *
 *     user:anne          a subject
 *     user:*             every subject of that type - the public wildcard
 *     group:eng#member   a *userset*: whoever is `member` of group:eng
 *
 * The third is what makes the model worth using: granting `group:eng#member`
 * means the grant follows the group's membership without another tuple. The
 * second is what makes it worth reading twice - `[user:*]` opens a relation to
 * everybody, including the unauthenticated, and it looks like a glob.
 *
 * `user:*#member` is not a thing, and the server says so. It is refused here.
 */
final class User implements Stringable
{
    private function __construct(
        public readonly string $type,
        public readonly ?string $id,
        public readonly ?string $relation,
    ) {}

    public static function of(string $type, string $id): self
    {
        return new self(self::assertType($type), self::assertId($id), null);
    }

    /** `group:eng#member` - everybody holding that relation on that object. */
    public static function userset(string $type, string $id, string $relation): self
    {
        if (trim($relation) === '') {
            throw InvalidArgument::because('a userset', 'has nothing after the "#"', $type.':'.$id.'#');
        }

        return new self(self::assertType($type), self::assertId($id), trim($relation));
    }

    /** `user:*` - everybody of that type. Read it twice. */
    public static function everyone(string $type): self
    {
        return new self(self::assertType($type), null, null);
    }

    public static function parse(string $value): self
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw InvalidArgument::because('a user', 'is empty');
        }

        if (preg_match('/\s/', $trimmed) === 1) {
            throw InvalidArgument::because('a user', 'contains whitespace, which OpenFGA refuses', $trimmed);
        }

        $relation = null;
        $subject = $trimmed;

        if (str_contains($trimmed, '#')) {
            $halves = explode('#', $trimmed);

            if (count($halves) !== 2 || $halves[1] === '') {
                throw InvalidArgument::because('a user', 'has a malformed userset after the "#"', $trimmed);
            }

            $subject = $halves[0];
            $relation = $halves[1];
        }

        $parts = explode(':', $subject);

        if (count($parts) !== 2) {
            throw InvalidArgument::because(
                'a user',
                'has to be written "type:id", "type:*" or "type:id#relation"',
                $trimmed,
            );
        }

        [$type, $id] = $parts;

        if ($id === '*') {
            if ($relation !== null) {
                throw InvalidArgument::because(
                    'a user',
                    'cannot be a wildcard userset - "type:*#relation" is not valid',
                    $trimmed,
                );
            }

            return self::everyone($type);
        }

        return $relation === null ? self::of($type, $id) : self::userset($type, $id, $relation);
    }

    public static function from(self|string $value): self
    {
        return $value instanceof self ? $value : self::parse($value);
    }

    public function isEveryone(): bool
    {
        return $this->id === null;
    }

    public function isUserset(): bool
    {
        return $this->relation !== null;
    }

    public function value(): string
    {
        if ($this->id === null) {
            return $this->type.':*';
        }

        return $this->type.':'.$this->id.($this->relation === null ? '' : '#'.$this->relation);
    }

    public function equals(self $other): bool
    {
        return $this->value() === $other->value();
    }

    public function __toString(): string
    {
        return $this->value();
    }

    private static function assertType(string $type): string
    {
        $trimmed = trim($type);

        if ($trimmed === '') {
            throw InvalidArgument::because('a user type', 'is empty');
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_\-]*$/', $trimmed) !== 1) {
            throw InvalidArgument::because(
                'a user type',
                'may only hold letters, digits, underscores and hyphens, and may not start with a digit',
                $trimmed,
            );
        }

        return $trimmed;
    }

    private static function assertId(string $id): string
    {
        if ($id === '') {
            throw InvalidArgument::because('a user id', 'is empty - "user:" is not a user');
        }

        if (preg_match('/\s/', $id) === 1) {
            throw InvalidArgument::because('a user id', 'contains whitespace, which OpenFGA refuses', $id);
        }

        return $id;
    }
}
