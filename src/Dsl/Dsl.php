<?php

declare(strict_types=1);

namespace Piston\OpenFga\Dsl;

use JsonException;
use Piston\OpenFga\Dsl\Ast\AuthorizationModel;
use Piston\OpenFga\Exception\InvalidArgument;

/**
 * The DSL, in and out.
 *
 * OpenFGA's HTTP API speaks only JSON, but nobody writes a model in JSON -
 * they write it in the DSL, in a `.fga` file next to the code it governs, and
 * then they need it as JSON to ship it. Every official SDK leaves that gap:
 * the transformer exists once, in TypeScript, as a separate tool. This closes
 * it for PHP.
 *
 *     $model = Dsl::file(__DIR__.'/authorization-model.fga');
 *     $openfga->models()->write($model);
 *
 * Both directions are here, and the round trip is the thing worth trusting:
 * parse then write and you get the same model back. That is asserted against
 * OpenFGA's own twenty-nine reference pairs, and separately against a live
 * server, which is the only judge that counts.
 */
final class Dsl
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function parse(string $dsl): AuthorizationModel
    {
        return Parser::parse($dsl);
    }

    /** Read a `.fga` file. */
    public static function file(string $path): AuthorizationModel
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw InvalidArgument::because('the model file', 'does not exist or cannot be read', $path);
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw InvalidArgument::because('the model file', 'could not be read', $path);
        }

        return self::parse($contents);
    }

    /** DSL straight to the JSON the API accepts. */
    public static function toJson(string $dsl, int $flags = 0): string
    {
        return self::parse($dsl)->toJson($flags);
    }

    /**
     * JSON back to a model - and from there to DSL.
     *
     * @param  string|array<string, mixed>  $json
     */
    public static function fromJson(string|array $json): AuthorizationModel
    {
        if (is_string($json)) {
            try {
                $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw InvalidArgument::because('the model', 'is not valid JSON: '.$e->getMessage());
            }

            if (! is_array($decoded)) {
                throw InvalidArgument::because('the model', 'is not a JSON object');
            }

            /** @var array<string, mixed> $decoded */
            $json = $decoded;
        }

        return AuthorizationModel::fromArray($json);
    }

    /**
     * A model as DSL text.
     *
     * @param  AuthorizationModel|array<string, mixed>|string  $model
     */
    public static function stringify(AuthorizationModel|array|string $model): string
    {
        return match (true) {
            $model instanceof AuthorizationModel => $model->toDsl(),
            default => self::fromJson($model)->toDsl(),
        };
    }
}
