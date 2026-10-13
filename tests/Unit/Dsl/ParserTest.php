<?php

declare(strict_types=1);

namespace Piston\OpenFga\Tests\Unit\Dsl;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Piston\OpenFga\Dsl\Ast\Difference;
use Piston\OpenFga\Dsl\Ast\DirectUserset;
use Piston\OpenFga\Dsl\Ast\Intersection;
use Piston\OpenFga\Dsl\Ast\TupleToUserset;
use Piston\OpenFga\Dsl\Ast\Union;
use Piston\OpenFga\Dsl\Dsl;
use Piston\OpenFga\Dsl\SyntaxError;
use Piston\OpenFga\Exception\InvalidArgument;

/**
 * The parser beyond conformance.
 *
 * The reference fixtures settle whether this agrees with OpenFGA's own
 * transformer. These cover what the fixtures do not: the input a person
 * actually types - comments, Windows line endings, a mistake - and the rules
 * the grammar states that no valid fixture can demonstrate.
 */
final class ParserTest extends TestCase
{
    private const MINIMAL = "model\n  schema 1.1\n\ntype user\n";

    #[Test]
    public function test_the_smallest_useful_model(): void
    {
        $model = Dsl::parse(self::MINIMAL);

        $this->assertSame('1.1', $model->schemaVersion);
        $this->assertSame(['user'], $model->typeNames());
        $this->assertSame([], $model->relationNames('user'));
    }

    #[Test]
    public function test_each_userset_shape(): void
    {
        $model = Dsl::parse(<<<'FGA'
        model
          schema 1.1

        type user

        type folder
          relations
            define parent: [folder]
            define direct: [user]
            define computed: direct
            define inherited: parent from parent
            define both: direct or computed
            define strict: direct and computed
            define minus: direct but not computed
        FGA);

        $folder = $model->type('folder');
        $this->assertNotNull($folder);
        $this->assertInstanceOf(DirectUserset::class, $folder->relation('direct')?->userset);
        $this->assertInstanceOf(Union::class, $folder->relation('both')?->userset);
        $this->assertInstanceOf(Intersection::class, $folder->relation('strict')?->userset);
        $this->assertInstanceOf(Difference::class, $folder->relation('minus')?->userset);

        $inherited = $folder->relation('inherited')?->userset;
        $this->assertInstanceOf(TupleToUserset::class, $inherited);
        $this->assertSame('parent', $inherited->computedRelation);
        $this->assertSame('parent', $inherited->tuplesetRelation);
    }

