<?php

declare(strict_types=1);

namespace Piston\OpenFga\Exception;

/**
 * 429.
 *
 * A self-hosted OpenFGA is as fast as the machine it runs on; the managed
 * offerings meter it. Either way this library does not retry - see Client -
 * and this carries whatever the response said about when to come back.
 */
final class RateLimited extends ApiError
{
    public function retryAfterSeconds(): ?int
    {
        $value = $this->body['retry_after'] ?? null;

        return is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : null);
    }
}
