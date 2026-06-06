<?php declare(strict_types=1);
namespace Ols\PhpRuler\Exception;
class SyntaxErrorException extends EvaluatorException
{
    public function __construct(string $message, public readonly int $position)
    {
        parent::__construct($message);
    }
}
