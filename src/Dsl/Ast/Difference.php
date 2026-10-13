<?php

declare(strict_types=1);

namespace Piston\OpenFga\Dsl\Ast;

/**
 * `a but not b`.
 *
 * Exactly one subtrahend, because the grammar allows exactly one: `a but not b
 * but not c` is not valid DSL, and has to be written `a but not (b or c)`.
 * Encoding that here means the writer cannot emit something the parser would
 * refuse.
 */
final class Difference extends Userset
{
    public function __construct(
        public readonly Userset $base,
        public readonly Userset $subtract,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'difference' => [
                'base' => $this->base->toArray(),
                'subtract' => $this->subtract->toArray(),
            ],
        ];
    }

    public function toDsl(): string
    {
        return Parenthesise::around($this->base).' but not '.Parenthesise::around($this->subtract);
    }

    public function assignsDirectly(): bool
    {
        return $this->base->assignsDirectly() || $this->subtract->assignsDirectly();
    }
}
