<?php declare(strict_types=1);
namespace Ols\PhpRuler\Parser;
final class FunctionNode implements Node
{
    public function __construct(
        public readonly string $name,
        public readonly array  $args,
    ) {}
}
