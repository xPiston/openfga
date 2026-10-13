<?php

declare(strict_types=1);

namespace Piston\OpenFga\Dsl\Ast;

use Piston\OpenFga\Exception\InvalidArgument;

/** `a or b or c`. */
final class Union extends Userset
{
    /** @var list<Userset> */
    public readonly array $children;

    /** @param list<Userset> $children */
    public function __construct(array $children)
    {
        if (count($children) < 2) {
            throw InvalidArgument::because('a union', 'needs at least two children', (string) count($children));
        }

        $this->children = $children;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['union' => ['child' => array_map(static fn (Userset $c): array => $c->toArray(), $this->children)]];
    }

    public function toDsl(): string
    {
        return implode(' or ', array_map(
            static fn (Userset $c): string => $c instanceof Union ? $c->toDsl() : Parenthesise::around($c),
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
