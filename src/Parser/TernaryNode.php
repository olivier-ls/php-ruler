<?php declare(strict_types=1);
namespace Ols\PhpRuler\Parser;

final class TernaryNode implements Node
{
    public function __construct(
        public readonly Node $condition,
        public readonly Node $then,
        public readonly Node $else,
    ) {}
}
