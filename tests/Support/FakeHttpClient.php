<?php

declare(strict_types=1);

namespace Piston\OpenFga\Tests\Support;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that answers from a queue and keeps what it was asked.
 *
 * The live suite proves this SDK against a real OpenFGA; this proves the
 * things a real server cannot be made to do on demand - a 500, a body that is
 * not JSON, a stream that stops halfway - and asserts the exact bytes of every
 * request, which a live test can only do by inference.
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface|ClientExceptionInterface> */
    private array $queue = [];

    /** @param array<string, string> $headers */
    public static function replying(string $body, int $status = 200, array $headers = []): self
    {
        $client = new self;
        $client->queue[] = new Response($status, ['Content-Type' => 'application/json', ...$headers], $body);

        return $client;
    }

    /** @param list<array{0: string, 1: int}> $replies */
    public static function replyingInTurn(array $replies): self
    {
        $client = new self;

        foreach ($replies as [$body, $status]) {
            $client->queue[] = new Response($status, ['Content-Type' => 'application/json'], $body);
        }

        return $client;
    }

    /** A newline-delimited stream, as streamed-list-objects sends. */
    public static function streaming(string ...$lines): self
    {
        $client = new self;
        $client->queue[] = new Response(200, ['Content-Type' => 'application/json'], implode("\n", $lines)."\n");

        return $client;
    }

    public static function failing(ClientExceptionInterface $exception): self
    {
        $client = new self;
        $client->queue[] = $exception;

        return $client;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $next = array_shift($this->queue);

        if ($next instanceof ClientExceptionInterface) {
            throw $next;
        }

        return $next ?? new Response(200, [], '{}');
    }

    public function lastRequest(): RequestInterface
    {
        return $this->requests[count($this->requests) - 1];
    }

    public function lastUrl(): string
    {
        return (string) $this->lastRequest()->getUri();
    }

    /** @return array<string, mixed> */
    public function lastBody(): array
    {
        $decoded = json_decode((string) $this->lastRequest()->getBody(), true);

        /** @var array<string, mixed> $decoded */
        return is_array($decoded) ? $decoded : [];
    }

    public function callCount(): int
    {
        return count($this->requests);
    }
}
