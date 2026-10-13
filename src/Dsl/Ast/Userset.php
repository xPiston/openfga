<?php

declare(strict_types=1);

namespace Piston\OpenFga\Dsl\Ast;

use Piston\OpenFga\Exception\InvalidArgument;

/**
 * The right-hand side of a `define`.
 *
 * Six shapes, and the JSON names them inconsistently - `this`, then camelCase
 * `computedUserset` and `tupleToUserset`, then lowercase `union`,
 * `intersection` and `difference`. That inconsistency is OpenFGA's wire
 * format, not a choice here, and it lives in these classes so that nothing
 * above them has to remember it.
 */
abstract class Userset
{
    /** @return array<string, mixed> */
    abstract public function toArray(): array;

    /** The DSL text for this userset, without the `define name: ` prefix. */
    abstract public function toDsl(): string;

    /**
     * Does this userset, or anything inside it, assign directly?
     *
     * Asked by the writer: a relation with a `[...]` somewhere in it has to
     * put that part first, because the DSL only accepts a direct assignment in
     * the leading position.
     */
    public function assignsDirectly(): bool
    {
        return false;
    }

    /**
     * Rebuild a userset from OpenFGA's JSON.
     *
     * @param  array<string, mixed>  $json
     */
    public static function fromArray(array $json): self
    {
        if (array_key_exists('this', $json)) {
            return new DirectUserset;
        }

        if (isset($json['computedUserset']) && is_array($json['computedUserset'])) {
            $relation = $json['computedUserset']['relation'] ?? null;

            if (! is_string($relation) || $relation === '') {
                throw InvalidArgument::because('a computedUserset', 'has no relation');
            }

            return new ComputedUserset($relation);
        }

        if (isset($json['tupleToUserset']) && is_array($json['tupleToUserset'])) {
            $ttu = $json['tupleToUserset'];
            $tupleset = is_array($ttu['tupleset'] ?? null) ? ($ttu['tupleset']['relation'] ?? null) : null;
            $computed = is_array($ttu['computedUserset'] ?? null) ? ($ttu['computedUserset']['relation'] ?? null) : null;

            if (! is_string($tupleset) || $tupleset === '' || ! is_string($computed) || $computed === '') {
                throw InvalidArgument::because('a tupleToUserset', 'is missing its tupleset or computedUserset relation');
            }

            return new TupleToUserset($computed, $tupleset);
        }

        foreach (['union' => Union::class, 'intersection' => Intersection::class] as $key => $class) {
            if (isset($json[$key]) && is_array($json[$key])) {
                $children = $json[$key]['child'] ?? null;

                if (! is_array($children) || $children === []) {
                    throw InvalidArgument::because('a '.$key, 'has no children');
                }

                $parsed = [];

                foreach ($children as $child) {
                    if (! is_array($child)) {
                        throw InvalidArgument::because('a '.$key.' child', 'is not an object');
                    }

                    /** @var array<string, mixed> $child */
                    $parsed[] = self::fromArray($child);
                }

                /** @var Union|Intersection $node */
                $node = new $class($parsed);

                return $node;
            }
        }

        if (isset($json['difference']) && is_array($json['difference'])) {
            $base = $json['difference']['base'] ?? null;
            $subtract = $json['difference']['subtract'] ?? null;

            if (! is_array($base) || ! is_array($subtract)) {
                throw InvalidArgument::because('a difference', 'is missing its base or subtract');
            }

            /** @var array<string, mixed> $base */
            /** @var array<string, mixed> $subtract */
            return new Difference(self::fromArray($base), self::fromArray($subtract));
        }

        throw InvalidArgument::because(
            'a relation definition',
            'is none of this, computedUserset, tupleToUserset, union, intersection or difference',
            implode(', ', array_keys($json)),
        );
    }
}
