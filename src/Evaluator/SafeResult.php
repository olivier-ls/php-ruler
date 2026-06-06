<?php declare(strict_types=1);
namespace Ols\PhpRuler\Evaluator;

final class SafeResult
{
    /**
     * @param bool     $success     false if at least one variable was missing (and not short-circuited away)
     * @param mixed    $value       result of the evaluation, or null if $success is false
     * @param string[] $missingVars list of variable paths that were needed but absent from the context
     */
    public function __construct(
        public readonly bool  $success,
        public readonly mixed $value,
        public readonly array $missingVars,
    ) {}

    /**
     * Returns the evaluated value.
     *
     * Throws if success is false — forces the caller to handle the missing-variable
     * case explicitly, rather than silently receiving null which is ambiguous
     * (null could be the actual evaluated value of a successful expression).
     *
     * Use getValueOr() instead if you want a safe fallback without try/catch.
     *
     * @throws \LogicException
     */
    public function getValue(): mixed
    {
        if (!$this->success) {
            throw new \LogicException(
                'Cannot get value of a failed SafeResult (missing variables: ' .
                implode(', ', $this->missingVars) . '). ' .
                'Check $result->success first, or use getValueOr().'
            );
        }
        return $this->value;
    }

    /**
     * Returns the evaluated value, or $default if the evaluation failed
     * (i.e. one or more variables were missing from the context).
     *
     * Safe alternative to getValue() when a fallback is acceptable.
     *
     * Example:
     *   $result->getValueOr(false)  // false if any variable was missing
     *   $result->getValueOr(null)   // null  — but indistinguishable from a real null result;
     *                               //         prefer getValue() + success check in that case
     */
    public function getValueOr(mixed $default): mixed
    {
        return $this->success ? $this->value : $default;
    }
}
