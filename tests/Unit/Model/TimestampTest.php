<?php

declare(strict_types=1);

namespace Piston\OpenFga\Tests\Unit\Model;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Piston\OpenFga\Model\Timestamp;

final class TimestampTest extends TestCase
{
    /**
     * The format a real server actually sends.
     *
     * OpenFGA is written in Go, and Go emits as many fractional digits as it
     * has - nine. PHP's `u` specifier means six, and `createFromFormat` with
     * `.u` returns false on nine, which would have made every tuple timestamp
     * this library reads silently null. Found by feeding this a value a live
     * server had just produced, which is why the value is here verbatim.
     */
    #[Test]
    public function test_the_nanoseconds_a_real_server_sends_are_parsed(): void
    {
        $parsed = Timestamp::parse('2026-10-13T09:12:59.667005883Z');

        $this->assertNotNull($parsed);
        $this->assertSame('2026-10-13 09:12:59.667005', $parsed->format('Y-m-d H:i:s.u'));
    }

    #[Test]
    #[DataProvider('acceptable')]
    public function test_the_shapes_openfga_sends(string $value, string $expected): void
    {
        $this->assertSame($expected, Timestamp::parse($value)?->format('Y-m-d H:i:s.u P'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function acceptable(): iterable
    {
        yield 'nanoseconds' => ['2026-10-13T09:12:59.667005883Z', '2026-10-13 09:12:59.667005 +00:00'];
        yield 'microseconds' => ['2026-10-13T09:12:59.667005Z', '2026-10-13 09:12:59.667005 +00:00'];
        yield 'milliseconds' => ['2026-10-13T09:12:59.667Z', '2026-10-13 09:12:59.667000 +00:00'];
        yield 'no fraction' => ['2026-10-13T09:12:59Z', '2026-10-13 09:12:59.000000 +00:00'];
        yield 'an offset' => ['2026-10-13T11:12:59.5+02:00', '2026-10-13 11:12:59.500000 +02:00'];
        yield 'an offset without a colon' => ['2026-10-13T11:12:59+0200', '2026-10-13 11:12:59.000000 +02:00'];
        yield 'a lowercase z' => ['2026-10-13T09:12:59z', '2026-10-13 09:12:59.000000 +00:00'];
    }

    /**
     * `new DateTimeImmutable($value)` accepts all of these and is wrong to.
     *
     * A field that should hold a timestamp and holds "tomorrow" instead is a
     * bug somewhere; parsing it successfully hides the bug and produces a
     * plausible wrong date.
     */
    #[Test]
    #[DataProvider('refusable')]
    public function test_what_a_timestamp_is_not(string $value): void
    {
        $this->assertNull(Timestamp::parse($value));
    }

    /** @return iterable<string, array{string}> */
    public static function refusable(): iterable
    {
        yield 'a relative date' => ['tomorrow'];
        yield 'now' => ['now'];
        yield 'a week away' => ['+1 week'];
        yield 'empty' => [''];
        yield 'whitespace' => ['   '];
        yield 'a date alone' => ['2026-10-13'];
        yield 'no zone' => ['2026-10-13T09:12:59'];
        yield 'month 13' => ['2026-13-01T00:00:00Z'];
        yield 'hour 99' => ['2026-10-13T99:00:00Z'];
        yield '30 February' => ['2026-02-30T00:00:00Z'];
        yield 'all zeroes' => ['0000-00-00T00:00:00Z'];
    }

    /**
     * The overflow case is the one worth naming.
     *
     * createFromFormat rolls an impossible date over rather than refusing it -
     * month 13 becomes January of the next year - so the parsed value is
     * written back out and compared. Only a value that survives the round trip
     * is the value that was sent.
     */
    #[Test]
    public function test_an_impossible_date_is_refused_rather_than_rolled_over(): void
    {
        $this->assertNull(Timestamp::parse('2026-13-45T99:99:99Z'));
    }

    #[Test]
    public function test_null_in_null_out(): void
    {
        $this->assertNull(Timestamp::parse(null));
    }
}
