<?php

declare(strict_types=1);

namespace Piston\OpenFga\Dsl\Ast;

/**
 * `viewer from parent` - the relation, on whatever the tupleset points at.
 *
 * This is the one that makes OpenFGA worth using: it is how a permission
 * reaches down a hierarchy without a tuple per object. Note the argument order
 * - the DSL reads "computed from tupleset", so that is the order here, even
 * though the JSON writes `tupleset` first.
 */
final class TupleToUserset extends Userset
{
    public function __construct(
        public readonly string $computedRelation,
        public readonly string $tuplesetRelation,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tupleToUserset' => [
                'tupleset' => ['relation' => $this->tuplesetRelation],
                'computedUserset' => ['relation' => $this->computedRelation],
            ],
        ];
    }

    public function toDsl(): string
    {
        return $this->computedRelation.' from '.$this->tuplesetRelation;
    }
}
