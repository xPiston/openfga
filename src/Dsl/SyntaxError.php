<?php

declare(strict_types=1);

namespace Piston\OpenFga\Dsl;

use DomainException;
use Piston\OpenFga\Exception\OpenFgaException;

/**
 * The DSL would not parse, with the place it stopped.
 *
 * A line number and a caret, because that is the difference between a parser
 * you can use and one you argue with. Models are long - the GitHub sample is
 * sixty lines - and "unexpected token" without a position means reading all
 * sixty.
 */
final class SyntaxError extends DomainException implements OpenFgaException
{
    /**
     * `atLine` and not `line`: Exception already has a `$line`, which is the
     * line of the PHP file that threw. Shadowing it is a fatal error - and
     * would have been confusing even if PHP allowed it, since the two numbers
     * point into different files.
     */
    private function __construct(
        public readonly string $problem,
        public readonly int $atLine,
        public readonly int $atColumn,
        public readonly string $sourceLine,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function at(string $problem, int $line, int $column, string $sourceLine): self
    {
        $message = sprintf("%s on line %d:\n\n%s\n%s^\n", $problem, $line, $sourceLine, str_repeat(' ', max(0, $column - 1)));

        return new self($problem, $line, $column, $sourceLine, $message);
    }
}
