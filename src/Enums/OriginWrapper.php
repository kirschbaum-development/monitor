<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Enums;

enum OriginWrapper: string
{
    case None = 'none';
    case SquareBrackets = 'square';     // [Monitor:Foo]
    case CurlyBraces = 'curly';      // {Monitor:Foo}
    case Parentheses = 'round';      // (Monitor:Foo)
    case AngleBrackets = 'angle';      // <Monitor:Foo>
    case DoubleQuotes = 'double';     // "Monitor:Foo"
    case SingleQuotes = 'single';     // 'Monitor:Foo'
    case Asterisks = 'asterisks';     // *Monitor:Foo*

    public function wrap(string $origin): string
    {
        return match ($this) {
            self::SquareBrackets => "[{$origin}]",
            self::CurlyBraces => "{{$origin}}",
            self::Parentheses => "({$origin})",
            self::AngleBrackets => "<{$origin}>",
            self::DoubleQuotes => "\"{$origin}\"",
            self::SingleQuotes => "'{$origin}'",
            self::Asterisks => "*{$origin}*",
            self::None => $origin,
        };
    }

    public static function fromConfig(?string $value): self
    {
        return match ($value) {
            'square' => self::SquareBrackets,
            'curly' => self::CurlyBraces,
            'round' => self::Parentheses,
            'angle' => self::AngleBrackets,
            'double' => self::DoubleQuotes,
            'single' => self::SingleQuotes,
            'asterisks' => self::Asterisks,
            default => self::None,
        };
    }
}
