<?php

declare(strict_types=1);

namespace Piston\OpenFga\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Piston\OpenFga\Configuration\NoCredentials;
use Piston\OpenFga\Configuration\PreSharedKey;
use Piston\OpenFga\Exception\ApiError;
use Piston\OpenFga\Exception\InvalidArgument;
use Piston\OpenFga\Exception\NotFound;
use Piston\OpenFga\Exception\RateLimited;
use Piston\OpenFga\Exception\TransportFailed;
use Piston\OpenFga\Exception\Unauthenticated;
use Piston\OpenFga\Exception\ValidationError;
use Piston\OpenFga\Http\Request;
use Piston\OpenFga\Tests\Support\FakeHttpClient;
use Piston\OpenFga\Tests\Support\Fga;
use Piston\OpenFga\Tests\Support\TransportFailure;

final class ClientTest extends TestCase
{
    #[Test]
    public function test_a_store_path_and_a_root_path_go_to_different_places(): void
    {
        $http = FakeHttpClient::replyingInTurn([['{}', 200], ['{}', 200]]);
        $client = Fga::client($http);

        $client->send(Request::post('check', ['a' => 'b']));
        $this->assertSame('http://openfga.test/stores/'.Fga::STORE.'/check', $http->lastUrl());

        $client->send(Request::get('stores')->atRoot());
        $this->assertSame('http://openfga.test/stores', $http->lastUrl());
    }

    /**
     * A call inside a store with no store configured is refused here, not
     * sent to `/stores//check` for the server to puzzle over.
     */
    #[Test]
    public function test_an_in_store_call_without_a_store_is_refused(): void
    {
        $client = Fga::client(FakeHttpClient::replying('{}'), store: null);

        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessageMatches('/no store id is configured/');

        $client->send(Request::post('check'));
    }

    #[Test]
    public function test_credentials_go_in_the_header_and_never_in_the_url(): void
    {
        $http = FakeHttpClient::replying('{}');
        Fga::client($http, credentials: PreSharedKey::of('secret-key'))->send(Request::post('check'));

        $this->assertSame('Bearer secret-key', $http->lastRequest()->getHeaderLine('Authorization'));
        $this->assertStringNotContainsString('secret-key', $http->lastUrl());
    }

    #[Test]
    public function test_no_credentials_means_no_authorization_header_at_all(): void
    {
        $http = FakeHttpClient::replying('{}');
        Fga::client($http, credentials: NoCredentials::create())->send(Request::post('check'));

        $this->assertFalse(
            $http->lastRequest()->hasHeader('Authorization'),
            'a plain `openfga run` wants no header; sending an empty one is not the same thing',
        );
    }

    #[Test]
    public function test_a_get_carries_no_body_and_declares_no_content_type(): void
    {
        $http = FakeHttpClient::replying('{}');
        Fga::client($http)->send(Request::get('changes', ['page_size' => 5, 'type' => 'doc']));

        $request = $http->lastRequest();
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('', (string) $request->getBody());
        $this->assertFalse($request->hasHeader('Content-Type'));
        $this->assertStringContainsString('page_size=5', $http->lastUrl());
        $this->assertStringContainsString('type=doc', $http->lastUrl());
    }

    #[Test]
    public function test_empty_query_values_are_dropped_rather_than_sent_blank(): void
    {
        $http = FakeHttpClient::replying('{}');
        Fga::client($http)->send(Request::get('changes', ['page_size' => null, 'type' => '', 'continuation_token' => 'abc']));

        $this->assertStringNotContainsString('page_size', $http->lastUrl());
        $this->assertStringNotContainsString('type=', $http->lastUrl());
        $this->assertStringContainsString('continuation_token=abc', $http->lastUrl());
    }

    /**
     * The regression that mattered most.
     *
     * `ApiError::from()` used `new self`, and in a static method on the parent
     * `self` is the parent - so `ValidationError::from()` handed back a plain
     * ApiError and every `catch (ValidationError)` in every application was
     * dead code. Found by catching a real 400 from a real server and noticing
     * the catch block did not fire.
     *
     * @param  class-string<ApiError>  $expected
     */
    #[Test]
    #[DataProvider('statuses')]
    public function test_each_status_becomes_its_own_exception_class(int $status, string $expected): void
    {
        $http = FakeHttpClient::replying('{"code":"some_code","message":"a message"}', $status);

        try {
            Fga::client($http)->send(Request::post('check'));
            $this->fail('a '.$status.' must throw');
        } catch (ApiError $e) {
            $this->assertInstanceOf($expected, $e, sprintf(
                'a %d came back as %s rather than %s',
                $status,
                $e::class,
                $expected,
            ));
            $this->assertSame($status, $e->status);
            $this->assertSame('some_code', $e->errorCode());
            $this->assertSame('a message', $e->apiMessage());
            $this->assertStringContainsString('some_code - a message', $e->getMessage());
        }
    }

    /** @return iterable<string, array{int, class-string<ApiError>}> */
    public static function statuses(): iterable
    {
        yield '400 is a validation error' => [400, ValidationError::class];
        yield '422 is a validation error' => [422, ValidationError::class];
        yield '401 is unauthenticated' => [401, Unauthenticated::class];
        yield '403 is unauthenticated' => [403, Unauthenticated::class];
        yield '404 is not found' => [404, NotFound::class];
        yield '429 is rate limited' => [429, RateLimited::class];
        yield '500 is a plain api error' => [500, ApiError::class];
        yield '503 is a plain api error' => [503, ApiError::class];
    }

