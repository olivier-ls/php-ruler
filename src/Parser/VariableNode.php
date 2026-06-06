<?php declare(strict_types=1);
namespace Ols\PhpRuler\Parser;
final class VariableNode implements Node
{
    public function __construct(public readonly string $path) {}
}
