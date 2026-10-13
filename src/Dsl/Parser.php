<?php

declare(strict_types=1);

namespace Piston\OpenFga\Dsl;

use Piston\OpenFga\Dsl\Ast\AuthorizationModel;
use Piston\OpenFga\Dsl\Ast\ConditionDefinition;
use Piston\OpenFga\Dsl\Ast\ConditionParameter;
use Piston\OpenFga\Dsl\Ast\ParameterType;
use Piston\OpenFga\Dsl\Ast\Relation;
use Piston\OpenFga\Dsl\Ast\TypeDefinition;
use Piston\OpenFga\Exception\InvalidArgument;

/**
 * Turns OpenFGA's DSL into an authorization model.
 *
 * Written against OpenFGA's published ANTLR grammar rather than against
 * examples, and checked against the twenty-nine DSL/JSON pairs their own
 * transformer is tested with - so where this parser and the reference
 * disagree, a test says so by name.
 *
 * The one thing it deliberately does not do is understand CEL. A condition's
 * expression is captured verbatim, braces and quotes respected, and handed to
 * the server to compile. OpenFGA's grammar takes the same position, and a
 * second-guessing CEL parser in PHP could only add a way to be wrong.
 */
final class Parser
{
    /** @var list<string> */
    private readonly array $lines;

    private int $index = 0;

    private function __construct(string $dsl)
    {
        $normalised = str_replace(["\r\n", "\r"], "\n", $dsl);
        $this->lines = explode("\n", $normalised);
    }

    public static function parse(string $dsl): AuthorizationModel
    {
        return (new self($dsl))->model();
    }

    private function model(): AuthorizationModel
    {
        $this->skipBlank();

        $header = $this->current();

        if ($header !== null && str_starts_with(trim($header), 'module')) {
            throw SyntaxError::at(
                'this is a module, not a model. Modular models (module/extend) are not supported yet - '
                .'compose them with OpenFGA\'s own CLI and parse the result',
                $this->index + 1,
                1,
                $header,
            );
        }

        $this->expectKeyword('model');

        $schema = $this->expectSchema();

        $types = [];
        $conditions = [];

        while (true) {
            $this->skipBlank();
            $line = $this->current();

            if ($line === null) {
                break;
            }

            $trimmed = trim($line);

            if (str_starts_with($trimmed, 'extend ')) {
                throw SyntaxError::at(
                    '"extend type" belongs to modular models, which are not supported yet',
                    $this->index + 1,
                    1,
                    $line,
                );
            }

            if (preg_match('/^type(\s|$)/', $trimmed) === 1) {
                $type = $this->typeDefinition();

                if (isset($types[$type->name])) {
                    throw SyntaxError::at(
                        sprintf('the type "%s" is defined twice', $type->name),
                        $this->index,
                        1,
                        $line,
                    );
                }

                $types[$type->name] = $type;

                continue;
            }

            if (preg_match('/^condition(\s|\(|$)/', $trimmed) === 1) {
                $condition = $this->conditionDefinition();

                if (isset($conditions[$condition->name])) {
                    throw SyntaxError::at(
                        sprintf('the condition "%s" is defined twice', $condition->name),
                        $this->index,
                        1,
                        $line,
                    );
                }

                $conditions[$condition->name] = $condition;

                continue;
            }

            throw SyntaxError::at(
                sprintf('expected "type" or "condition", found "%s"', $trimmed),
                $this->index + 1,
                strlen($line) - strlen(ltrim($line)) + 1,
                $line,
            );
        }

        return new AuthorizationModel($schema, $types, $conditions);
    }

