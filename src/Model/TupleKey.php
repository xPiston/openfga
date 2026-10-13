<?php

declare(strict_types=1);

namespace Piston\OpenFga\Model;

use Stringable;

/**
 * `user:anne` is `viewer` of `document:budget` - the unit of everything here.
 *
 * It is the thing you write, the thing you check, and the thing you delete,
 * which is why one class serves all three rather than three near-identical
 * ones. The conventional way to say it out loud is OpenFGA's own, and it is
 * what `__toString` produces:
 *
 *     document:budget#viewer@user:anne
 *
 * That ordering - object, relation, user - is the one in the server's error
 * messages, so a log line from this library and a log line from OpenFGA can be
 * compared without translating.
 */
final class TupleKey implements Stringable
{
    public function __construct(
        public readonly User $user,
        public readonly string $relation,
        public readonly TypedObject $object,
        public readonly ?RelationshipCondition $condition = null,
    ) {}

    /** The short way, from three strings. */
    public static function of(
        User|string $user,
        string $relation,
        TypedObject|string $object,
        ?RelationshipCondition $condition = null,
    ): self {
        return new self(
            User::from($user),
            Relation::assertName($relation),
            TypedObject::from($object),
            $condition,
        );
    }

    /** @param array<string, mixed> $json */
    public static function fromArray(array $json): self
    {
        $user = $json['user'] ?? '';
        $relation = $json['relation'] ?? '';
        $object = $json['object'] ?? '';
        $condition = $json['condition'] ?? null;

        /** @var array<string, mixed> $condition */
        $condition = is_array($condition) ? $condition : [];

        return new self(
            User::parse(is_string($user) ? $user : ''),
            Relation::assertName(is_string($relation) ? $relation : ''),
            TypedObject::parse(is_string($object) ? $object : ''),
            $condition === [] ? null : RelationshipCondition::fromArray($condition),
        );
    }

    /** @param array<string, mixed> $context */
    public function withCondition(string $name, array $context = []): self
    {
        return new self($this->user, $this->relation, $this->object, new RelationshipCondition($name, $context));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $json = [
            'user' => $this->user->value(),
            'relation' => $this->relation,
            'object' => $this->object->value(),
        ];

        if ($this->condition !== null) {
            $json['condition'] = $this->condition->toArray();
        }

        return $json;
    }

    public function equals(self $other): bool
    {
        return $this->user->equals($other->user)
            && $this->relation === $other->relation
            && $this->object->equals($other->object);
    }

    /** OpenFGA's own notation, so logs line up with the server's. */
    public function __toString(): string
    {
        return sprintf('%s#%s@%s', $this->object->value(), $this->relation, $this->user->value());
    }
}
