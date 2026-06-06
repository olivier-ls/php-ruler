<?php declare(strict_types=1);
namespace Ols\PhpRuler\Lexer;

final class Token
{
    public function __construct(
        public readonly TokenType $type,
        public readonly mixed     $value,
        public readonly int       $position,
    ) {}
}
