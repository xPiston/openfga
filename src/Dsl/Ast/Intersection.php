<?php

declare(strict_types=1);

namespace Piston\OpenFga\Dsl\Ast;

use Piston\OpenFga\Exception\InvalidArgument;

/** `a and b and c`. */
final class Intersection extends Userset
{
    /** @var list<Userset> */
    public readonly array $children;

    /** @param list<Userset> $children */
    public function __construct(array $children)
    {
        if (count($children) < 2) {
            throw InvalidArgument::because('an intersection', 'needs at least two children', (string) count($children));
        }

        $this->children = $children;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['intersection' => ['child' => array_map(static fn (Userset $c): array => $c->toArray(), $this->children)]];
    }

    public function toDsl(): string
    {
        return implode(' and ', array_map(
            static fn (Userset $c): string => $c instanceof Intersection ? $c->toDsl() : Parenthesise::around($c),
            $this->children,
        ));
    }

    public function assignsDirectly(): bool
    {
        foreach ($this->children as $child) {
            if ($child->assignsDirectly()) {
                return true;
            }
        }

        return false;
    }
}
