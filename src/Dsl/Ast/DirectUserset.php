<?php

declare(strict_types=1);

namespace Piston\OpenFga\Dsl\Ast;

use stdClass;

/**
 * `[user, group#member]` - the relation can be assigned by writing a tuple.
 *
 * The JSON is `{"this": {}}` and carries no types at all: which types may be
 * assigned lives in the type's *metadata*, far away from the relation it
 * constrains. That separation is the single most surprising thing about this
 * format, and it is why Relation holds both.
 */
final class DirectUserset extends Userset
{
    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['this' => new stdClass];
    }

    public function toDsl(): string
    {
        // The types are written by Relation, which has the metadata.
        return '[]';
    }

    public function assignsDirectly(): bool
    {
        return true;
    }
}
