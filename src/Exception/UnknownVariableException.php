<?php declare(strict_types=1);
namespace Ols\PhpRuler\Exception;

class UnknownVariableException extends EvaluatorException
{
    public function __construct(string $message, public readonly string $variablePath = '')
    {
        parent::__construct($message);
    }
}
