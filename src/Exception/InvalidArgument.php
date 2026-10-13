<?php

declare(strict_types=1);

namespace Piston\OpenFga\Exception;

use InvalidArgumentException;

/**
 * Your mistake, caught before a request was made.
 *
 * The message always names the value that was wrong, because "invalid tuple"
 * sends somebody reading their own code and "object must be type:id, got
 * 'document'" sends them to the line.
 */
final class InvalidArgument extends InvalidArgumentException implements OpenFgaException
{
    public static function because(string $subject, string $problem, ?string $value = null): self
    {
        $message = sprintf('%s %s', $subject, $problem);

        if ($value !== null) {
            $message .= sprintf(' (got %s)', $value === '' ? 'an empty string' : '"'.$value.'"');
        }

        return new self($message);
    }
}
