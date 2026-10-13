<?php

declare(strict_types=1);

namespace Piston\OpenFga\Configuration;

use DateTimeImmutable;
use SensitiveParameter;

/**
 * An OAuth2 access token and the moment it stops working.
 *
 * `isUsable()` takes a buffer, and the buffer is the point. A token that
 * expires in two seconds passes a naive `expiresAt > now` and then fails in
 * flight, because the check and the request are not the same instant. Asking
 * for it slightly early costs one extra exchange an hour and removes a class
 * of intermittent 401 that is painful to reproduce.
 */
final class AccessToken
{
    public function __construct(
        #[SensitiveParameter] public readonly string $value,
        public readonly ?DateTimeImmutable $expiresAt,
    ) {}

    public function isUsable(DateTimeImmutable $now, int $bufferInSeconds = 0): bool
    {
        if ($this->value === '') {
            return false;
        }

        if ($this->expiresAt === null) {
            // An issuer that sends no expires_in is saying it does not know.
            // Trusting it forever is the only option that is not a guess.
            return true;
        }

        return $this->expiresAt->getTimestamp() - $bufferInSeconds > $now->getTimestamp();
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return [
            'value' => '***redacted***',
            'expiresAt' => $this->expiresAt?->format(DATE_ATOM) ?? 'unknown',
        ];
    }
}
