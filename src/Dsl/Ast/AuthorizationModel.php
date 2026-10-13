<?php

declare(strict_types=1);

namespace Piston\OpenFga\Dsl\Ast;

use Piston\OpenFga\Exception\InvalidArgument;

/**
 * A whole authorization model: the schema version, the types, the conditions.
 *
 * This is the object the DSL parses into and the one that serialises to what
 * `POST /authorization-models` accepts. It is also what comes back from a
 * read, with an id attached - which is why `id` is nullable rather than
 * required: a model you just wrote has one and a model you just parsed does
 * not, and conflating the two is how an SDK ends up sending an id back to a
 * server that rejects it.
 *
 * Two empty-shape rules are encoded here because OpenFGA's own output has
 * them, and because nothing would reject the wrong answer:
 *
 * - a model with no types omits `type_definitions` altogether;
 * - a model with no conditions omits `conditions` altogether.
 */
final class AuthorizationModel
{
    public const SCHEMA_1_1 = '1.1';

    /** @var array<string, TypeDefinition> */
    public readonly array $types;

    /** @var array<string, ConditionDefinition> */
    public readonly array $conditions;

    /**
     * @param  array<string, TypeDefinition>  $types
     * @param  array<string, ConditionDefinition>  $conditions
     */
    public function __construct(
        public readonly string $schemaVersion = self::SCHEMA_1_1,
        array $types = [],
        array $conditions = [],
        public readonly ?string $id = null,
    ) {
        if (preg_match('/^\d+\.\d+$/', $schemaVersion) !== 1) {
            throw InvalidArgument::because(
                'the schema version',
                'has to look like 1.1',
                $schemaVersion,
            );
        }

        $this->types = $types;
        $this->conditions = $conditions;
    }

    public function type(string $name): ?TypeDefinition
    {
        return $this->types[$name] ?? null;
    }

    public function condition(string $name): ?ConditionDefinition
    {
        return $this->conditions[$name] ?? null;
    }

    /** @return list<string> */
    public function typeNames(): array
    {
        return array_keys($this->types);
    }

    /**
     * Everything a `define` could legally point at, for a given type.
     *
     * @return list<string>
     */
    public function relationNames(string $type): array
    {
        return $this->types[$type]?->relationNames() ?? [];
    }

    /** @param array<string, mixed> $json */
    public static function fromArray(array $json): self
    {
        $schema = $json['schema_version'] ?? self::SCHEMA_1_1;

        if (! is_string($schema) || $schema === '') {
            throw InvalidArgument::because('the model', 'has no schema_version');
        }

        $types = [];
        $rawTypes = $json['type_definitions'] ?? [];

        if (is_array($rawTypes)) {
            foreach ($rawTypes as $rawType) {
                if (! is_array($rawType)) {
                    continue;
                }

                /** @var array<string, mixed> $rawType */
                $name = $rawType['type'] ?? null;

                if (! is_string($name) || $name === '') {
                    throw InvalidArgument::because('a type definition', 'has no type name');
                }

                $types[$name] = new TypeDefinition($name, self::relationsFromArray($rawType));
            }
        }

        $conditions = [];
        $rawConditions = $json['conditions'] ?? [];

        if (is_array($rawConditions)) {
            foreach ($rawConditions as $name => $rawCondition) {
                if (! is_string($name) || ! is_array($rawCondition)) {
                    continue;
                }

                /** @var array<string, mixed> $rawCondition */
                $conditions[$name] = ConditionDefinition::fromArray($name, $rawCondition);
            }
        }

        $id = $json['id'] ?? null;

        return new self($schema, $types, $conditions, is_string($id) && $id !== '' ? $id : null);
    }

    /**
     * The body of a write request.
     *
     * The id is deliberately left out even when this model has one: OpenFGA
     * assigns ids and refuses to be told them.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $json = ['schema_version' => $this->schemaVersion];

        if ($this->types !== []) {
            $json['type_definitions'] = array_values(array_map(
                static fn (TypeDefinition $t): array => $t->toArray(),
                $this->types,
            ));
        }

        if ($this->conditions !== []) {
            $conditions = [];

            foreach ($this->conditions as $name => $condition) {
                $conditions[$name] = $condition->toArray();
            }

            $json['conditions'] = $conditions;
        }

        return $json;
    }

    public function toJson(int $flags = 0): string
    {
        return (string) json_encode($this->toArray(), $flags | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function toDsl(): string
    {
        $blocks = [sprintf("model\n  schema %s", $this->schemaVersion)];

        foreach ($this->types as $type) {
            $blocks[] = $type->toDsl();
        }

        foreach ($this->conditions as $condition) {
            $blocks[] = $condition->toDsl();
        }

        return implode("\n\n", $blocks)."\n";
    }

    /**
     * Join a type's `relations` to its `metadata`.
     *
     * @param  array<string, mixed>  $rawType
     * @return array<string, Relation>
     */
    private static function relationsFromArray(array $rawType): array
    {
        $rawRelations = $rawType['relations'] ?? [];

        if (! is_array($rawRelations) || $rawRelations === []) {
            return [];
        }

        $metadata = $rawType['metadata'] ?? null;
        $metadataRelations = is_array($metadata) && is_array($metadata['relations'] ?? null)
            ? $metadata['relations']
            : [];

        $relations = [];

        foreach ($rawRelations as $name => $rawRelation) {
            if (! is_string($name) || ! is_array($rawRelation)) {
                continue;
            }

            /** @var array<string, mixed> $rawRelation */
            $restrictions = [];
            $relationMetadata = $metadataRelations[$name] ?? null;
            $rawRestrictions = is_array($relationMetadata)
                ? ($relationMetadata['directly_related_user_types'] ?? [])
                : [];

            if (is_array($rawRestrictions)) {
                foreach ($rawRestrictions as $rawRestriction) {
                    if (! is_array($rawRestriction)) {
                        continue;
                    }

                    /** @var array<string, mixed> $rawRestriction */
                    $restrictions[] = TypeRestriction::fromArray($rawRestriction);
                }
            }

            $relations[$name] = new Relation($name, Userset::fromArray($rawRelation), $restrictions);
        }

        return $relations;
    }
}
