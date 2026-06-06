<?php declare(strict_types=1);
namespace Ols\PhpRuler\Parser;
final class InNode implements Node
{
    public function __construct(
        public readonly Node $subject,
        public readonly Node $list,   // LiteralNode(array) ou VariableNode
    ) {}
}
