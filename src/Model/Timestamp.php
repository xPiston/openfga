<?php

declare(strict_types=1);

namespace Piston\OpenFga\Model;

use DateTimeImmutable;

/**
 * Parses the timestamps OpenFGA sends, and nothing else.
 *
 * Two reasons this is a class rather than a call to `new DateTimeImmutable`.
 *
 * **The server sends nanoseconds.** OpenFGA is written in Go, and Go's RFC
 * 3339 marshalling emits as many fractional digits as it has - so a real
 * response carries `2026-10-13T09:12:59.667005883Z`, nine digits. PHP's `u`
 * format specifier means microseconds, six, and
 * `createFromFormat('Y-m-d\TH:i:s.uP', ...)` returns false on nine. That is a
 * silent null in every tuple this library reads, and it was found by feeding
 * this parser a timestamp a live server had just produced.
 *
 * **`new DateTimeImmutable($value)` is too generous.** It accepts
 * "tomorrow", "+1 week" and "now" - so a field that was supposed to hold a
 * timestamp and instead holds anything at all would parse successfully and be
 * wrong. Anything that is not one of the shapes below yields null.
 */
final class Timestamp
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function parse(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        // Date, time, optional fraction of any length, then Z or an offset.
        if (preg_match(
            '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d+))?(Z|[+-]\d{2}:?\d{2})$/i',
            $trimmed,
            $matches,
        ) !== 1) {
            return null;
        }

        [, $datetime] = $matches;
        $fraction = $matches[2];
        $zone = strtoupper($matches[3]) === 'Z' ? '+00:00' : $matches[3];

        // Go emits up to nine digits; PHP understands six. Truncating rather
        // than rounding, because a timestamp is a point and not a quantity.
        $microseconds = str_pad(substr($fraction, 0, 6), 6, '0');

        $parsed = DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i:s.uP',
            sprintf('%s.%s%s', $datetime, $microseconds, $zone),
        );

        if (! $parsed instanceof DateTimeImmutable) {
            return null;
        }

        // createFromFormat happily rolls an impossible date over: month 13
        // becomes January of the next year, and hour 99 becomes four days
        // later. Both parse, and both are a wrong answer rather than a
        // refusal - so the result is written back out and compared. Only a
        // value that survives the round trip is the value that was sent.
        if ($parsed->format('Y-m-d\TH:i:s') !== $datetime) {
            return null;
        }

        return $parsed;
    }
}
