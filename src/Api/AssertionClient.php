<?php

declare(strict_types=1);

namespace Piston\OpenFga\Api;

use Piston\OpenFga\Exception\InvalidArgument;
use Piston\OpenFga\Http\Client;
use Piston\OpenFga\Http\Request;

/**
 * Assertions, stored against a model version.
 *
 * Writing them **replaces** the whole set for that model - there is no way to
 * add one - which is the right shape for a deployment that declares the lot
 * and the wrong shape for anything incremental.
 */
final class AssertionClient
{
    public function __construct(
        private readonly Client $client,
        private readonly ?string $modelId = null,
    ) {}

    public function withModel(string $modelId): self
    {
        return new self($this->client, $modelId);
    }

    /**
     * Replace the assertions for a model version.
     *
     * @param  list<Assertion>  $assertions
     */
    public function write(array $assertions, ?string $modelId = null): void
    {
        $model = $this->requireModel($modelId);

        $this->client->send(Request::put(
            'assertions/'.rawurlencode($model),
            ['assertions' => array_map(static fn (Assertion $a): array => $a->toArray(), array_values($assertions))],
        ));
    }

    /** @return list<Assertion> */
    public function read(?string $modelId = null): array
    {
        $model = $this->requireModel($modelId);

        $reply = $this->client->send(Request::get('assertions/'.rawurlencode($model)));

        $assertions = [];

        foreach ($reply->array('assertions') as $assertion) {
            if (! is_array($assertion)) {
                continue;
            }

            /** @var array<string, mixed> $assertion */
            $assertions[] = Assertion::fromArray($assertion);
        }

        return $assertions;
    }

    private function requireModel(?string $modelId): string
    {
        $model = $modelId ?? $this->modelId;

        if ($model === null || trim($model) === '') {
            throw InvalidArgument::because(
                'assertions',
                'belong to one authorization model version, so an id is required - pass one here or '
                .'configure a default',
            );
        }

        return trim($model);
    }
}
