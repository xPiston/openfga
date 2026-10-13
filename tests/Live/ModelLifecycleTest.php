<?php

declare(strict_types=1);

namespace Piston\OpenFga\Tests\Live;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Piston\OpenFga\Dsl\Ast\Difference;
use Piston\OpenFga\Dsl\Dsl;
use Piston\OpenFga\Exception\ValidationError;

/**
 * The DSL against the only judge that counts.
 *
 * The conformance suite proves this parser agrees with OpenFGA's reference
 * transformer. This proves the server agrees too - which is a different
 * claim, and the one an integration depends on.
 */
final class ModelLifecycleTest extends LiveTestCase
{
    #[Test]
    public function test_a_model_written_from_dsl_comes_back_identical(): void
    {
        $dsl = <<<'FGA'
        model
          schema 1.1

        type user

        type group
          relations
            define member: [user]

        type folder
          relations
            define owner: [user]
            define viewer: [user, user:*, group#member] or owner

        type document
          relations
            define parent: [folder]
            define owner: [user] or owner from parent
            define editor: [user, group#member] or owner
            define viewer: [user with non_expired_grant] or editor or viewer from parent
            define blocked: [user]
            define can_read: viewer but not blocked

        condition non_expired_grant(current_time: timestamp, grant_time: timestamp, grant_duration: duration) {
          current_time < grant_time + grant_duration
        }
        FGA;

        $id = $this->deploy($dsl);
        $mine = Dsl::parse($dsl);
        $theirs = $this->fga->models()->read($id);

        $this->assertSame(
            self::normalise($mine->toArray()),
            self::normalise($theirs->toArray()),
            'the server stored something other than what the parser produced',
        );

        $this->assertSame(['user', 'group', 'folder', 'document'], $theirs->typeNames());
        $this->assertNotNull($theirs->condition('non_expired_grant'));
        $this->assertInstanceOf(Difference::class, $theirs->type('document')?->relation('can_read')?->userset);
    }

    /**
     * The full circle: our DSL, their JSON, our DSL again.
     *
     * The server renders protobuf zero values that mean "absent" -
     * `condition: ""`, `module: ""`, `source_info: null`, `conditions: {}`.
     * A reader that took `condition: ""` for a condition named `""` would
     * write DSL the server then refuses, so this is the test that pins it.
     */
    #[Test]
    #[DataProvider('models')]
    public function test_the_server_json_round_trips_back_to_the_same_model(string $dsl): void
    {
        $id = $this->deploy($dsl);
        $theirs = $this->fga->models()->read($id);

        $rewritten = Dsl::parse($theirs->toDsl());

        $this->assertSame(
            self::normalise($theirs->toArray()),
            self::normalise($rewritten->toArray()),
            "writing the server's own model as DSL and reading it back changed it:\n\n".$theirs->toDsl(),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function models(): iterable
    {
        yield 'a wildcard and a userset' => [
            "model\n  schema 1.1\n\ntype user\n\ntype group\n  relations\n    define member: [user]\n\n"
            ."type doc\n  relations\n    define viewer: [user, user:*, group#member]\n",
        ];

        yield 'a condition with every scalar type' => [
            "model\n  schema 1.1\n\ntype user\n\ntype doc\n  relations\n    define viewer: [user with c]\n\n"
            ."condition c(a: bool, b: string, c: int, d: uint, e: double, f: duration, g: timestamp, h: ipaddress) {\n"
            ."  a && b != \"\" && c > 0 && d > 0u && e > 0.0 && f > duration(\"0s\") && h.in_cidr(\"10.0.0.0/8\")\n}\n",
        ];

        yield 'a condition with containers' => [
            "model\n  schema 1.1\n\ntype user\n\ntype doc\n  relations\n    define viewer: [user with c]\n\n"
            ."condition c(tags: list<string>, meta: map<string>) {\n  \"a\" in tags && meta[\"k\"] != \"\"\n}\n",
        ];

        yield 'braces inside a string' => [
            "model\n  schema 1.1\n\ntype user\n\ntype doc\n  relations\n    define viewer: [user with c]\n\n"
            ."condition c(x: string) {\n  x == \"{}\"\n}\n",
        ];

        yield 'nested mixed operators' => [
            "model\n  schema 1.1\n\ntype user\n\ntype doc\n  relations\n"
            ."    define a: [user]\n    define b: [user]\n    define c: [user]\n"
            ."    define x: (a and b) but not c\n    define y: a and (b but not c)\n"
            ."    define z: ((a and b) or (a but not c)) and b\n",
        ];

        yield 'a hierarchy' => [
            "model\n  schema 1.1\n\ntype user\n\ntype folder\n  relations\n    define owner: [user]\n"
            ."    define parent: [folder]\n    define viewer: [user] or owner or viewer from parent\n",
        ];
    }

    /**
     * An unbalanced brace inside a CEL string.
     *
     * OpenFGA's own corpus has `x == "{}"`, which balances by luck. This one
     * does not, so it separates a scanner that respects quotes from one that
     * merely counts braces - and the server is the proof that the expression
     * survived intact.
     */
    #[Test]
    public function test_an_unbalanced_brace_inside_a_string_survives(): void
    {
        $dsl = "model\n  schema 1.1\n\ntype user\n\ntype doc\n  relations\n    define viewer: [user with c]\n\n"
            ."condition c(x: string) {\n  x == \"{\"\n}\n";

        $id = $this->deploy($dsl);

        $this->assertSame(
            'x == "{"',
            $this->fga->models()->read($id)->condition('c')?->expression,
            'the server stored the expression the parser captured',
        );
    }

    #[Test]
    public function test_models_are_append_only_and_listed_newest_first(): void
    {
        $first = $this->deploy("model\n  schema 1.1\n\ntype user\n");
        $second = $this->deploy("model\n  schema 1.1\n\ntype user\n\ntype doc\n  relations\n    define viewer: [user]\n");

        $this->assertNotSame($first, $second);
        $this->assertSame($second, $this->fga->models()->latest()?->id, 'latest() is the newest');

        $ids = array_map(static fn ($m): ?string => $m->id, iterator_to_array($this->fga->models()->all()));
        $this->assertSame([$second, $first], $ids, 'and the older version is still there');
    }

    /**
     * The division of labour, proved.
     *
     * This parser does syntax; the server does semantics. This is valid DSL -
     * it parses here without complaint - and an invalid model, because a
     * relation used in a tupleset has to be directly assignable. Re-implementing
     * that rule in PHP would be a second opinion that is sometimes wrong.
     */
    #[Test]
    public function test_valid_syntax_can_still_be_an_invalid_model(): void
    {
        $dsl = "model\n  schema 1.1\n\ntype user\n\ntype doc\n  relations\n"
            ."    define a: [user]\n    define b: a\n    define c: a from b\n";

        // Parses here - and the tupleset is read, which is the part the
        // server then objects to.
        $parsed = Dsl::parse($dsl)->type('doc')?->relation('c')?->userset;
        $this->assertInstanceOf(\Piston\OpenFga\Dsl\Ast\TupleToUserset::class, $parsed);
        $this->assertSame('b', $parsed->tuplesetRelation);

        // And is refused there, with the reason.
        try {
            $this->fga->models()->write($dsl);
            $this->fail('the server should have refused this model');
        } catch (ValidationError $e) {
            $this->assertSame('invalid_authorization_model', $e->errorCode());
            $this->assertStringContainsString('tupleset', (string) $e->apiMessage());
        }
    }

    #[Test]
    public function test_an_unknown_condition_is_refused_by_the_server(): void
    {
        $this->expectException(ValidationError::class);

        $this->fga->models()->write(
            "model\n  schema 1.1\n\ntype user\n\ntype doc\n  relations\n    define viewer: [user with nope]\n",
        );
    }

    /** @param array<string, mixed> $model */
    private static function normalise(array $model): string
    {
        /** @var mixed $decoded */
        $decoded = json_decode((string) json_encode($model), true);

        return (string) json_encode(self::sort($decoded), JSON_PRETTY_PRINT);
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
