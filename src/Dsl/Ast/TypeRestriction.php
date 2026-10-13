<?php

declare(strict_types=1);

namespace Piston\OpenFga\Dsl\Ast;

use Piston\OpenFga\Exception\InvalidArgument;
use stdClass;

/**
 * One entry inside a `[...]`: who may be assigned this relation directly.
 *
 * Four forms, and they are genuinely different things rather than variations:
 *
 *   user            a user of that type
 *   user:*          *every* user of that type - the public wildcard
 *   group#member    a userset: whoever is `member` of some group
 *   user with cond  any of the above, gated by a condition
 *
 * The wildcard is the one worth pausing on. `[user:*]` makes a relation
 * publicly assignable, and a tuple written against it grants everyone. It
 * reads like a glob and behaves like a decision.
 */
final class TypeRestriction
{
    private function __construct(
        public readonly string $type,
        public readonly bool $wildcard,
        public readonly ?string $relation,
        public readonly ?string $condition,
    ) {}

    public static function of(string $type, ?string $condition = null): self
    {
        return new self(self::assertType($type), false, null, self::nullIfEmpty($condition));
    }

    /** `user:*` - every user of the type. */
    public static function wildcard(string $type, ?string $condition = null): self
    {
        return new self(self::assertType($type), true, null, self::nullIfEmpty($condition));
    }

    /** `group#member` - whoever holds that relation on some object of the type. */
    public static function userset(string $type, string $relation, ?string $condition = null): self
    {
        if (trim($relation) === '') {
            throw InvalidArgument::because('a userset restriction', 'has no relation after the #', $type.'#');
        }

        return new self(self::assertType($type), false, trim($relation), self::nullIfEmpty($condition));
    }

    /** @param array<string, mixed> $json */
    public static function fromArray(array $json): self
    {
        $type = $json['type'] ?? null;

        if (! is_string($type) || $type === '') {
            throw InvalidArgument::because('a type restriction', 'has no type');
        }

        $condition = $json['condition'] ?? null;
        $condition = is_string($condition) && $condition !== '' ? $condition : null;

        if (array_key_exists('wildcard', $json)) {
            return self::wildcard($type, $condition);
        }

        $relation = $json['relation'] ?? null;

        if (is_string($relation) && $relation !== '') {
            return self::userset($type, $relation, $condition);
        }

        return self::of($type, $condition);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $json = ['type' => $this->type];

        if ($this->wildcard) {
            $json['wildcard'] = new stdClass;
        }

        if ($this->relation !== null) {
            $json['relation'] = $this->relation;
        }

        if ($this->condition !== null) {
            $json['condition'] = $this->condition;
        }

        return $json;
    }

    public function toDsl(): string
    {
        $dsl = $this->type;

        if ($this->wildcard) {
            $dsl .= ':*';
        } elseif ($this->relation !== null) {
            $dsl .= '#'.$this->relation;
        }

        if ($this->condition !== null) {
            $dsl .= ' with '.$this->condition;
        }

        return $dsl;
    }

    private static function assertType(string $type): string
    {
        $trimmed = trim($type);

        if ($trimmed === '') {
            throw InvalidArgument::because('a type restriction', 'has no type name');
        }

        return $trimmed;
    }

    private static function nullIfEmpty(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
