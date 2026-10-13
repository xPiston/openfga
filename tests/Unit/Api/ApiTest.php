<?php

declare(strict_types=1);

namespace Piston\OpenFga\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Piston\OpenFga\Api\Assertion;
use Piston\OpenFga\Exception\InvalidArgument;
use Piston\OpenFga\Model\TupleKey;
use Piston\OpenFga\Tests\Support\FakeHttpClient;
use Piston\OpenFga\Tests\Support\Fga;

/**
 * The exact bytes each call sends.
 *
 * The live suite proves the server understands them; this proves they do not
 * change by accident, and pins the details the API gets inconsistently -
 * `contextual_tuples` wrapped in one endpoint and flat in another, a delete
 * that must not carry a condition, a pinned model id on every query.
 */
final class ApiTest extends TestCase
{
    #[Test]
    public function test_a_check_sends_the_tuple_and_the_pinned_model(): void
    {
        $http = FakeHttpClient::replying('{"allowed":true}');

        $allowed = Fga::with($http, model: Fga::MODEL)->check('user:anne', 'viewer', 'document:budget');

        $this->assertTrue($allowed);
        $this->assertSame([
            'tuple_key' => ['user' => 'user:anne', 'relation' => 'viewer', 'object' => 'document:budget'],
            'authorization_model_id' => Fga::MODEL,
        ], $http->lastBody());
    }

    /**
     * Without a pinned model the id is simply absent, which makes OpenFGA use
     * the newest. Allowed, and documented as the thing not to do.
     */
    #[Test]
    public function test_without_a_pinned_model_no_id_is_sent(): void
    {
        $http = FakeHttpClient::replying('{"allowed":true}');
        Fga::with($http)->check('user:anne', 'viewer', 'document:budget');

        $this->assertArrayNotHasKey('authorization_model_id', $http->lastBody());
    }

    #[Test]
    public function test_a_check_can_carry_context_and_contextual_tuples(): void
    {
        $http = FakeHttpClient::replying('{"allowed":true}');

        Fga::with($http)->check('user:anne', 'viewer', 'doc:1', ['now' => '2026-10-13T09:00:00Z'], [
            TupleKey::of('user:anne', 'member', 'group:eng'),
        ]);

        $body = $http->lastBody();
        $this->assertSame(['now' => '2026-10-13T09:00:00Z'], $body['context']);
        $this->assertSame([
            'tuple_keys' => [['user' => 'user:anne', 'relation' => 'member', 'object' => 'group:eng']],
        ], $body['contextual_tuples'], 'a check wraps contextual tuples in tuple_keys');
    }

    /**
     * OpenFGA correlates batch results by id, not by position, and the order
     * it returns them in is its own. Generating the ids here and mapping back
     * is what lets a list page trust the order it asked in.
     */
    #[Test]
    public function test_batch_check_answers_in_the_order_asked_whatever_order_the_server_replies_in(): void
    {
        // Deliberately shuffled, and c1 omitted entirely.
        $http = FakeHttpClient::replying('{"result":{"c3":{"allowed":true},"c0":{"allowed":true},"c2":{"allowed":false}}}');

        $results = Fga::with($http)->query()->batchCheck([
            TupleKey::of('user:a', 'viewer', 'doc:1'),
            TupleKey::of('user:b', 'viewer', 'doc:2'),
            TupleKey::of('user:c', 'viewer', 'doc:3'),
            TupleKey::of('user:d', 'viewer', 'doc:4'),
        ]);

        $this->assertSame(
            [true, false, false, true],
            array_map(static fn ($r): bool => $r->allowed, $results),
            'position 1 was not in the response at all and must read as a denial, not as a shifted answer',
        );
        $this->assertSame(['c0', 'c1', 'c2', 'c3'], array_map(static fn ($r): ?string => $r->correlationId, $results));
    }

    #[Test]
    public function test_an_empty_batch_makes_no_request(): void
    {
        $http = FakeHttpClient::replying('{}');

        $this->assertSame([], Fga::with($http)->query()->batchCheck([]));
        $this->assertSame(0, $http->callCount());
    }

    #[Test]
    public function test_list_users_splits_a_userset_filter(): void
    {
        $http = FakeHttpClient::replying('{"users":[{"object":{"type":"user","id":"anne"}},{"userset":{"type":"group","id":"eng","relation":"member"}},{"wildcard":{"type":"user"}}]}');

        $users = Fga::with($http)->query()->listUsers('document:budget', 'viewer', 'user');

        $this->assertSame([
            'object' => ['type' => 'document', 'id' => 'budget'],
            'relation' => 'viewer',
            'user_filters' => [['type' => 'user']],
        ], $http->lastBody());

        $this->assertSame(
            ['user:anne', 'group:eng#member', 'user:*'],
            array_map('strval', $users),
            'all three user shapes the API can return are read back',
        );
    }

