<?php

declare(strict_types=1);

namespace Piston\OpenFga\Dsl\Ast;

use Piston\OpenFga\Exception\InvalidArgument;
use stdClass;

/**
 * A `condition` block: typed parameters and a CEL expression.
 *
 * The expression is **kept verbatim and never parsed**. That is deliberate.
 * CEL is a whole language with its own grammar, the server compiles it and
 * reports on it precisely, and a PHP re-implementation could only ever be a
 * second opinion that is sometimes wrong. OpenFGA's own DSL grammar takes the
 * same position: the expression is everything up to the closing brace.
 *
 * So whitespace inside the expression survives, including the double space in
 * OpenFGA's own `_ipaddress != null &&  _ipaddress != ...` example. Only the
 * outer edges are trimmed, because those are the braces' doing rather than the
 * author's.
 */
final class ConditionDefinition
{
    /** @var array<string, ConditionParameter> */
    public readonly array $parameters;

    /** @param array<string, ConditionParameter> $parameters */
    public function __construct(
        public readonly string $name,
        array $parameters,
        public readonly string $expression,
    ) {
        if (trim($name) === '') {
            throw InvalidArgument::because('a condition', 'has no name');
        }

        if (trim($expression) === '') {
            throw InvalidArgument::because(sprintf('the condition "%s"', $name), 'has an empty expression');
        }

        $this->parameters = $parameters;
    }

    /** @param array<string, mixed> $json */
    public static function fromArray(string $name, array $json): self
    {
        $expression = $json['expression'] ?? null;

        if (! is_string($expression)) {
            throw InvalidArgument::because(sprintf('the condition "%s"', $name), 'has no expression');
        }

        $parameters = [];
        $raw = $json['parameters'] ?? [];

        if (is_array($raw)) {
            foreach ($raw as $parameterName => $parameter) {
                if (! is_string($parameterName) || ! is_array($parameter)) {
                    continue;
                }

                /** @var array<string, mixed> $parameter */
                $parameters[$parameterName] = ConditionParameter::fromArray($parameterName, $parameter);
            }
        }

        return new self($name, $parameters, $expression);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $parameters = [];

        foreach ($this->parameters as $name => $parameter) {
            $parameters[$name] = $parameter->toArray();
        }

        return [
            'name' => $this->name,
            'expression' => $this->expression,
            'parameters' => $parameters === [] ? new stdClass : $parameters,
        ];
    }

    public function toDsl(): string
    {
        $parameters = implode(', ', array_map(
            static fn (ConditionParameter $p): string => $p->toDsl(),
            array_values($this->parameters),
        ));

        return sprintf("condition %s(%s) {\n  %s\n}", $this->name, $parameters, $this->expression);
    }
}
