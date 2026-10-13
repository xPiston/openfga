<?php

declare(strict_types=1);

namespace Piston\OpenFga\Dsl\Ast;

/**
 * One `define`, with both halves of what OpenFGA splits in two.
 *
 * The wire format puts the relation's *shape* under `relations` and the types
 * it accepts under `metadata.relations.<name>.directly_related_user_types`,
 * which means reading a model from JSON involves holding two trees open at
 * once. This class is the join, so nothing above it has to do that.
 *
 * A relation that assigns directly has restrictions; one that is purely
 * computed has an empty list - and an empty list is still written to the
 * metadata, because that is what OpenFGA emits.
 */
final class Relation
{
    /** @var list<TypeRestriction> */
    public readonly array $restrictions;

    /** @param list<TypeRestriction> $restrictions */
    public function __construct(
        public readonly string $name,
        public readonly Userset $userset,
        array $restrictions = [],
    ) {
        $this->restrictions = $restrictions;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->userset->toArray();
    }

    /** @return list<array<string, mixed>> */
    public function restrictionsToArray(): array
    {
        return array_map(static fn (TypeRestriction $r): array => $r->toArray(), $this->restrictions);
    }

    public function toDsl(): string
    {
        return 'define '.$this->name.': '.$this->bodyToDsl();
    }

    /**
     * The right-hand side, with the direct assignment written back in.
     *
     * DirectUserset renders as `[]` on its own because it does not carry its
     * own types - they are here. So the brackets are filled in at this level,
     * and only here.
     */
    private function bodyToDsl(): string
    {
        $types = '['.implode(', ', array_map(
            static fn (TypeRestriction $r): string => $r->toDsl(),
            $this->restrictions,
        )).']';

        return $this->substituteDirect($this->userset, $types);
    }

    private function substituteDirect(Userset $userset, string $types): string
    {
        return match (true) {
            $userset instanceof DirectUserset => $types,
            $userset instanceof Union => implode(' or ', array_map(
                fn (Userset $c): string => $this->wrap($c, $types, Union::class),
                $userset->children,
            )),
            $userset instanceof Intersection => implode(' and ', array_map(
                fn (Userset $c): string => $this->wrap($c, $types, Intersection::class),
                $userset->children,
            )),
            $userset instanceof Difference => $this->wrap($userset->base, $types, Difference::class)
                .' but not '
                .$this->wrap($userset->subtract, $types, Difference::class),
            default => $userset->toDsl(),
        };
    }

    /** @param class-string $parent */
    private function wrap(Userset $child, string $types, string $parent): string
    {
        $rendered = $this->substituteDirect($child, $types);

        $needsBrackets = ($child instanceof Union || $child instanceof Intersection || $child instanceof Difference)
            && ! ($child instanceof $parent && ! $child instanceof Difference);

        return $needsBrackets ? '('.$rendered.')' : $rendered;
    }
}