    #[Test]
    public function test_list_users_without_a_filter_is_refused(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessageMatches('/needs a user filter/');

        Fga::with(FakeHttpClient::replying('{}'))->query()->listUsers('doc:1', 'viewer', '  ');
    }

    /**
     * One filter per call, because the server allows exactly one - and sends
     * exactly one item even though the field is an array.
     */
    #[Test]
    public function test_a_userset_filter_is_split_into_type_and_relation(): void
    {
        $http = FakeHttpClient::replying('{"users":[{"userset":{"type":"group","id":"eng","relation":"member"}}]}');

        $users = Fga::with($http)->query()->listUsers('document:budget', 'viewer', 'group#member');

        $this->assertSame(
            [['type' => 'group', 'relation' => 'member']],
            $http->lastBody()['user_filters'] ?? null,
        );
        $this->assertSame(['group:eng#member'], array_map('strval', $users));
    }

    #[Test]
    public function test_a_store_name_the_server_would_refuse_is_refused_here(): void
    {
        foreach (['ab', str_repeat('a', 65), 'bad*star', ''] as $name) {
            try {
                Fga::with(FakeHttpClient::replying('{}'))->stores()->create($name);
                $this->fail(sprintf('"%s" should have been refused', $name));
            } catch (InvalidArgument $e) {
                $this->assertStringContainsString('3 to 64 characters', $e->getMessage());
            }
        }
    }

    /**
     * A write is transactional. Splitting a too-large one would turn an
     * atomic change into several that can half-fail, so it is refused with
     * the reason rather than chunked.
     */
    #[Test]
    public function test_a_write_over_the_limit_is_refused_rather_than_split(): void
    {
        $http = FakeHttpClient::replying('{}');
        $keys = [];

        for ($i = 0; $i < 101; $i++) {
            $keys[] = TupleKey::of('user:u'.$i, 'viewer', 'doc:'.$i);
        }

        try {
            Fga::with($http)->tuples()->write($keys);
            $this->fail('101 tuples should be refused');
        } catch (InvalidArgument $e) {
            $this->assertStringContainsString('transactional', $e->getMessage());
            $this->assertStringContainsString('101 tuples', $e->getMessage());
            $this->assertSame(0, $http->callCount(), 'and nothing is sent');
        }
    }

    #[Test]
    public function test_the_write_limit_follows_the_server_setting(): void
    {
        $http = FakeHttpClient::replying('{}');
        $client = new \Piston\OpenFga\OpenFga(Fga::client($http), null, maxTuplesPerWrite: 2);

        $client->tuples()->write([
            TupleKey::of('user:a', 'viewer', 'doc:1'),
            TupleKey::of('user:b', 'viewer', 'doc:2'),
        ]);

        $this->assertSame(1, $http->callCount());

        $this->expectException(InvalidArgument::class);
        $client->tuples()->write([
            TupleKey::of('user:a', 'viewer', 'doc:1'),
            TupleKey::of('user:b', 'viewer', 'doc:2'),
            TupleKey::of('user:c', 'viewer', 'doc:3'),
        ]);
    }

    #[Test]
    public function test_a_write_with_nothing_in_it_is_refused(): void
    {
        $this->expectException(InvalidArgument::class);

        Fga::with(FakeHttpClient::replying('{}'))->tuples()->write([]);
    }

    /**
     * A delete identifies a tuple and carries no condition. Sending one is an
     * error rather than a filter, so the condition is stripped on the way out
     * even when the key has one.
     */
    #[Test]
    public function test_a_delete_never_carries_a_condition(): void
    {
        $http = FakeHttpClient::replying('{}');

        Fga::with($http)->tuples()->write([], [
            TupleKey::of('user:anne', 'viewer', 'doc:1')->withCondition('whatever', ['a' => 1]),
        ]);

        $this->assertSame([
            'deletes' => ['tuple_keys' => [['user' => 'user:anne', 'relation' => 'viewer', 'object' => 'doc:1']]],
        ], $http->lastBody());
    }

