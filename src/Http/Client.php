<?php

declare(strict_types=1);

namespace Piston\OpenFga\Http;

use Generator;
use JsonException;
use Piston\OpenFga\Configuration\Credentials;
use Piston\OpenFga\Configuration\Endpoint;
use Piston\OpenFga\Configuration\StoreId;
use Piston\OpenFga\Exception\ApiError;
use Piston\OpenFga\Exception\InvalidArgument;
use Piston\OpenFga\Exception\NotFound;
use Piston\OpenFga\Exception\RateLimited;
use Piston\OpenFga\Exception\TransportFailed;
use Piston\OpenFga\Exception\Unauthenticated;
use Piston\OpenFga\Exception\ValidationError;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The one place that talks to OpenFGA.
 *
 * Everything above it builds a Request and reads a Reply.
 *
 * **It never retries.** Not a 429, not a 500. A check is on the request path
 * of whatever it authorises, so a retry inside this client turns one slow
 * response into three and a user-facing timeout into a user-facing timeout
 * three times as long. More to the point, a `write` is **transactional**: a
 * retried write that actually succeeded the first time comes back as a
 * duplicate-tuple error, and a client that retried it has turned a success
 * into a failure. The exception carries what the server said; the decision is
 * the caller's.
 *
 * **It never puts credentials in the URL.** Only in the header.
 *
 * **It does not pretend an error is a result.** The one thing it is careful
 * *not* to turn into an exception is `{"allowed": false}` - that is a
 * successful call with a negative answer, and conflating a denial with a
 * failure is how an application fails open.
 */
final class Client
{
    public function __construct(
        private readonly Endpoint $endpoint,
        private readonly Credentials $credentials,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly ?StoreId $store = null,
    ) {}

    public function withStore(StoreId $store): self
    {
        return new self($this->endpoint, $this->credentials, $this->http, $this->requests, $this->streams, $store);
    }

    public function store(): ?StoreId
    {
        return $this->store;
    }

    public function endpoint(): Endpoint
    {
        return $this->endpoint;
    }

    public function send(Request $request): Reply
    {
        $response = $this->dispatch($request);
        $status = $response->getStatusCode();
        $body = $this->decode($response, $request);

        if ($status >= 200 && $status < 300) {
            return new Reply($status, $body);
        }

        throw $this->error($status, $body, $request);
    }

    /**
     * A streaming endpoint, one decoded object at a time.
     *
     * `streamed-list-objects` answers with a sequence of JSON documents rather
     * than one array, so that a result set larger than memory can be read.
     * This reads it line by line off the PSR-7 stream instead of calling
     * `(string) $body`, which would defeat the point.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function stream(Request $request): Generator
    {
        $response = $this->dispatch($request);
        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            throw $this->error($status, $this->decode($response, $request), $request);
        }

        $body = $response->getBody();
        $buffer = '';

        while (! $body->eof()) {
            $chunk = $body->read(8192);

            if ($chunk === '') {
                break;
            }

            $buffer .= $chunk;

            while (($newline = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $newline);
                $buffer = substr($buffer, $newline + 1);

                $decoded = self::decodeLine($line, $status);

                if ($decoded !== null) {
                    yield $decoded;
                }
            }
        }

        $decoded = self::decodeLine($buffer, $status);

        if ($decoded !== null) {
            yield $decoded;
        }
    }

    private function dispatch(Request $request): ResponseInterface
    {
        $url = $this->url($request);

        $http = $this->requests->createRequest($request->method, $url)
            ->withHeader('Accept', 'application/json');

        $authorization = $this->credentials->authorizationHeader();

        if ($authorization !== null) {
            $http = $http->withHeader('Authorization', $authorization);
        }

        if ($request->body !== null) {
            $http = $http
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streams->createStream($this->encode($request->body)));
        }

        try {
            return $this->http->sendRequest($http);
        } catch (ClientExceptionInterface $e) {
            throw TransportFailed::unreachable($e);
        }
    }

    private function url(Request $request): string
    {
        if ($request->inStore) {
            if ($this->store === null) {
                throw InvalidArgument::because(
                    sprintf('the call to %s', $request->path),
                    'happens inside a store, and no store id is configured - pass one to the constructor '
                    .'or call stores()->create() first',
                );
            }

            $url = $this->endpoint->inStore($this->store, $request->path);
        } else {
            $url = $this->endpoint->root($request->path);
        }

        if ($request->query !== []) {
            $query = array_filter($request->query, static fn (mixed $v): bool => $v !== null && $v !== '');

            if ($query !== []) {
                $url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            }
        }

        return $url;
    }

    /** @param array<string, mixed> $body */
    private function error(int $status, array $body, Request $request): ApiError
    {
        $path = $request->path;
        $method = $request->method;

        return match (true) {
            $status === 400 || $status === 422 => ValidationError::from($status, $body, $method, $path),
            $status === 401 || $status === 403 => Unauthenticated::from($status, $body, $method, $path),
            $status === 404 => NotFound::from($status, $body, $method, $path),
            $status === 429 => RateLimited::from($status, $body, $method, $path),
            default => ApiError::from($status, $body, $method, $path),
        };
    }

    /** @param array<string, mixed> $body */
    private function encode(array $body): string
    {
        // An empty PHP array encodes to `[]` - a JSON *array* - and every
        // body in this API is an object. The server answers a bare `[]` with
        // "syntax error (line 1:1): unexpected token [", which reads like a
        // problem with a relation expression and is not. Found by an
        // unfiltered `read()`, the one call whose body is legitimately empty.
        if ($body === []) {
            return '{}';
        }

        try {
            return json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw TransportFailed::notJson(0, 'the request body could not be encoded: '.$e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function decode(ResponseInterface $response, Request $request): array
    {
        $contents = (string) $response->getBody();

        if (trim($contents) === '') {
            // DELETE answers 200 with no body, and `write` answers `{}`.
            return [];
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw TransportFailed::notJson($response->getStatusCode(), $contents);
        }

        if (! is_array($decoded)) {
            throw TransportFailed::notJson($response->getStatusCode(), $contents);
        }

        unset($request);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @return array<string, mixed>|null */
    private static function decodeLine(string $line, int $status): ?array
    {
        if (trim($line) === '') {
            return null;
        }

        try {
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw TransportFailed::notJson($status, $line);
        }

        if (! is_array($decoded)) {
            throw TransportFailed::notJson($status, $line);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
