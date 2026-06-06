<?php declare(strict_types=1);
namespace Ols\PhpRuler\Parser;
final class BinaryNode implements Node
{
    public function __construct(
        public readonly string $operator,
        public readonly Node   $left,
        public readonly Node   $right,
    ) {}
}
