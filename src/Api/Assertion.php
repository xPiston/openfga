<?php

declare(strict_types=1);

namespace Piston\OpenFga\Api;

use Piston\OpenFga\Exception\InvalidArgument;
use Piston\OpenFga\Model\TupleKey;
use Piston\OpenFga\Model\TypedObject;
use Piston\OpenFga\Model\User;

/**
 * "This check should answer yes" - a test for a model, stored with it.
 *
 * Assertions are OpenFGA's own way of testing a model, and they live in the
 * server beside the model version they describe. Writing them from a
 * deployment script means a model change that breaks an expectation is caught
 * where the model is deployed rather than where it is used.
 *
 * Note the shape difference, which is easy to get wrong: here
 * `contextual_tuples` is a flat array capped at twenty, while a `check` wraps
 * the same thing in `{"tuple_keys": [...]}`. Two names for one idea in one
 * API.
 */
final class Assertion
{
    /** @var list<TupleKey> */
    public readonly array $contextualTuples;

    /** @var array<string, mixed> */
    public readonly array $context;

    /** Twenty, per the API definition. */
    public const MAX_CONTEXTUAL_TUPLES = 20;

    /**
     * @param  list<TupleKey>  $contextualTuples
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly TupleKey $key,
        public readonly bool $expectation,
        array $contextualTuples = [],
        array $context = [],
    ) {
        if (count($contextualTuples) > self::MAX_CONTEXTUAL_TUPLES) {
            throw InvalidArgument::because(
                'an assertion',
                sprintf('carries more than %d contextual tuples, which the API caps', self::MAX_CONTEXTUAL_TUPLES),
                (string) count($contextualTuples),
            );
        }

        $this->contextualTuples = $contextualTuples;
        $this->context = $context;
    }

    public static function allows(User|string $user, string $relation, TypedObject|string $object): self
    {
        return new self(TupleKey::of($user, $relation, $object), true);
    }

    public static function denies(User|string $user, string $relation, TypedObject|string $object): self
    {
        return new self(TupleKey::of($user, $relation, $object), false);
    }

    /** @param array<string, mixed> $json */
    public static function fromArray(array $json): self
    {
        $key = $json['tuple_key'] ?? [];

        /** @var array<string, mixed> $key */
        $key = is_array($key) ? $key : [];

        $contextual = [];
        $raw = $json['contextual_tuples'] ?? [];

        if (is_array($raw)) {
            foreach ($raw as $tuple) {
                if (! is_array($tuple)) {
                    continue;
                }

                /** @var array<string, mixed> $tuple */
                $contextual[] = TupleKey::fromArray($tuple);
            }
        }

        $context = $json['context'] ?? [];

        /** @var array<string, mixed> $context */
        return new self(
            TupleKey::fromArray($key),
            ($json['expectation'] ?? false) === true,
            $contextual,
            is_array($context) ? $context : [],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $json = [
            // An assertion's tuple key carries no condition, so it is built
            // from the three fields rather than from TupleKey::toArray().
            'tuple_key' => [
                'user' => $this->key->user->value(),
                'relation' => $this->key->relation,
                'object' => $this->key->object->value(),
            ],
            'expectation' => $this->expectation,
        ];

        if ($this->contextualTuples !== []) {
            $json['contextual_tuples'] = array_map(
                static fn (TupleKey $t): array => $t->toArray(),
                $this->contextualTuples,
            );
        }

        if ($this->context !== []) {
            $json['context'] = $this->context;
        }

        return $json;
    }

    public function __toString(): string
    {
        return sprintf('%s should be %s', (string) $this->key, $this->expectation ? 'allowed' : 'denied');
    }
}
