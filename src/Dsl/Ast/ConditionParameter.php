<?php

declare(strict_types=1);

namespace Piston\OpenFga\Dsl\Ast;

use Piston\OpenFga\Exception\InvalidArgument;

/** One parameter of a condition: `user_ip: ipaddress`, or `roles: list<string>`. */
final class ConditionParameter
{
    public function __construct(
        public readonly string $name,
        public readonly ParameterType $type,
        public readonly ?ParameterType $generic = null,
    ) {
        if ($type->isContainer() && $generic === null) {
            throw InvalidArgument::because(
                sprintf('the condition parameter "%s"', $name),
                sprintf('is a %s and so needs an element type, as in %s<string>', $type->value, $type->value),
            );
        }

        if (! $type->isContainer() && $generic !== null) {
            throw InvalidArgument::because(
                sprintf('the condition parameter "%s"', $name),
                sprintf('is a %s, which takes no element type', $type->value),
            );
        }

        // Containers do not nest. `list<list>`, `map<map>` and the two
        // crossings are refused by OpenFGA, and refusing them here rather
        // than at the server means the message names the parameter. Found by
        // the reference syntax corpus, which has a case for each of the four.
        if ($generic !== null && $generic->isContainer()) {
            throw InvalidArgument::because(
                sprintf('the condition parameter "%s"', $name),
                sprintf(
                    'is a %s of %s, and containers do not nest - the element type has to be a scalar '
                    .'such as string, int or timestamp',
                    $type->value,
                    $generic->value,
                ),
            );
        }
    }

    /** @param array<string, mixed> $json */
    public static function fromArray(string $name, array $json): self
    {
        $wire = $json['type_name'] ?? null;

        if (! is_string($wire) || $wire === '') {
            throw InvalidArgument::because(sprintf('the condition parameter "%s"', $name), 'has no type_name');
        }

        $type = ParameterType::fromWireName($wire);
        $generic = null;

        if ($type->isContainer()) {
            $generics = $json['generic_types'] ?? null;
            $first = is_array($generics) ? ($generics[0] ?? null) : null;
            $genericWire = is_array($first) ? ($first['type_name'] ?? null) : null;

            if (! is_string($genericWire) || $genericWire === '') {
                throw InvalidArgument::because(
                    sprintf('the condition parameter "%s"', $name),
                    sprintf('is a %s with no generic_types', $type->value),
                );
            }

            $generic = ParameterType::fromWireName($genericWire);
        }

        return new self($name, $type, $generic);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $json = ['type_name' => $this->type->wireName()];

        if ($this->generic !== null) {
            $json['generic_types'] = [['type_name' => $this->generic->wireName()]];
        }

        return $json;
    }

    public function toDsl(): string
    {
        $type = $this->generic === null
            ? $this->type->value
            : sprintf('%s<%s>', $this->type->value, $this->generic->value);

        return $this->name.': '.$type;
    }
}
