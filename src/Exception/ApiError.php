<?php

declare(strict_types=1);

namespace Piston\OpenFga\Exception;

use RuntimeException;

/**
 * OpenFGA answered, and the answer was a refusal.
 *
 * It keeps the status and the decoded body, because OpenFGA's errors are
 * specific and machine-readable - every one carries a `code` from a documented
 * enumeration and a human `message` - and an SDK that flattened that to
 * "request failed" would throw away the only part worth logging.
 */
class ApiError extends RuntimeException implements OpenFgaException
{
    /** @param array<string, mixed> $body */
    final public function __construct(
        public readonly int $status,
        public readonly array $body,
        public readonly string $method,
        public readonly string $path,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * Build the right subclass.
     *
     * `new static` and not `new self`: in a static method on the parent,
     * `self` is this class, so `ValidationError::from()` would hand back a
     * plain ApiError and every `catch (ValidationError)` in every application
     * would be dead code. Found by catching a real 400 from a real server and
     * noticing the catch block did not fire.
     *
     * @param  array<string, mixed>  $body
     */
    public static function from(int $status, array $body, string $method, string $path): static
    {
        $message = sprintf('%s %s answered %d', $method, $path, $status);
        $detail = self::describe($body);

        if ($detail !== null) {
            $message .= ': '.$detail;
        }

        return new static($status, $body, $method, $path, $message);
    }

    /**
     * OpenFGA's own error code, such as `validation_error` or
     * `type_not_found`. Worth switching on; worth logging either way.
     */
    public function errorCode(): ?string
    {
        foreach (['code', 'error_code'] as $key) {
            $value = $this->body[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    public function apiMessage(): ?string
    {
        $message = $this->body['message'] ?? null;

        return is_string($message) && $message !== '' ? $message : null;
    }

    /** @param array<string, mixed> $body */
    private static function describe(array $body): ?string
    {
        $code = null;

        foreach (['code', 'error_code'] as $key) {
            $value = $body[$key] ?? null;

            if (is_string($value) && $value !== '') {
                $code = $value;
                break;
            }
        }

        $message = $body['message'] ?? null;
        $message = is_string($message) && $message !== '' ? $message : null;

        return match (true) {
            $code !== null && $message !== null => sprintf('%s - %s', $code, $message),
            $message !== null => $message,
            $code !== null => $code,
            default => null,
        };
    }
}