    #[Test]
    public function test_writes_and_deletes_travel_together(): void
    {
        $http = FakeHttpClient::replying('{}');

        Fga::with($http, model: Fga::MODEL)->tuples()->write(
            [TupleKey::of('user:anne', 'member', 'group:eng')],
            [TupleKey::of('user:bob', 'member', 'group:eng')],
        );

        $body = $http->lastBody();
        $this->assertArrayHasKey('writes', $body);
        $this->assertArrayHasKey('deletes', $body);
        $this->assertSame(Fga::MODEL, $body['authorization_model_id']);
        $this->assertSame(1, $http->callCount(), 'one call, so one transaction');
    }

    /**
     * OpenFGA signals "no more pages" with an empty string, not a missing
     * key - so `isset()` is true on the last page and a loop that trusts it
     * never ends.
     */
    #[Test]
    public function test_an_empty_continuation_token_means_no_more_pages(): void
    {
        $http = FakeHttpClient::replying('{"tuples":[],"continuation_token":""}');

        $page = Fga::with($http)->tuples()->read('doc:1');

        $this->assertFalse($page->hasMore());
        $this->assertNull($page->continuationToken);
    }

    #[Test]
    public function test_read_all_follows_the_pages_and_stops(): void
    {
        $http = FakeHttpClient::replyingInTurn([
            ['{"tuples":[{"key":{"user":"user:a","relation":"viewer","object":"doc:1"},"timestamp":"2026-10-13T09:00:00.1Z"}],"continuation_token":"t1"}', 200],
            ['{"tuples":[{"key":{"user":"user:b","relation":"viewer","object":"doc:1"}}],"continuation_token":""}', 200],
        ]);

        $tuples = iterator_to_array(Fga::with($http)->tuples()->readAll('doc:1'));

        $this->assertCount(2, $tuples);
        $this->assertSame('doc:1#viewer@user:a', (string) $tuples[0]);
        $this->assertNotNull($tuples[0]->writtenAt);
        $this->assertNull($tuples[1]->writtenAt, 'a tuple with no timestamp is not invented one');
        $this->assertSame(2, $http->callCount());
    }

    /**
     * The change log hands its final token back unchanged with no new
     * entries, which is an infinite loop for a reader that only stops on a
     * null token.
     */
    #[Test]
    public function test_changes_since_stops_when_the_log_repeats_its_token(): void
    {
        $http = FakeHttpClient::replyingInTurn([
            ['{"changes":[{"tuple_key":{"user":"user:a","relation":"viewer","object":"doc:1"},"operation":"TUPLE_OPERATION_WRITE","timestamp":"2026-10-13T09:00:00.1Z"}],"continuation_token":"t1"}', 200],
            ['{"changes":[],"continuation_token":"t1"}', 200],
        ]);

        $changes = iterator_to_array(Fga::with($http)->tuples()->changesSince());

        $this->assertCount(1, $changes);
        $this->assertTrue($changes[0]->isWrite());
        $this->assertFalse($changes[0]->isDelete());
        $this->assertSame(2, $http->callCount(), 'it asked twice and then stopped rather than looping');
    }

    #[Test]
    public function test_the_change_operation_is_readable_rather_than_a_protobuf_name(): void
    {
        $http = FakeHttpClient::replying('{"changes":[{"tuple_key":{"user":"user:a","relation":"viewer","object":"doc:1"},"operation":"TUPLE_OPERATION_DELETE"}],"continuation_token":""}');

        $changes = Fga::with($http)->tuples()->changes();

        $this->assertSame('delete', $changes->items[0]->operation);
        $this->assertTrue($changes->items[0]->isDelete());
    }

    /**
     * `write()` takes DSL text. A string starting with `{` is JSON and
     * anything else is DSL - which is not a guess: DSL cannot begin with a
     * brace and JSON cannot begin with anything else.
     */
    #[Test]
    public function test_a_model_can_be_written_as_dsl_or_as_json(): void
    {
        $dsl = "model\n  schema 1.1\n\ntype user\n\ntype doc\n  relations\n    define viewer: [user]\n";
        $expected = [
            'schema_version' => '1.1',
            'type_definitions' => [
                ['type' => 'user', 'relations' => [], 'metadata' => null],
                ['type' => 'doc', 'relations' => ['viewer' => ['this' => []]],
                    'metadata' => ['relations' => ['viewer' => ['directly_related_user_types' => [['type' => 'user']]]]]],
            ],
        ];

        $fromDsl = FakeHttpClient::replying('{"authorization_model_id":"'.Fga::MODEL.'"}');
        $id = Fga::with($fromDsl)->models()->write($dsl);
        $this->assertSame(Fga::MODEL, $id);
        $this->assertSame($expected, $fromDsl->lastBody());

        $fromJson = FakeHttpClient::replying('{"authorization_model_id":"'.Fga::MODEL.'"}');
        Fga::with($fromJson)->models()->write((string) json_encode($expected));
        $this->assertSame($expected, $fromJson->lastBody(), 'both routes send the same bytes');
    }

