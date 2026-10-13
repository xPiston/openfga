<?php

declare(strict_types=1);

namespace Piston\OpenFga\Configuration;

use DateTimeImmutable;

/**
 * OAuth2 client credentials: what a managed OpenFGA wants.
 *
 * It holds the token between calls, and refreshes it a little before it
 * expires rather than after - see AccessToken. The early margin carries a
 * random jitter on purpose: a fleet of workers that all started together would
 * otherwise all refresh in the same second, which turns a token expiry into a
 * burst at the issuer. A few seconds of spread costs nothing and removes it.
 */
final class ClientCredentials implements Credentials
{
    /** Ask for a new token this long before the old one dies. */
    public const REFRESH_BUFFER = 30;

    /** Spread that margin over this many seconds, per instance. */
    public const REFRESH_JITTER = 30;

    private ?AccessToken $token = null;

    private readonly int $buffer;

    public function __construct(private readonly TokenIssuer $issuer, ?int $buffer = null)
    {
        $this->buffer = $buffer ?? self::REFRESH_BUFFER + random_int(0, self::REFRESH_JITTER);
    }

    public function authorizationHeader(): string
    {
        return 'Bearer '.$this->accessToken()->value;
    }

    public function accessToken(?DateTimeImmutable $now = null): AccessToken
    {
        $now ??= new DateTimeImmutable;

        if ($this->token !== null && $this->token->isUsable($now, $this->buffer)) {
            return $this->token;
        }

        return $this->token = $this->issuer->fetch();
    }

    /** Forget the cached token; the next call fetches a new one. */
    public function forget(): void
    {
        $this->token = null;
    }
}
