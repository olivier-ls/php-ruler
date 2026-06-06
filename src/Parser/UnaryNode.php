<?php declare(strict_types=1);
namespace Ols\PhpRuler\Parser;
final class UnaryNode implements Node
{
    public function __construct(
        public readonly string $operator,
        public readonly Node   $operand,
    ) {}
}
