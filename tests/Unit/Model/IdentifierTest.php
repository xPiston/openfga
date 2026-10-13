<?php

declare(strict_types=1);

namespace Piston\OpenFga\Tests\Unit\Model;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Piston\OpenFga\Exception\InvalidArgument;
use Piston\OpenFga\Model\TupleKey;
use Piston\OpenFga\Model\TypedObject;
use Piston\OpenFga\Model\User;

/**
 * Objects and users are different types because OpenFGA treats them
 * differently, and these are the differences - each one measured against a
 * real server rather than read off a page.
 */
final class IdentifierTest extends TestCase
{
    #[Test]
    public function test_an_object_is_a_type_and_an_id(): void
    {
        $object = TypedObject::parse('document:budget-2026');

        $this->assertSame('document', $object->type);
        $this->assertSame('budget-2026', $object->id);
        $this->assertSame('document:budget-2026', (string) $object);
        $this->assertTrue($object->equals(TypedObject::of('document', 'budget-2026')));
    }

    /**
     * The ids a live server accepts. Measured, not guessed - including the
     * comma, which OpenFGA's own DSL test corpus calls invalid and which the
     * tuple API accepts. A client stricter than its server refuses writes the
     * server would have taken, so the server wins.
     */
    #[Test]
    #[DataProvider('acceptableIds')]
    public function test_an_id_the_server_accepts_is_not_refused_here(string $id): void
    {
        $this->assertSame('doc:'.$id, TypedObject::parse('doc:'.$id)->value());
    }

    /** @return iterable<string, array{string}> */
    public static function acceptableIds(): iterable
    {
        yield 'alphanumeric' => ['document1'];
        yield 'underscore' => ['doc_123'];
        yield 'email-like' => ['user@domain.com'];
        yield 'dot' => ['file.name'];
        yield 'plus' => ['data+set'];
        yield 'pipe' => ['pipe|char'];
        yield 'hyphen' => ['dash-char'];
        yield 'slash' => ['slash/char'];
        yield 'a star, not leading' => ['doc*123'];
        yield 'a comma - which the server takes' => ['a,b'];
        yield 'a ULID' => ['01M4T8JQ0KTTDRR0CGQZ3R2RPE'];
    }

    #[Test]
    #[DataProvider('unacceptableObjects')]
    public function test_a_malformed_object_is_refused_before_any_request(string $value, string $why): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessageMatches($why);

        TypedObject::parse($value);
    }

    /** @return iterable<string, array{string, string}> */
    public static function unacceptableObjects(): iterable
    {
        yield 'no colon' => ['document', '/type:id/'];
        yield 'two colons' => ['doc:x:y', '/exactly one/'];
        yield 'no id' => ['doc:', '/empty/'];
        yield 'whitespace in the id' => ['doc:a b', '/whitespace/'];
        yield 'a hash in the id' => ['doc:a#b', '/#/'];
        yield 'empty' => ['', '/empty/'];
    }

    /**
     * The asymmetry worth a type for.
     *
     * `user:*` is a perfectly good user - it is how a relation is made public.
     * `document:*` is refused by the server: "the 'object' field cannot
     * reference a typed wildcard". One Identifier class for both fields
     * cannot say that.
     */
    #[Test]
    public function test_the_wildcard_is_a_user_but_never_an_object(): void
    {
        $everyone = User::parse('user:*');

        $this->assertTrue($everyone->isEveryone());
        $this->assertSame('user:*', $everyone->value());

        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessageMatches('/never as an object/');

        TypedObject::parse('document:*');
    }

    #[Test]
    public function test_a_user_can_be_a_userset(): void
    {
        $userset = User::parse('group:engineering#member');

        $this->assertTrue($userset->isUserset());
        $this->assertSame('group', $userset->type);
        $this->assertSame('engineering', $userset->id);
        $this->assertSame('member', $userset->relation);
        $this->assertSame('group:engineering#member', $userset->value());
    }

    /** The server refuses this one; so does the parser, with the reason. */
    #[Test]
    public function test_a_wildcard_userset_is_not_a_thing(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessageMatches('/wildcard userset/');

        User::parse('user:*#member');
    }

    #[Test]
    #[DataProvider('unacceptableUsers')]
    public function test_a_malformed_user_is_refused(string $value): void
    {
        $this->expectException(InvalidArgument::class);

        User::parse($value);
    }

    /** @return iterable<string, array{string}> */
    public static function unacceptableUsers(): iterable
    {
        yield 'no type' => ['anne'];
        yield 'a bare star' => ['*'];
        yield 'no id' => ['user:'];
        yield 'whitespace' => ['user:an ne'];
        yield 'an empty userset' => ['group:eng#'];
        yield 'two hashes' => ['group:eng#a#b'];
        yield 'empty' => [''];
    }

    /**
     * A tuple prints the way OpenFGA prints it.
     *
     * Object, relation, user - which is the order in the server's error
     * messages, so a log line from here and one from OpenFGA can be compared
     * without translating.
     */
    #[Test]
    public function test_a_tuple_reads_the_way_the_server_writes_it(): void
    {
        $key = TupleKey::of('user:anne', 'viewer', 'document:budget');

        $this->assertSame('document:budget#viewer@user:anne', (string) $key);
        $this->assertSame(
            ['user' => 'user:anne', 'relation' => 'viewer', 'object' => 'document:budget'],
            $key->toArray(),
        );
    }

    #[Test]
    public function test_a_tuple_can_carry_a_condition_and_its_context(): void
    {
        $key = TupleKey::of('user:anne', 'viewer', 'document:budget')
            ->withCondition('non_expired_grant', ['grant_duration' => '1h']);

        $this->assertSame([
            'user' => 'user:anne',
            'relation' => 'viewer',
            'object' => 'document:budget',
            'condition' => ['name' => 'non_expired_grant', 'context' => ['grant_duration' => '1h']],
        ], $key->toArray());
    }

    /**
     * The server renders "no condition" as `null` on a read and as
     * `{"name": ""}` elsewhere. Neither is a condition called nothing.
     */
    #[Test]
    public function test_an_absent_condition_is_absent_however_the_server_spells_it(): void
    {
        $fromNull = TupleKey::fromArray([
            'user' => 'user:anne', 'relation' => 'viewer', 'object' => 'doc:1', 'condition' => null,
        ]);
        $fromEmptyName = TupleKey::fromArray([
            'user' => 'user:anne', 'relation' => 'viewer', 'object' => 'doc:1', 'condition' => ['name' => ''],
        ]);

        $this->assertNull($fromNull->condition);
        $this->assertNull($fromEmptyName->condition);
    }

    #[Test]
    public function test_a_relation_name_is_checked_before_a_request_is_made(): void
    {
        $this->expectException(InvalidArgument::class);

        TupleKey::of('user:anne', 'not a relation', 'doc:1');
    }
}
