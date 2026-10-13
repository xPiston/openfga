<?php

declare(strict_types=1);

namespace Piston\OpenFga\Model;

use Piston\OpenFga\Exception\InvalidArgument;

/**
 * A condition attached to a tuple, with the context it is evaluated against.
 *
 * The condition is named in the model; the context is data written beside the
 * tuple. At check time OpenFGA merges this stored context with whatever the
 * check passes in - so a grant can carry "valid until Friday" and the check
 * only has to supply "it is now Thursday".
 */
final class RelationshipCondition
{
    /** @var array<string, mixed> */
    public readonly array $context;

    /** @param array<string, mixed> $context */
    public function __construct(public readonly string $name, array $context = [])
    {
        if (trim($name) === '') {
            throw InvalidArgument::because('a relationship condition', 'has no name');
        }

        $this->context = $context;
    }

    /** @param array<string, mixed> $json */
    public static function fromArray(array $json): ?self
    {
        $name = $json['name'] ?? null;

        // The server renders an absent condition as `{"name": ""}` rather
        // than omitting it, so an empty name means "none" and not "a
        // condition called nothing".
        if (! is_string($name) || $name === '') {
            return null;
        }

        $context = $json['context'] ?? [];

        /** @var array<string, mixed> $context */
        return new self($name, is_array($context) ? $context : []);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $json = ['name' => $this->name];

        if ($this->context !== []) {
            $json['context'] = $this->context;
        }

        return $json;
    }
}
