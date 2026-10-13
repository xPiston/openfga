<?php

declare(strict_types=1);

namespace Piston\OpenFga\Configuration;

use Piston\OpenFga\Exception\InvalidArgument;
use SensitiveParameter;

/**
 * The key a server started with `--authn-method=preshared` expects.
 *
 * Sent as `Authorization: Bearer <key>`. The constructor refuses a key pasted
 * with the prefix already on it, because `Bearer Bearer abc` produces a 401
 * that reads exactly like a wrong key and costs an afternoon.
 */
final class PreSharedKey implements Credentials
{
    private function __construct(#[SensitiveParameter] private readonly string $key) {}

    public static function of(#[SensitiveParameter] string $key): self
    {
        $trimmed = trim($key);

        if ($trimmed === '') {
            throw InvalidArgument::because('the pre-shared key', 'is empty');
        }

        if (preg_match('/^bearer\s/i', $trimmed) === 1) {
            throw InvalidArgument::because(
                'the pre-shared key',
                'already carries a "Bearer " prefix - pass the key alone, this class adds the prefix',
            );
        }

        return new self($trimmed);
    }

    public function authorizationHeader(): string
    {
        return 'Bearer '.$this->key;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['key' => '***redacted***'];
    }
}
