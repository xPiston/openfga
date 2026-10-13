<?php

declare(strict_types=1);

namespace Piston\OpenFga\Dsl\Ast;

/**
 * Brackets a userset when writing DSL, and only when it needs them.
 *
 * The DSL has no operator precedence: `a and b or c` is not valid, it is a
 * syntax error. Operators do not mix at one level, so every nested operator
 * needs parentheses - and a leaf never does. Getting this wrong produces DSL
 * that looks right, is rejected by the server, and takes a while to read.
 */
final class Parenthesise
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function around(Userset $userset): string
    {
        $needsBrackets = $userset instanceof Union
            || $userset instanceof Intersection
            || $userset instanceof Difference;

        return $needsBrackets ? '('.$userset->toDsl().')' : $userset->toDsl();
    }
}
