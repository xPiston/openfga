<?php

declare(strict_types=1);

namespace Piston\OpenFga\Dsl;

use Piston\OpenFga\Dsl\Ast\ComputedUserset;
use Piston\OpenFga\Dsl\Ast\Difference;
use Piston\OpenFga\Dsl\Ast\DirectUserset;
use Piston\OpenFga\Dsl\Ast\Intersection;
use Piston\OpenFga\Dsl\Ast\TupleToUserset;
use Piston\OpenFga\Dsl\Ast\TypeRestriction;
use Piston\OpenFga\Dsl\Ast\Union;
use Piston\OpenFga\Dsl\Ast\Userset;

/**
 * Parses the right-hand side of a `define`.
 *
 *     [user, group#member] or owner or viewer from parent
 *     (base and allowed) but not blocked
 *
 * Three rules from OpenFGA's grammar that this enforces, each of which a
 * hand-rolled parser usually misses:
 *
 * **Operators do not mix.** There is no precedence in this language. `a and b
 * or c` is a syntax error, not an implicit grouping - the author has to say
 * which they meant with parentheses. Inventing a precedence here would accept
 * models the server rejects, and worse, would silently give some of them a
 * different meaning than the author intended.
 *
 * **`but not` takes exactly one right-hand side.** `a but not b but not c`
 * does not parse; it has to be `a but not (b or c)`.
 *
 * **A direct assignment may only lead.** `[user] or owner` is fine and `owner
 * or [user]` is not, at any depth. The restriction is positional rather than
 * structural, so it is tracked as the parser descends.
 */
final class RelationExpression
{
    private int $position = 0;

    /**
     * @param  non-empty-string  $source  the text after `define name: `
     */
    private function __construct(
        private readonly string $source,
        private readonly int $line,
        private readonly int $columnOffset,
        private readonly string $sourceLine,
    ) {}

    /**
     * @return array{Userset, list<TypeRestriction>} the shape and the types it accepts
     */
    public static function parse(string $source, int $line, int $columnOffset, string $sourceLine): array
    {
        $trimmed = trim($source);

        if ($trimmed === '') {
            throw SyntaxError::at('a relation has nothing after the colon', $line, $columnOffset, $sourceLine);
        }

        $parser = new self($trimmed, $line, $columnOffset, $sourceLine);
        $restrictions = [];
        $userset = $parser->expression(true, $restrictions);

        $parser->skipWhitespace();

        if (! $parser->atEnd()) {
            throw $parser->fail(sprintf('unexpected "%s" after the relation definition', $parser->rest()));
        }

        return [$userset, $restrictions];
    }

    /**
     * @param  list<TypeRestriction>  $restrictions
     */
    private function expression(bool $directAllowed, array &$restrictions): Userset
    {
        $first = $this->operand($directAllowed, $restrictions);

        $this->skipWhitespace();

        $operator = $this->peekOperator();

        if ($operator === null) {
            return $first;
        }

        // Everything after the first operand is in a "no direct assignment"
        // position, whatever the operator.
        $children = [$first];

        if ($operator === 'but not') {
            $this->consumeOperator($operator);
            $subtract = $this->operand(false, $restrictions);
            $this->skipWhitespace();

            if ($this->peekOperator() !== null) {
                throw $this->fail(
                    '"but not" takes one right-hand side - write "a but not (b or c)" rather than '
                    .'"a but not b but not c"',
                );
            }

            return new Difference($first, $subtract);
        }

        while (true) {
            $this->skipWhitespace();
            $next = $this->peekOperator();

            if ($next === null) {
                break;
            }

            if ($next !== $operator) {
                throw $this->fail(sprintf(
                    'this language has no operator precedence, so "%s" and "%s" cannot be mixed at the '
                    .'same level - add parentheses to say which you mean',
                    $operator,
                    $next,
                ));
            }

            $this->consumeOperator($next);
            $children[] = $this->operand(false, $restrictions);
        }

        return $operator === 'or' ? new Union($children) : new Intersection($children);
    }

    /** @param list<TypeRestriction> $restrictions */
    private function operand(bool $directAllowed, array &$restrictions): Userset
    {
        $this->skipWhitespace();

        if ($this->atEnd()) {
            throw $this->fail('the relation definition ends where an operand was expected');
        }

        $char = $this->source[$this->position];

        if ($char === '[') {
            if (! $directAllowed) {
                throw $this->fail(
                    'a direct assignment "[...]" may only be the first thing in a relation definition - '
                    .'write "[user] or owner" rather than "owner or [user]"',
                );
            }

            // There is no separate "only one direct assignment" check,
            // because there cannot be a second one to catch: a direct
            // assignment is only permitted in the leading position, and the
            // leading position is reached once. `[user] or [user]` fails on
            // the second one being non-leading, which is the same refusal
            // with a better explanation.
            $restrictions = $this->typeRestrictions();

            return new DirectUserset;
        }

        if ($char === '(') {
            $this->position++;
            $inner = $this->expression($directAllowed, $restrictions);
            $this->skipWhitespace();

            if ($this->atEnd() || $this->source[$this->position] !== ')') {
                throw $this->fail('a "(" was never closed');
            }

            $this->position++;

            return $inner;
        }

        return $this->rewrite();
    }

