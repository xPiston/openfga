<?php

declare(strict_types=1);

namespace Piston\OpenFga\Dsl\Ast;

use stdClass;

/**
 * A `type` block.
 *
 * Note the two empty shapes, which are not the same shape: a type with no
 * relations emits `"relations": {}` *and* `"metadata": null`, while a type
 * with relations emits both populated. A transformer that emitted
 * `"metadata": {"relations": {}}` for an empty type would be rejected by
 * nothing and still differ from every model OpenFGA has ever produced.
 */
final class TypeDefinition
{
    /** @var array<string, Relation> */
    public readonly array $relations;

    /** @param array<string, Relation> $relations */
    public function __construct(public readonly string $name, array $relations = [])
    {
        $this->relations = $relations;
    }

    public function relation(string $name): ?Relation
    {
        return $this->relations[$name] ?? null;
    }

    /** @return list<string> */
    public function relationNames(): array
    {
        return array_keys($this->relations);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        if ($this->relations === []) {
            return [
                'type' => $this->name,
                'relations' => new stdClass,
                'metadata' => null,
            ];
        }

        $relations = [];
        $metadata = [];

        foreach ($this->relations as $name => $relation) {
            $relations[$name] = $relation->toArray();
            $metadata[$name] = ['directly_related_user_types' => $relation->restrictionsToArray()];
        }

        return [
            'type' => $this->name,
            'relations' => $relations,
            'metadata' => ['relations' => $metadata],
        ];
    }

    public function toDsl(): string
    {
        $lines = ['type '.$this->name];

        if ($this->relations !== []) {
            $lines[] = '  relations';

            foreach ($this->relations as $relation) {
                $lines[] = '    '.$relation->toDsl();
            }
        }

        return implode("\n", $lines);
    }
}
