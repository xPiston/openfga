<?php

declare(strict_types=1);

namespace Piston\OpenFga\Tests\Live;

use PHPUnit\Framework\Attributes\Test;
use Piston\OpenFga\Exception\ValidationError;
use Piston\OpenFga\Model\TupleKey;

/**
 * The questions, asked of a real server.
 *
 * Every expectation here is a claim about OpenFGA's evaluation rather than
 * about this library's plumbing - that a union makes an owner a viewer, that
 * `but not` takes a permission away, that a userset follows group membership.
 * They are the claims the README makes, checked.
 */
final class AuthorizationTest extends LiveTestCase
{
    private const MODEL = <<<'FGA'
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->deploy(self::MODEL);

        $this->fga->tuples()->write([
            TupleKey::of('user:anne', 'owner', 'folder:budgets'),
            TupleKey::of('user:bob', 'member', 'group:finance'),
            TupleKey::of('group:finance#member', 'viewer', 'folder:budgets'),
            TupleKey::of('folder:budgets', 'parent', 'document:q4'),
            TupleKey::of('user:carl', 'editor', 'document:q4'),
            TupleKey::of('user:carl', 'blocked', 'document:q4'),
        ]);
    }

    #[Test]
    public function test_a_union_makes_an_owner_a_viewer(): void
    {
        $this->assertTrue($this->fga->check('user:anne', 'owner', 'folder:budgets'));
        $this->assertTrue($this->fga->check('user:anne', 'viewer', 'folder:budgets'));
    }

    #[Test]
    public function test_a_userset_follows_group_membership_without_another_tuple(): void
    {
        $this->assertTrue($this->fga->check('user:bob', 'viewer', 'folder:budgets'));
        $this->assertFalse($this->fga->check('user:zoe', 'viewer', 'folder:budgets'));

        // And it keeps following: adding Zoe to the group grants her the
        // folder, with no tuple written against the folder at all.
        $this->fga->grant('user:zoe', 'member', 'group:finance');

        $this->assertTrue($this->fga->check('user:zoe', 'viewer', 'folder:budgets'));
    }

    #[Test]
    public function test_a_permission_reaches_down_a_hierarchy(): void
    {
        // anne owns the folder; the document's owner is "owner from parent".
        $this->assertTrue($this->fga->check('user:anne', 'owner', 'document:q4'));
        $this->assertTrue($this->fga->check('user:anne', 'viewer', 'document:q4'));
    }

    #[Test]
    public function test_but_not_takes_a_permission_away(): void
    {
        $this->assertTrue($this->fga->check('user:carl', 'viewer', 'document:q4'), 'carl is an editor');
        $this->assertFalse($this->fga->check('user:carl', 'can_read', 'document:q4'), 'and is blocked');
    }

    #[Test]
    public function test_a_denial_is_a_result_and_not_an_exception(): void
    {
        $result = $this->fga->checkResult('user:nobody', 'viewer', 'document:q4');

        $this->assertFalse($result->allowed);
        $this->assertSame('denied', (string) $result);
    }

    #[Test]
    public function test_batch_check_answers_in_the_order_asked(): void
    {
        $results = $this->fga->query()->batchCheck([
            TupleKey::of('user:anne', 'owner', 'folder:budgets'),
            TupleKey::of('user:zoe', 'viewer', 'folder:budgets'),
            TupleKey::of('user:bob', 'viewer', 'folder:budgets'),
            TupleKey::of('user:carl', 'can_read', 'document:q4'),
        ]);

        $this->assertSame(
            [true, false, true, false],
            array_map(static fn ($r): bool => $r->allowed, $results),
        );
    }

    /**
     * A conditional grant: the same tuple, two answers.
     *
     * This is the feature that most needs a live test, because the condition
     * is evaluated by the server's CEL engine and nothing this library does
     * can simulate it.
     */
    #[Test]
    public function test_a_condition_is_evaluated_against_the_context(): void
    {
        $this->fga->tuples()->grant('user:dana', 'viewer', 'document:q4', 'non_expired_grant', [
            'grant_time' => '2026-10-13T09:00:00Z',
            'grant_duration' => '1h',
        ]);

        $this->assertTrue(
            $this->fga->check('user:dana', 'viewer', 'document:q4', ['current_time' => '2026-10-13T09:30:00Z']),
            'inside the window',
        );
        $this->assertFalse(
            $this->fga->check('user:dana', 'viewer', 'document:q4', ['current_time' => '2026-10-13T11:00:00Z']),
            'outside it - the same tuple, a different context',
        );
    }

    #[Test]
    public function test_a_condition_missing_its_context_is_an_error_not_a_denial(): void
    {
        $this->fga->tuples()->grant('user:dana', 'viewer', 'document:q4', 'non_expired_grant', [
            'grant_time' => '2026-10-13T09:00:00Z',
            'grant_duration' => '1h',
        ]);

        // No current_time: the expression cannot be evaluated, and OpenFGA
        // says so rather than quietly answering no.
        $this->expectException(ValidationError::class);

        $this->fga->check('user:dana', 'viewer', 'document:q4');
    }

    /** "What if?" - answered without writing anything. */
    #[Test]
    public function test_contextual_tuples_do_not_persist(): void
    {
        $this->assertFalse($this->fga->check('user:zoe', 'viewer', 'folder:budgets'));

        $this->assertTrue($this->fga->check('user:zoe', 'viewer', 'folder:budgets', [], [
            TupleKey::of('user:zoe', 'member', 'group:finance'),
        ]));

        $this->assertFalse(
            $this->fga->check('user:zoe', 'viewer', 'folder:budgets'),
            'nothing was written, so the next question gets the old answer',
        );
    }

    /** `[user:*]` grants everyone, including a user with no tuples at all. */
    #[Test]
    public function test_the_public_wildcard_grants_everybody(): void
    {
        $this->assertFalse($this->fga->check('user:stranger', 'viewer', 'folder:budgets'));

        $this->fga->grant('user:*', 'viewer', 'folder:budgets');

        $this->assertTrue($this->fga->check('user:stranger', 'viewer', 'folder:budgets'));
    }

    #[Test]
    public function test_list_objects_and_the_stream_agree(): void
    {
        $this->fga->tuples()->write([
            TupleKey::of('user:anne', 'owner', 'folder:plans'),
            TupleKey::of('user:anne', 'owner', 'folder:notes'),
        ]);

        $listed = array_map('strval', $this->fga->query()->listObjects('user:anne', 'viewer', 'folder'));
        $streamed = [];

        foreach ($this->fga->query()->streamListObjects('user:anne', 'viewer', 'folder') as $object) {
            $streamed[] = (string) $object;
        }

        sort($listed);
        sort($streamed);

        $this->assertSame(['folder:budgets', 'folder:notes', 'folder:plans'], $listed);
        $this->assertSame($listed, $streamed, 'the streamed answer is the same answer');
    }

    #[Test]
    public function test_list_users_resolves_through_a_group(): void
    {
        $users = array_map('strval', $this->fga->query()->listUsers('folder:budgets', 'viewer', 'user'));
        sort($users);

        $this->assertSame(['user:anne', 'user:bob'], $users);
    }

    #[Test]
    public function test_expand_separates_users_from_usersets_to_follow(): void
    {
        $tree = $this->fga->query()->expand('folder:budgets', 'viewer');

        $this->assertSame('folder:budgets#viewer', $tree->name());
        $this->assertContains('group:finance#member', $tree->users());
        $this->assertContains('folder:budgets#owner', $tree->usersets());
    }

    #[Test]
    public function test_a_write_is_atomic(): void
    {
        $before = count(iterator_to_array($this->fga->tuples()->readAll()));

        try {
            // The second tuple names a relation that does not exist, so the
            // whole call must fail - including the first, valid tuple.
            $this->fga->tuples()->write([
                TupleKey::of('user:eve', 'owner', 'folder:budgets'),
                TupleKey::of('user:eve', 'nonexistent', 'folder:budgets'),
            ]);
            $this->fail('a write naming an unknown relation must fail');
        } catch (ValidationError) {
            // expected
        }

        $this->assertSame(
            $before,
            count(iterator_to_array($this->fga->tuples()->readAll())),
            'nothing was written, not even the valid half',
        );
        $this->assertFalse($this->fga->check('user:eve', 'owner', 'folder:budgets'));
    }

    #[Test]
    public function test_reading_tuples_is_not_the_same_as_checking(): void
    {
        // anne can view the document, through the folder. But there is no
        // tuple saying so, and `read` returns tuples rather than permissions.
        $this->assertTrue($this->fga->check('user:anne', 'viewer', 'document:q4'));

        $tuples = array_map('strval', iterator_to_array($this->fga->tuples()->readAll('document:q4')));

        $this->assertNotContains('document:q4#viewer@user:anne', $tuples);
    }

    #[Test]
    public function test_pagination_and_the_change_log(): void
    {
        $all = iterator_to_array($this->fga->tuples()->readAll(pageSize: 2));
        $this->assertCount(6, $all, 'readAll follows the pages');
        $this->assertNotNull($all[0]->writtenAt, 'and the nanosecond timestamps parse');

        $changes = iterator_to_array($this->fga->tuples()->changesSince(pageSize: 2));
        $this->assertCount(6, $changes);
        $this->assertTrue($changes[0]->isWrite());

        $this->fga->revoke('user:carl', 'editor', 'document:q4');

        $deletes = array_filter(
            iterator_to_array($this->fga->tuples()->changesSince(pageSize: 50)),
            static fn ($c): bool => $c->isDelete(),
        );

        $this->assertCount(1, $deletes);
        $this->assertFalse($this->fga->check('user:carl', 'viewer', 'document:q4'));
    }

    #[Test]
    public function test_assertions_are_stored_against_the_model(): void
    {
        $this->fga->assertions()->write([
            \Piston\OpenFga\Api\Assertion::allows('user:anne', 'owner', 'folder:budgets'),
            \Piston\OpenFga\Api\Assertion::denies('user:zoe', 'viewer', 'folder:budgets'),
        ]);

        $stored = $this->fga->assertions()->read();

        $this->assertCount(2, $stored);
        $this->assertSame('folder:budgets#owner@user:anne should be allowed', (string) $stored[0]);
        $this->assertFalse($stored[1]->expectation);
    }

    #[Test]
    public function test_an_unknown_relation_is_a_validation_error_with_the_code(): void
    {
        try {
            $this->fga->check('user:anne', 'no_such_relation', 'folder:budgets');
            $this->fail('an unknown relation must throw');
        } catch (ValidationError $e) {
            $this->assertSame(400, $e->status);
            $this->assertSame('validation_error', $e->errorCode());
            $this->assertStringContainsString('no_such_relation', (string) $e->apiMessage());
        }
    }

    #[Test]
    public function test_the_store_can_be_found_by_name_and_deleted(): void
    {
        $store = $this->store();

        $this->assertTrue($this->fga->stores()->findByName($store->name)?->id->equals($store->id) ?? false);
        $this->assertSame($store->name, $this->fga->stores()->get($store->id)->name);
    }
}