    private function typeDefinition(): TypeDefinition
    {
        $line = (string) $this->current();
        $lineNumber = $this->index + 1;

        if (preg_match('/^\s*type\s+([A-Za-z_][A-Za-z0-9_\-\/.]*)\s*$/', $line, $matches) !== 1) {
            throw SyntaxError::at(
                'a type declaration is "type <name>" and nothing else on the line',
                $lineNumber,
                1,
                $line,
            );
        }

        $name = $matches[1];
        $this->index++;

        $relations = [];
        $save = $this->index;
        $this->skipBlank();
        $next = $this->current();

        if ($next === null || trim($next) !== 'relations') {
            // A type with no relations. Rewind so the caller sees whatever
            // came next - another type, a condition, or the end.
            $this->index = $save;

            return new TypeDefinition($name);
        }

        $this->index++;

        while (true) {
            $save = $this->index;
            $this->skipBlank();
            $line = $this->current();

            if ($line === null) {
                break;
            }

            $trimmed = trim($line);

            if (! str_starts_with($trimmed, 'define')) {
                $this->index = $save;
                break;
            }

            $relation = $this->relation();

            if (isset($relations[$relation->name])) {
                throw SyntaxError::at(
                    sprintf('the relation "%s" is defined twice on type "%s"', $relation->name, $name),
                    $this->index,
                    1,
                    $line,
                );
            }

            $relations[$relation->name] = $relation;
        }

        if ($relations === []) {
            throw SyntaxError::at(
                sprintf('type "%s" says "relations" and then defines none', $name),
                $this->index,
                1,
                (string) ($this->lines[$this->index - 1] ?? ''),
            );
        }

        return new TypeDefinition($name, $relations);
    }

    private function relation(): Relation
    {
        $line = (string) $this->current();
        $lineNumber = $this->index + 1;
        $this->index++;

        if (preg_match('/^(\s*define\s+)([A-Za-z_][A-Za-z0-9_\-\/.]*)\s*:(.*)$/', $line, $matches) !== 1) {
            throw SyntaxError::at(
                'a relation is "define <name>: <definition>"',
                $lineNumber,
                strlen($line) - strlen(ltrim($line)) + 1,
                $line,
            );
        }

        $name = $matches[2];
        $body = $matches[3];
        $offset = strlen($matches[1]) + strlen($name) + 1;

        [$userset, $restrictions] = RelationExpression::parse($body, $lineNumber, $offset + 1, $line);

        return new Relation($name, $userset, $restrictions);
    }

    /**
     * A `condition` block.
     *
     * Scanned rather than read line by line, because the grammar allows the
     * parameter list to wrap and because the expression is whatever sits
     * between the braces - including braces of its own, which CEL has both as
     * map literals and inside strings. So the brace counter here ignores
     * anything in quotes, which is what makes OpenFGA's own `x=="{}"` test
     * case come out right.
     */
    private function conditionDefinition(): ConditionDefinition
    {
        $startLine = $this->index + 1;
        $startIndex = $this->index;

        // Join forward until the opening brace is in hand.
        $header = '';
        $braceAt = false;

        while ($this->index < count($this->lines)) {
            $header .= ($header === '' ? '' : "\n").$this->lines[$this->index];
            $this->index++;

            if (str_contains($header, '{')) {
                $braceAt = true;
                break;
            }
        }

        if (! $braceAt) {
            throw SyntaxError::at(
                'a condition needs a "{" to open its expression',
                $startLine,
                1,
                (string) ($this->lines[$startIndex] ?? ''),
            );
        }

        $bracePosition = (int) strpos($header, '{');
        $signature = substr($header, 0, $bracePosition);
        $expression = substr($header, $bracePosition + 1);

        // Keep joining until the braces balance outside of any string.
        while (($closing = self::closingBrace($expression)) === null) {
            if ($this->index >= count($this->lines)) {
                throw SyntaxError::at(
                    'a condition\'s "{" is never closed',
                    $startLine,
                    1,
                    (string) ($this->lines[$startIndex] ?? ''),
                );
            }

            $expression .= "\n".$this->lines[$this->index];
            $this->index++;
        }

        $trailing = trim(substr($expression, $closing + 1));
        $expression = substr($expression, 0, $closing);

        if ($trailing !== '') {
            throw SyntaxError::at(
                sprintf('unexpected "%s" after a condition\'s closing brace', $trailing),
                $this->index,
                1,
                (string) ($this->lines[$this->index - 1] ?? ''),
            );
        }

        [$name, $parameters] = $this->conditionSignature($signature, $startLine);

        return new ConditionDefinition($name, $parameters, trim($expression));
    }