    /**
     * The language has no operator precedence - mixing is a syntax error
     * rather than an implicit grouping.
     *
     * Inventing a precedence here would accept models the server rejects,
     * and, worse, would silently give some of them a different meaning than
     * the author intended.
     */
    #[Test]
    #[DataProvider('mixedOperators')]
    public function test_operators_cannot_be_mixed_without_parentheses(string $definition): void
    {
        try {
            Dsl::parse(self::relation($definition));
            $this->fail(sprintf('"%s" should not have parsed', $definition));
        } catch (SyntaxError $e) {
            $this->assertStringContainsString('no operator precedence', $e->getMessage());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function mixedOperators(): iterable
    {
        yield 'or then and' => ['a or b and c'];
        yield 'and then or' => ['a and b or c'];
        yield 'or then but not' => ['a or b but not c'];
        yield 'and then but not' => ['a and b but not c'];
    }

    #[Test]
    public function test_parentheses_make_the_same_thing_legal(): void
    {
        $model = Dsl::parse(self::relation('(a or b) and c', ['a', 'b', 'c']));
        $userset = $model->type('document')?->relation('x')?->userset;

        $this->assertInstanceOf(Intersection::class, $userset);
        $this->assertInstanceOf(Union::class, $userset->children[0]);
    }

    /** `a but not b but not c` is not valid; it has to be `a but not (b or c)`. */
    #[Test]
    public function test_but_not_takes_exactly_one_right_hand_side(): void
    {
        try {
            Dsl::parse(self::relation('a but not b but not c', ['a', 'b', 'c']));
            $this->fail('a chained "but not" should not parse');
        } catch (SyntaxError $e) {
            $this->assertStringContainsString('but not (b or c)', $e->getMessage());
        }
    }

    /**
     * A direct assignment may only lead - at any depth.
     */
    #[Test]
    public function test_a_direct_assignment_may_only_lead(): void
    {
        // Legal, and in the reference fixtures. The restrictions coming
        // back is what proves the leading `[...]` was read rather than
        // merely tolerated.
        $leading = Dsl::parse(self::relation('[user] or a', ['a']))->type('document')?->relation('x');
        $this->assertNotNull($leading);
        $this->assertSame(['user'], array_map(
            static fn ($r): string => $r->type,
            $leading->restrictions,
        ));

        $nested = Dsl::parse(self::relation('([user] and a) but not b', ['a', 'b']))->type('document')?->relation('x');
        $this->assertInstanceOf(Difference::class, $nested?->userset);
        $this->assertSame(['user'], array_map(
            static fn ($r): string => $r->type,
            $nested->restrictions,
        ));

        try {
            Dsl::parse(self::relation('a or [user]', ['a']));
            $this->fail('a trailing direct assignment should not parse');
        } catch (SyntaxError $e) {
            $this->assertStringContainsString('may only be the first thing', $e->getMessage());
        }
    }

    /**
     * Two direct assignments in one relation cannot both lead, so the second
     * is refused by the positional rule - there is no separate "only one"
     * check to write, and an earlier version of this parser had one that
     * could never fire.
     */
    #[Test]
    #[DataProvider('twoDirectAssignments')]
    public function test_a_relation_cannot_carry_two_direct_assignments(string $definition): void
    {
        $this->expectException(SyntaxError::class);
        $this->expectExceptionMessageMatches('/may only be the first thing/');

        Dsl::parse(self::relation($definition, ['a']));
    }

    /** @return iterable<string, array{string}> */
    public static function twoDirectAssignments(): iterable
    {
        yield 'side by side' => ['[user] or [user]'];
        yield 'the second inside a group' => ['[user] or ([user] and a)'];
        yield 'both inside one group' => ['([user] or [user])'];
        yield 'across an intersection' => ['[user] and [user]'];
    }

    /**
     * `orders` starts with `or`.
     *
     * A parser that matched the operator without checking the next character
     * would read `define x: orders` as a union with nothing on the right.
     */
    #[Test]
    public function test_a_relation_whose_name_starts_with_an_operator(): void
    {
        $model = Dsl::parse(<<<'FGA'
        model
          schema 1.1

        type user

        type shop
          relations
            define orders: [user]
            define android: [user]
            define format: [user]
            define owner: orders or android or format
        FGA);

        $this->assertSame(['orders', 'android', 'format', 'owner'], $model->relationNames('shop'));
        $this->assertInstanceOf(Union::class, $model->type('shop')?->relation('owner')?->userset);
    }

    #[Test]
    public function test_comments_and_blank_lines_carry_no_meaning(): void
    {
        $model = Dsl::parse(<<<'FGA'
        # The model for the document service.
        model
          schema 1.1

        # Users come from the identity provider.
        type user


        type document
          # Who may look at it.
          relations
            define viewer: [user]
        FGA);

        $this->assertSame(['user', 'document'], $model->typeNames());
        $this->assertSame(['viewer'], $model->relationNames('document'));
    }

    #[Test]
    public function test_windows_line_endings(): void
    {
        $crlf = str_replace("\n", "\r\n", self::MINIMAL);
        $cr = str_replace("\n", "\r", self::MINIMAL);

        $this->assertSame(['user'], Dsl::parse($crlf)->typeNames());
        $this->assertSame(['user'], Dsl::parse($cr)->typeNames());
    }

    /**
     * Braces inside a CEL string must not end the condition.
     *
     * OpenFGA's own corpus has `x=="{}"`, which a scanner that stopped at the
     * first brace would truncate. The quoted-brace case is theirs; the
     * unbalanced one below is not, and is where a brace *counter* without
     * string awareness also breaks.
     */
    #[Test]
    #[DataProvider('celExpressions')]
    public function test_a_condition_expression_is_captured_verbatim(string $expression): void
    {
        $model = Dsl::parse(sprintf(
            "model\n  schema 1.1\n\ntype user\n\ntype doc\n  relations\n    define v: [user with c]\n\ncondition c(x: string) {\n  %s\n}\n",
            $expression,
        ));

        $this->assertSame($expression, $model->condition('c')?->expression);
    }

    /** @return iterable<string, array{string}> */
    public static function celExpressions(): iterable
    {
        yield 'a quoted empty map' => ['x=="{}"'];
        yield 'an unbalanced open brace in a string' => ['x == "{"'];
        yield 'an unbalanced close brace in a string' => ['x == "}"'];
        yield 'a map literal' => ['x in {"a": 1, "b": 2}'];
        yield 'single quotes' => ["x == '}'"];
        yield 'an escaped quote' => ['x == "a\\"}b"'];
        yield 'internal double spaces survive' => ['x != null &&  x != "a"'];
        yield 'a comparison' => ['x < "2026-01-01"'];
    }

    #[Test]
    public function test_every_condition_parameter_type(): void
    {
        $model = Dsl::parse(<<<'FGA'
        model
          schema 1.1

        type user

        type doc
          relations
            define v: [user with c]

        condition c(a: bool, b: string, c: int, d: uint, e: double, f: duration, g: timestamp, h: ipaddress, i: list<string>, j: map<int>) {
          a
        }
        FGA);

        $condition = $model->condition('c');
        $this->assertNotNull($condition);
        $parameters = $condition->parameters;

        $this->assertCount(10, $parameters);
        $this->assertSame('TYPE_NAME_IPADDRESS', $parameters['h']->type->wireName());
        $this->assertSame('TYPE_NAME_LIST', $parameters['i']->type->wireName());
        $this->assertSame('TYPE_NAME_STRING', $parameters['i']->generic?->wireName());
        $this->assertSame('TYPE_NAME_MAP', $parameters['j']->type->wireName());
        $this->assertSame('TYPE_NAME_INT', $parameters['j']->generic?->wireName());
    }

    /** Containers do not nest - a rule the reference corpus has four cases for. */
    #[Test]
    #[DataProvider('nestedContainers')]
    public function test_containers_do_not_nest(string $type): void
    {
        $this->expectException(SyntaxError::class);
        $this->expectExceptionMessageMatches('/do not nest/');

        Dsl::parse(sprintf(
            "model\n  schema 1.1\n\ntype user\n\ntype doc\n  relations\n    define v: [user with c]\n\ncondition c(x: %s) {\n  x\n}\n",
            $type,
        ));
    }

    /** @return iterable<string, array{string}> */
    public static function nestedContainers(): iterable
    {
        yield 'list of list' => ['list<list>'];
        yield 'list of map' => ['list<map>'];
        yield 'map of map' => ['map<map>'];
        yield 'map of list' => ['map<list>'];
    }

    #[Test]
    #[DataProvider('refusals')]
    public function test_what_does_not_parse(string $dsl, string $expected): void
    {
        try {
            Dsl::parse($dsl);
            $this->fail('this should not have parsed: '.$dsl);
        } catch (SyntaxError|InvalidArgument $e) {
            $this->assertStringContainsString($expected, $e->getMessage());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusals(): iterable
    {
        yield 'no header' => ["type user\n", 'starts with "model"'];
        yield 'no schema' => ["model\ntype user\n", 'schema 1.1'];
        yield 'a bad schema' => ["model\n  schema abc\n", 'schema 1.1'];
        yield 'a module' => ["module core\n\ntype user\n", 'Modular models'];
        yield 'extend' => ["model\n  schema 1.1\n\nextend type user\n", 'modular models'];
        yield 'a duplicate type' => ["model\n  schema 1.1\n\ntype user\n\ntype user\n", 'defined twice'];
        yield 'relations with none' => ["model\n  schema 1.1\n\ntype user\n  relations\n", 'defines none'];
        yield 'an unclosed bracket' => ["model\n  schema 1.1\n\ntype user\n  relations\n    define v: [user\n", 'never closed'];
        yield 'an unclosed paren' => ["model\n  schema 1.1\n\ntype user\n  relations\n    define v: (a or b\n", 'never closed'];
        yield 'an empty assignment' => ["model\n  schema 1.1\n\ntype user\n  relations\n    define v: []\n", 'lists no types'];
        yield 'nothing after the colon' => ["model\n  schema 1.1\n\ntype user\n  relations\n    define v:\n", 'nothing after the colon'];
        yield 'a condition with no brace' => ["model\n  schema 1.1\n\ntype user\n\ncondition c(x: string)\n", 'needs a "{"'];
        yield 'an unclosed condition' => ["model\n  schema 1.1\n\ntype user\n\ncondition c(x: string) {\n  x\n", 'never closed'];
        yield 'a condition with no parameters' => ["model\n  schema 1.1\n\ntype user\n\ncondition c() {\n  true\n}\n", 'declares no parameters'];
        yield 'an unknown parameter type' => ["model\n  schema 1.1\n\ntype user\n\ncondition c(x: widget) {\n  x\n}\n", 'not one of bool'];
        yield 'only a colon after a type' => ["model\n  schema 1.1\n\ntype user\n  relations\n    define v: [user:foo]\n", 'only "type:*"'];
    }

    #[Test]
    public function test_a_file_that_is_not_there_says_so(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessageMatches('/does not exist or cannot be read/');

        Dsl::file('/no/such/model.fga');
    }

    #[Test]
    public function test_a_file_is_read(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fga-');
        $this->assertNotFalse($path);
        file_put_contents($path, self::MINIMAL);

        try {
            $this->assertSame(['user'], Dsl::file($path)->typeNames());
        } finally {
            unlink($path);
        }
    }

    #[Test]
    public function test_json_that_is_not_json_says_so(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessageMatches('/not valid JSON/');

        Dsl::fromJson('{nope');
    }

    /** @param list<string> $extra */
    private static function relation(string $definition, array $extra = []): string
    {
        $relations = '';

        foreach ($extra as $name) {
            $relations .= sprintf("    define %s: [user]\n", $name);
        }

        return sprintf(
            "model\n  schema 1.1\n\ntype user\n\ntype document\n  relations\n%s    define x: %s\n",
            $relations,
            $definition,
        );
    }
}
