<?php

declare(strict_types=1);

namespace Piston\OpenFga\Configuration;

use DateTimeImmutable;
use JsonException;
use Piston\OpenFga\Exception\InvalidArgument;
use Piston\OpenFga\Exception\TransportFailed;
use Piston\OpenFga\Exception\Unauthenticated;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SensitiveParameter;

/**
 * The OAuth2 client-credentials exchange, over PSR-18.
 *
 * The shape is the one the official SDKs send, checked against them rather
 * than invented here: a form-encoded POST of `client_id`, `client_secret`,
 * `audience` and `grant_type=client_credentials`, answered with
 * `access_token` and `expires_in`.
 *
 * Two details about the URL that are easy to get wrong, and that the official
 * clients handle the same way:
 *
 * - the issuer may be given **without a scheme**, and then it is https;
 * - the issuer may be given as a bare host, and then the path is
 *   `/oauth/token`. If it already carries a path, that path is used as it
 *   stands - including its query string, which some issuers need.
 */
final class HttpTokenIssuer implements TokenIssuer
{
    public const DEFAULT_PATH = '/oauth/token';

    public function __construct(
        private readonly string $issuer,
        private readonly string $clientId,
        #[SensitiveParameter] private readonly string $clientSecret,
        private readonly string $audience,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
    ) {}

    public function fetch(): AccessToken
    {
        $url = self::tokenUrl($this->issuer);

        $body = http_build_query([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'audience' => $this->audience,
            'grant_type' => 'client_credentials',
        ], '', '&', PHP_QUERY_RFC1738);

        $request = $this->requests->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withHeader('Accept', 'application/json')
            ->withBody($this->streams->createStream($body));

        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw TransportFailed::unreachable($e);
        }

        $contents = (string) $response->getBody();
        $status = $response->getStatusCode();

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw TransportFailed::notJson($status, $contents);
        }

        if (! is_array($decoded)) {
            throw TransportFailed::notJson($status, $contents);
        }

        /** @var array<string, mixed> $decoded */
        if ($status < 200 || $status >= 300) {
            throw new Unauthenticated(
                $status,
                $decoded,
                'POST',
                $url,
                sprintf(
                    'the token issuer refused the client credentials with %d%s. Check the client id, the '
                    .'secret, and that the audience matches the OpenFGA deployment.',
                    $status,
                    self::detail($decoded),
                ),
            );
        }

        $token = $decoded['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new Unauthenticated(
                $status,
                $decoded,
                'POST',
                $url,
                'the token issuer answered without an access_token',
            );
        }

        $expiresIn = $decoded['expires_in'] ?? null;
        $expiresAt = null;

        if (is_int($expiresIn) || (is_string($expiresIn) && ctype_digit($expiresIn))) {
            $expiresAt = (new DateTimeImmutable)->modify(sprintf('+%d seconds', (int) $expiresIn));
        }

        return new AccessToken($token, $expiresAt);
    }

    /**
     * The URL to post to, from whatever shape the issuer was configured in.
     *
     * Public and static because it is the part worth testing on its own:
     * every deployment writes the issuer differently and each form has to end
     * up at the same place.
     */
    public static function tokenUrl(string $issuer): string
    {
        $issuer = trim($issuer);

        if ($issuer === '') {
            throw InvalidArgument::because('the token issuer', 'is empty');
        }

        if (preg_match('#^https?://#i', $issuer) !== 1) {
            $issuer = 'https://'.$issuer;
        }

        $parts = parse_url($issuer);

        if ($parts === false || ! isset($parts['host']) || $parts['host'] === '') {
            throw InvalidArgument::because('the token issuer', 'is not a usable URL', $issuer);
        }

        $path = $parts['path'] ?? '';

        // An issuer given as a bare host - the common case - gets the
        // conventional path. One that already names a path keeps it.
        if (trim($path, '/') === '') {
            $path = self::DEFAULT_PATH;
        }

        $url = sprintf(
            '%s://%s%s%s',
            strtolower($parts['scheme'] ?? 'https'),
            $parts['host'],
            isset($parts['port']) ? ':'.$parts['port'] : '',
            $path,
        );

        if (isset($parts['query']) && $parts['query'] !== '') {
            $url .= '?'.$parts['query'];
        }

        return $url;
    }

    /** @param array<string, mixed> $body */
    private static function detail(array $body): string
    {
        foreach (['error_description', 'error', 'message'] as $key) {
            $value = $body[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return ': '.$value;
            }
        }

        return '';
    }
}