    /**
     * @return array{string, array<string, ConditionParameter>}
     */
    private function conditionSignature(string $signature, int $line): array
    {
        $collapsed = trim(preg_replace('/\s+/', ' ', $signature) ?? $signature);

        if (preg_match('/^condition\s+([A-Za-z_][A-Za-z0-9_]*)\s*\((.*)\)$/', $collapsed, $matches) !== 1) {
            throw SyntaxError::at(
                'a condition is "condition <name>(<param>: <type>, ...) { <expression> }"',
                $line,
                1,
                $signature,
            );
        }

        $name = $matches[1];
        $rawParameters = trim($matches[2]);

        if ($rawParameters === '') {
            throw SyntaxError::at(
                sprintf('the condition "%s" declares no parameters - OpenFGA requires at least one', $name),
                $line,
                1,
                $signature,
            );
        }

        $parameters = [];

        foreach (self::splitParameters($rawParameters) as $raw) {
            $parameter = $this->conditionParameter($raw, $name, $line, $signature);

            if (isset($parameters[$parameter->name])) {
                throw SyntaxError::at(
                    sprintf('the condition "%s" declares "%s" twice', $name, $parameter->name),
                    $line,
                    1,
                    $signature,
                );
            }

            $parameters[$parameter->name] = $parameter;
        }

        return [$name, $parameters];
    }

    private function conditionParameter(string $raw, string $condition, int $line, string $signature): ConditionParameter
    {
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*:\s*([A-Za-z]+)(?:\s*<\s*([A-Za-z]+)\s*>)?$/', trim($raw), $matches) !== 1) {
            throw SyntaxError::at(
                sprintf('"%s" is not a parameter of the condition "%s" - write "<name>: <type>"', trim($raw), $condition),
                $line,
                1,
                $signature,
            );
        }

        try {
            return new ConditionParameter(
                $matches[1],
                ParameterType::fromDsl($matches[2]),
                ($matches[3] ?? '') === '' ? null : ParameterType::fromDsl($matches[3]),
            );
        } catch (InvalidArgument $e) {
            throw SyntaxError::at($e->getMessage(), $line, 1, $signature);
        }
    }

    /**
     * Where the expression's closing brace is, or null if it is not here yet.
     *
     * Quotes are respected - single, double, and backslash escapes inside
     * both - so `x == "{"` does not look balanced and `x == "{}"` does not
     * look like the end.
     */
    private static function closingBrace(string $expression): ?int
    {
        $depth = 1;
        $length = strlen($expression);
        $quote = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $expression[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;

                    continue;
                }

                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;

                continue;
            }

            if ($char === '{') {
                $depth++;

                continue;
            }

            if ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * Split on commas that are not inside `<...>` or a string.
     *
     * @return list<string>
     */
    private static function splitParameters(string $raw): array
    {
        $parameters = [];
        $buffer = '';
        $depth = 0;
        $quote = null;
        $length = strlen($raw);

        for ($i = 0; $i < $length; $i++) {
            $char = $raw[$i];

            if ($quote !== null) {
                $buffer .= $char;

                if ($char === '\\' && $i + 1 < $length) {
                    $buffer .= $raw[++$i];
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                $buffer .= $char;

                continue;
            }

            if ($char === '<') {
                $depth++;
            } elseif ($char === '>') {
                $depth--;
            }

            if ($char === ',' && $depth === 0) {
                $parameters[] = $buffer;
                $buffer = '';

                continue;
            }

            $buffer .= $char;
        }

        if (trim($buffer) !== '') {
            $parameters[] = $buffer;
        }

        return $parameters;
    }

    private function expectKeyword(string $keyword): void
    {
        $line = $this->current();

        if ($line === null || trim($line) !== $keyword) {
            throw SyntaxError::at(
                sprintf('a model starts with "%s" on a line of its own', $keyword),
                $this->index + 1,
                1,
                $line ?? '',
            );
        }

        $this->index++;
    }

    private function expectSchema(): string
    {
        $this->skipBlank();
        $line = $this->current();

        if ($line === null || preg_match('/^\s*schema\s+(\d+\.\d+)\s*$/', $line, $matches) !== 1) {
            throw SyntaxError::at(
                'the line after "model" is "schema 1.1"',
                $this->index + 1,
                1,
                $line ?? '',
            );
        }

        $this->index++;

        return $matches[1];
    }

    private function current(): ?string
    {
        return $this->lines[$this->index] ?? null;
    }

    /** Blank lines and whole-line comments carry no meaning. */
    private function skipBlank(): void
    {
        while ($this->index < count($this->lines)) {
            $trimmed = trim($this->lines[$this->index]);

            if ($trimmed !== '' && ! str_starts_with($trimmed, '#')) {
                return;
            }

            $this->index++;
        }
    }
}
