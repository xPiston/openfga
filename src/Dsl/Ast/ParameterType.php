<?php

declare(strict_types=1);

namespace Piston\OpenFga\Dsl\Ast;

use Piston\OpenFga\Exception\InvalidArgument;

/**
 * The type of a condition parameter.
 *
 * The DSL spells these in lower case (`string`, `ipaddress`) and the JSON
 * spells them `TYPE_NAME_STRING`, `TYPE_NAME_IPADDRESS` - a protobuf
 * enumeration leaking through the HTTP API. Both spellings live here so that
 * neither the parser nor the writer has to build the other by hand.
 */
enum ParameterType: string
{
    case Bool = 'bool';
    case String = 'string';
    case Int = 'int';
    case Uint = 'uint';
    case Double = 'double';
    case Duration = 'duration';
    case Timestamp = 'timestamp';
    case IpAddress = 'ipaddress';
    case Map = 'map';
    case List = 'list';

    public function wireName(): string
    {
        return 'TYPE_NAME_'.strtoupper($this->value);
    }

    public function isContainer(): bool
    {
        return $this === self::Map || $this === self::List;
    }

    public static function fromWireName(string $wire): self
    {
        $name = strtolower(str_starts_with($wire, 'TYPE_NAME_') ? substr($wire, strlen('TYPE_NAME_')) : $wire);

        return self::tryFrom($name) ?? throw InvalidArgument::because(
            'a condition parameter type',
            'is not one OpenFGA defines',
            $wire,
        );
    }

    public static function fromDsl(string $name): self
    {
        return self::tryFrom(strtolower(trim($name))) ?? throw InvalidArgument::because(
            'a condition parameter type',
            'is not one of bool, string, int, uint, double, duration, timestamp, ipaddress, map or list',
            $name,
        );
    }
}
