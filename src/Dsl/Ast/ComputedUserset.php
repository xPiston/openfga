<?php

declare(strict_types=1);

namespace Piston\OpenFga\Dsl\Ast;

/** `owner` - whoever has that relation on this same object. */
final class ComputedUserset extends Userset
{
    public function __construct(public readonly string $relation) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['computedUserset' => ['relation' => $this->relation]];
    }

    public function toDsl(): string
    {
        return $this->relation;
    }
}