    private function rewrite(): Userset
    {
        $name = $this->identifier('a relation name');

        $save = $this->position;
        $this->skipWhitespace();

        if ($this->consumeWord('from')) {
            $this->skipWhitespace();
            $tupleset = $this->identifier('a relation name after "from"');

            return new TupleToUserset($name, $tupleset);
        }

        $this->position = $save;

        return new ComputedUserset($name);
    }

    /** @return list<TypeRestriction> */
    private function typeRestrictions(): array
    {
        $this->position++; // the [
        $restrictions = [];

        while (true) {
            $this->skipWhitespace();

            if ($this->atEnd()) {
                throw $this->fail('a "[" was never closed');
            }

            if ($this->source[$this->position] === ']') {
                $this->position++;
                break;
            }

            $restrictions[] = $this->typeRestriction();
            $this->skipWhitespace();

            if ($this->atEnd()) {
                throw $this->fail('a "[" was never closed');
            }

            $char = $this->source[$this->position];

            if ($char === ',') {
                $this->position++;

                continue;
            }

            if ($char === ']') {
                $this->position++;
                break;
            }

            throw $this->fail(sprintf('expected "," or "]" inside a direct assignment, found "%s"', $char));
        }

        if ($restrictions === []) {
            throw $this->fail('a direct assignment "[]" lists no types');
        }

        return $restrictions;
    }

    private function typeRestriction(): TypeRestriction
    {
        $type = $this->identifier('a type name');
        $wildcard = false;
        $relation = null;

        if (! $this->atEnd() && $this->source[$this->position] === ':') {
            $this->position++;

            if ($this->atEnd() || $this->source[$this->position] !== '*') {
                throw $this->fail('only "type:*" is allowed after a colon in a direct assignment');
            }

            $this->position++;
            $wildcard = true;
        } elseif (! $this->atEnd() && $this->source[$this->position] === '#') {
            $this->position++;
            $relation = $this->identifier('a relation name after the "#"');
        }

        $condition = null;
        $save = $this->position;
        $this->skipWhitespace();

        if ($this->consumeWord('with')) {
            $this->skipWhitespace();
            $condition = $this->identifier('a condition name after "with"');
        } else {
            $this->position = $save;
        }

        return match (true) {
            $wildcard => TypeRestriction::wildcard($type, $condition),
            $relation !== null => TypeRestriction::userset($type, $relation, $condition),
            default => TypeRestriction::of($type, $condition),
        };
    }

    /**
     * An identifier, as OpenFGA's lexer defines it.
     *
     * Letters, digits, underscore and hyphen - and also `/` and `.`, which the
     * grammar allows for modular models where a type is named `core/document`.
     */
    private function identifier(string $what): string
    {
        $this->skipWhitespace();
        $start = $this->position;

        if ($this->atEnd() || preg_match('/[A-Za-z_]/', $this->source[$this->position]) !== 1) {
            throw $this->fail(sprintf('expected %s', $what));
        }

        $this->position++;

        while (! $this->atEnd() && preg_match('/[A-Za-z0-9_\-\/.]/', $this->source[$this->position]) === 1) {
            $this->position++;
        }

        $name = substr($this->source, $start, $this->position - $start);

        // `from`, `or` and `and` are keywords: an identifier that is one of
        // them means the author wrote `define x: or` or similar.
        if (in_array($name, ['or', 'and', 'from'], true)) {
            throw $this->fail(sprintf('"%s" is an operator, not %s', $name, $what));
        }

        return $name;
    }

    /** 'or', 'and', 'but not', or null. */
    private function peekOperator(): ?string
    {
        foreach (['but not', 'or', 'and'] as $operator) {
            if ($this->looksLikeWord($operator)) {
                return $operator;
            }
        }

        return null;
    }

    private function consumeOperator(string $operator): void
    {
        $this->position += strlen($operator);
    }

    /**
     * Is the next thing exactly this word, not the start of a longer one?
     *
     * `orders` starts with `or`, and a parser that did not check the following
     * character would read `define x: orders` as a dangling union.
     */
    private function looksLikeWord(string $word): bool
    {
        if (substr($this->source, $this->position, strlen($word)) !== $word) {
            return false;
        }

        $after = $this->source[$this->position + strlen($word)] ?? ' ';

        return preg_match('/[A-Za-z0-9_\-]/', $after) !== 1;
    }

    private function consumeWord(string $word): bool
    {
        if (! $this->looksLikeWord($word)) {
            return false;
        }

        $this->position += strlen($word);

        return true;
    }

    private function skipWhitespace(): void
    {
        while (! $this->atEnd() && ($this->source[$this->position] === ' ' || $this->source[$this->position] === "\t")) {
            $this->position++;
        }
    }

    private function atEnd(): bool
    {
        return $this->position >= strlen($this->source);
    }

    private function rest(): string
    {
        return substr($this->source, $this->position);
    }

    private function fail(string $problem): SyntaxError
    {
        return SyntaxError::at($problem, $this->line, $this->columnOffset + $this->position, $this->sourceLine);
    }
}
