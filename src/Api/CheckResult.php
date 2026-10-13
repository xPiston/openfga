<?php

declare(strict_types=1);

namespace Piston\OpenFga\Api;

/**
 * The answer to one check.
 *
 * `allowed` is a bool and not nullable, because "we could not tell" is not one
 * of the answers OpenFGA gives: either the call failed, in which case there is
 * an exception, or it answered. A library that returned null here would invite
 * `if (! $result->allowed)` to mean "deny" for a failure it should have
 * noticed, which is the quiet way to fail open.
 */
final class CheckResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly ?string $resolution = null,
        public readonly ?string $correlationId = null,
    ) {}

    /** @param array<string, mixed> $json */
    public static function fromArray(array $json, ?string $correlationId = null): self
    {
        $allowed = $json['allowed'] ?? false;
        $resolution = $json['resolution'] ?? null;

        return new self(
            $allowed === true,
            is_string($resolution) && $resolution !== '' ? $resolution : null,
            $correlationId,
        );
    }

    public function __toString(): string
    {
        return $this->allowed ? 'allowed' : 'denied';
    }
}