    /**
     * A denial is a result, not a failure.
     *
     * This is the one thing the client must not turn into an exception.
     * Conflating "the answer is no" with "the call failed" is how an
     * application fails open.
     */
    #[Test]
    public function test_allowed_false_is_a_successful_call(): void
    {
        $http = FakeHttpClient::replying('{"allowed":false}');

        $result = Fga::with($http)->checkResult('user:anne', 'viewer', 'doc:1');

        $this->assertFalse($result->allowed);
        $this->assertSame('denied', (string) $result);
    }

    #[Test]
    public function test_a_missing_allowed_field_is_a_denial_not_an_approval(): void
    {
        $http = FakeHttpClient::replying('{}');

        $this->assertFalse(Fga::with($http)->check('user:anne', 'viewer', 'doc:1'));
    }

    /**
     * Writes are transactional, so a retried write that actually succeeded
     * comes back as a duplicate error - a client that retried would turn a
     * success into a failure.
     */
    #[Test]
    public function test_nothing_is_ever_retried(): void
    {
        foreach ([429, 500, 503] as $status) {
            $http = FakeHttpClient::replying('{"code":"x","message":"y"}', $status);

            try {
                Fga::client($http)->send(Request::post('write'));
            } catch (ApiError) {
                // expected
            }

            $this->assertSame(1, $http->callCount(), sprintf('a %d was retried', $status));
        }
    }

    #[Test]
    public function test_an_unreachable_server_is_a_transport_failure(): void
    {
        $http = FakeHttpClient::failing(new TransportFailure('connection refused'));

        $this->expectException(TransportFailed::class);
        $this->expectExceptionMessageMatches('/could not be reached: connection refused/');

        Fga::client($http)->send(Request::post('check'));
    }

    #[Test]
    public function test_a_body_that_is_not_json_says_so_with_an_excerpt(): void
    {
        $http = FakeHttpClient::replying('<html><body>502 Bad Gateway</body></html>', 502);

        $this->expectException(TransportFailed::class);
        $this->expectExceptionMessageMatches('/not JSON.*502 Bad Gateway/s');

        Fga::client($http)->send(Request::post('check'));
    }

    /**
     * An empty body is `{}` and never `[]`.
     *
     * `json_encode([])` produces a JSON array, and every body in this API is
     * an object - the server answers a bare `[]` with "syntax error (line
     * 1:1): unexpected token [", which reads like a problem with a relation
     * expression and is not. An unfiltered `read()` is the call that sends
     * one, so it is the call in this test.
     */
    #[Test]
    public function test_an_empty_request_body_is_an_object_not_an_array(): void
    {
        $http = FakeHttpClient::replying('{"tuples":[],"continuation_token":""}');

        Fga::with($http)->tuples()->read();

        $this->assertSame('{}', (string) $http->lastRequest()->getBody());
    }

    #[Test]
    public function test_an_empty_body_is_not_a_parse_error(): void
    {
        $http = FakeHttpClient::replying('', 200);

        $this->assertSame([], Fga::client($http)->send(Request::delete('stores/x')->atRoot())->body);
    }

    /** A JSON array at the top level is not an object, and not a reply. */
    #[Test]
    public function test_a_json_scalar_is_not_a_reply(): void
    {
        $http = FakeHttpClient::replying('"just a string"', 200);

        $this->expectException(TransportFailed::class);

        Fga::client($http)->send(Request::post('check'));
    }

    #[Test]
    public function test_a_stream_is_read_line_by_line(): void
    {
        $http = FakeHttpClient::streaming(
            '{"result":{"object":"doc:1"}}',
            '{"result":{"object":"doc:2"}}',
            '{"result":{"object":"doc:3"}}',
        );

        $lines = iterator_to_array($this->iterate(Fga::client($http)->stream(Request::post('streamed-list-objects'))));

        $this->assertCount(3, $lines);
        $this->assertSame(['result' => ['object' => 'doc:1']], $lines[0]);
    }

    #[Test]
    public function test_a_stream_tolerates_blank_lines_and_a_missing_final_newline(): void
    {
        $http = FakeHttpClient::replying("{\"result\":{\"object\":\"doc:1\"}}\n\n{\"result\":{\"object\":\"doc:2\"}}");

        $lines = iterator_to_array($this->iterate(Fga::client($http)->stream(Request::post('streamed-list-objects'))));

        $this->assertCount(2, $lines);
    }

    #[Test]
    public function test_a_stream_that_errors_before_it_starts_throws_rather_than_yielding(): void
    {
        $http = FakeHttpClient::replying('{"code":"validation_error","message":"bad type"}', 400);

        $this->expectException(ValidationError::class);

        iterator_to_array($this->iterate(Fga::client($http)->stream(Request::post('streamed-list-objects'))));
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $stream
     * @return iterable<int, array<string, mixed>>
     */
    private function iterate(iterable $stream): iterable
    {
        foreach ($stream as $line) {
            yield $line;
        }
    }
}
