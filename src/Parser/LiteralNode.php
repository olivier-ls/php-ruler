<?php declare(strict_types=1);
namespace Ols\PhpRuler\Parser;
final class LiteralNode implements Node
{
    public function __construct(public readonly mixed $value) {}
}
