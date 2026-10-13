<?php

declare(strict_types=1);

namespace Piston\OpenFga\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Piston\OpenFga\Dsl\Dsl;
use Piston\OpenFga\Dsl\SyntaxError;
use Piston\OpenFga\Exception\OpenFgaException;
use Symfony\Component\Yaml\Yaml;

/**
 * This parser against OpenFGA's own.
 *
 * The fixtures beside this file are copied unchanged from the `openfga/language`
 * repository - the reference implementation of the DSL transformer, and the
 * cases it is held to. See fixtures/NOTICE.md.
 *
 * Using their fixtures rather than mine is the whole point. A transformer that
 * agrees with examples its own author wrote has proved only that the author is
 * consistent. These twenty-nine include the real sample models - GitHub,
 * Google Drive, Slack, a bank - and a deliberate torture case with eight
 * nested mixed-operator relations.
 *
 * Three directions are checked, because a transformer is only useful if all
 * three hold:
 *
 *   DSL  -> JSON   does it agree with the reference?
 *   JSON -> DSL -> JSON   can it write what it can read?
 *   DSL  -> DSL  -> JSON   is its own output its own input?
 */
final class DslConformanceTest extends TestCase
{
    #[Test]
    #[DataProvider('cases')]
    public function test_the_dsl_becomes_the_reference_json(string $case): void
    {
        [$dsl, $json] = self::fixture($case);

        $this->assertSame(
            self::normalise($json),
            self::normalise(Dsl::toJson($dsl)),
            sprintf('%s: the JSON differs from OpenFGA\'s reference transformer', $case),
        );
    }

    #[Test]
    #[DataProvider('cases')]
    public function test_the_reference_json_can_be_written_back_as_dsl(string $case): void
    {
        [, $json] = self::fixture($case);

        // Their JSON -> our DSL -> our JSON. Proves the writer produces DSL
        // that means what it was given, rather than DSL that merely parses.
        $written = Dsl::stringify($json);

        $this->assertSame(
            self::normalise($json),
            self::normalise(Dsl::toJson($written)),
            sprintf("%s: writing the model as DSL and reading it back changed it.\n\nWe wrote:\n%s", $case, $written),
        );
    }

    #[Test]
    #[DataProvider('cases')]
    public function test_our_own_dsl_output_is_a_fixed_point(string $case): void
    {
        [$dsl, $json] = self::fixture($case);

        $rewritten = Dsl::parse($dsl)->toDsl();

        $this->assertSame(
            self::normalise($json),
            self::normalise(Dsl::toJson($rewritten)),
            sprintf("%s: re-reading our own DSL changed the model.\n\nWe wrote:\n%s", $case, $rewritten),
        );
    }

    /**
     * The invalid DSL in OpenFGA's syntax suite is refused.
     *
     * Their expected errors are ANTLR's, with its token names and its
     * positions, so matching the messages would be matching an implementation
     * rather than a language. What is asserted is the part that is the
     * language: that each of these does not parse. Which it must not, because
     * the server would refuse the result.
     */
    #[Test]
    #[DataProvider('invalidDsl')]
    public function test_invalid_dsl_is_refused(string $name, string $dsl): void
    {
        try {
            Dsl::parse($dsl);
        } catch (SyntaxError) {
            $this->addToAssertionCount(1);

            return;
        } catch (OpenFgaException) {
            // InvalidArgument from a node constructor is also a refusal; what
            // matters is that nothing malformed was accepted.
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail(sprintf("%s: this should not have parsed.\n\n%s", $name, $dsl));
    }

    /**
     * A syntax error says where.
     *
     * Not a nicety: models run to sixty lines, and an unpositioned
     * "unexpected token" means reading all sixty.
     */
    #[Test]
    public function test_a_syntax_error_points_at_the_line(): void
    {
        $dsl = <<<'FGA'
        model
          schema 1.1

        type user

        type document
          relations
            define viewer: [user] or
        FGA;

        try {
            Dsl::parse($dsl);
            $this->fail('a dangling "or" should not parse');
        } catch (SyntaxError $e) {
            $this->assertSame(8, $e->atLine);
            $this->assertStringContainsString('line 8', $e->getMessage());
            $this->assertStringContainsString('define viewer: [user] or', $e->getMessage());
            // The caret is rendered under the offending column.
            $this->assertStringContainsString('^', $e->getMessage());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function cases(): iterable
    {
        foreach (self::caseNames() as $case) {
            yield $case => [$case];
        }
    }

    /**
     * The cases the reference marks as syntax errors.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function invalidDsl(): iterable
    {
        $path = __DIR__.'/fixtures/dsl-syntax-validation-cases.yaml';

        /** @var list<array<string, mixed>> $cases */
        $cases = Yaml::parseFile($path);

        // The reference file reuses a few case names, so the index keeps the
        // provider keys unique while the name stays in the failure message.
        foreach ($cases as $index => $case) {
            $name = $case['name'] ?? null;
            $dsl = $case['dsl'] ?? null;
            $errors = $case['expected_errors'] ?? null;

            if (! is_string($name) || ! is_string($dsl) || ! is_array($errors) || $errors === []) {
                continue;
            }

            yield sprintf('#%d %s', $index, $name) => [$name, $dsl];
        }
    }

    /** @return list<string> */
    private static function caseNames(): array
    {
        $names = [];

        foreach (glob(__DIR__.'/fixtures/*/authorization-model.fga') ?: [] as $file) {
            $names[] = basename(dirname($file));
        }

        sort($names);

        return $names;
    }

    /** @return array{string, string} */
    private static function fixture(string $case): array
    {
        $directory = __DIR__.'/fixtures/'.$case;

        return [
            (string) file_get_contents($directory.'/authorization-model.fga'),
            (string) file_get_contents($directory.'/authorization-model.json'),
        ];
    }

    /**
     * Key order is not meaning; list order is.
     *
     * So maps are sorted before comparison and lists are not - a union's
     * children are ordered and reordering them would change the model. The
     * JSON round trip also flattens the empty objects this library builds as
     * stdClass, which `===` would otherwise compare by identity.
     */
    private static function normalise(string $json): string
    {
        /** @var mixed $decoded */
        $decoded = json_decode($json, true);

        return (string) json_encode(self::sort($decoded), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private static function sort(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        $sorted = array_map(static fn (mixed $v): mixed => self::sort($v), $value);

        if (! $isList) {
            ksort($sorted);
        }

        return $sorted;
    }
}