    /** Valid DSL, but the server refuses a model with no types - said here. */
    #[Test]
    public function test_a_model_with_no_types_is_refused_before_the_request(): void
    {
        $http = FakeHttpClient::replying('{}');

        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessageMatches('/declares no types/');

        Fga::with($http)->models()->write("model\n  schema 1.1\n");
    }

    #[Test]
    public function test_assertions_are_written_against_a_model_version(): void
    {
        $http = FakeHttpClient::replying('{}');

        Fga::with($http, model: Fga::MODEL)->assertions()->write([
            Assertion::allows('user:anne', 'viewer', 'doc:1'),
            Assertion::denies('user:bob', 'viewer', 'doc:1'),
        ]);

        $this->assertStringEndsWith('/assertions/'.Fga::MODEL, $http->lastUrl());
        $this->assertSame('PUT', $http->lastRequest()->getMethod());
        $this->assertSame([
            'assertions' => [
                ['tuple_key' => ['user' => 'user:anne', 'relation' => 'viewer', 'object' => 'doc:1'], 'expectation' => true],
                ['tuple_key' => ['user' => 'user:bob', 'relation' => 'viewer', 'object' => 'doc:1'], 'expectation' => false],
            ],
        ], $http->lastBody());
    }

    #[Test]
    public function test_assertions_without_a_model_say_which_is_missing(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessageMatches('/belong to one authorization model version/');

        Fga::with(FakeHttpClient::replying('{}'))->assertions()->read();
    }

    /** Unlike a check, an assertion's contextual tuples are a flat array. */
    #[Test]
    public function test_an_assertion_sends_contextual_tuples_flat(): void
    {
        $http = FakeHttpClient::replying('{}');

        Fga::with($http, model: Fga::MODEL)->assertions()->write([
            new Assertion(
                TupleKey::of('user:anne', 'viewer', 'doc:1'),
                true,
                [TupleKey::of('user:anne', 'member', 'group:eng')],
            ),
        ]);

        $body = $http->lastBody();
        /** @var array<int, array<string, mixed>> $assertions */
        $assertions = $body['assertions'];
        $this->assertSame(
            [['user' => 'user:anne', 'relation' => 'member', 'object' => 'group:eng']],
            $assertions[0]['contextual_tuples'],
        );
    }

    #[Test]
    public function test_an_assertion_refuses_more_contextual_tuples_than_the_api_allows(): void
    {
        $tuples = [];

        for ($i = 0; $i < 21; $i++) {
            $tuples[] = TupleKey::of('user:u'.$i, 'member', 'group:eng');
        }

        $this->expectException(InvalidArgument::class);

        new Assertion(TupleKey::of('user:anne', 'viewer', 'doc:1'), true, $tuples);
    }

    #[Test]
    public function test_a_store_name_is_not_an_id(): void
    {
        $http = FakeHttpClient::replying('{"id":"'.Fga::STORE.'","name":"production","created_at":"2026-10-13T09:00:00.667005883Z"}');

        $store = Fga::with($http)->stores()->create('production');

        $this->assertSame(Fga::STORE, $store->id->value());
        $this->assertSame('production', $store->name);
        $this->assertSame('2026-10-13', $store->createdAt?->format('Y-m-d'));
        $this->assertSame('http://openfga.test/stores', $http->lastUrl());
    }

    #[Test]
    public function test_expand_separates_users_from_the_usersets_still_to_follow(): void
    {
        // The shape a real server sends for `[user] or owner`.
        $http = FakeHttpClient::replying(json_encode(['tree' => ['root' => [
            'name' => 'folder:b#viewer',
            'union' => ['nodes' => [
                ['name' => 'folder:b#viewer', 'leaf' => ['users' => ['users' => ['user:bob']]]],
                ['name' => 'folder:b#viewer', 'leaf' => ['computed' => ['userset' => 'folder:b#owner']]],
            ]],
        ]]], JSON_THROW_ON_ERROR));

        $tree = Fga::with($http)->query()->expand('folder:b', 'viewer');

        $this->assertSame('folder:b#viewer', $tree->name());
        $this->assertSame(['user:bob'], $tree->users());
        $this->assertSame(['folder:b#owner'], $tree->usersets());
    }
}
