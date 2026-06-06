<?php declare(strict_types=1);
namespace Ols\PhpRuler\Explainer;

final class ExplainNode
{
    /**
     * @param string         $expression  Reconstructed expression  e.g. "cart.total > 100"
     * @param ?bool          $passed      Node result. NULL when status !== EVALUATED.
     * @param string         $operator    Operator                  e.g. ">", "AND", "IN", "NOT", "?:"
     *                                    For non-evaluated nodes, conventionally the original
     *                                    operator of the AST node (or 'skipped'/'missing'/'error').
     * @param mixed          $leftValue   Evaluated left value      e.g. 85.0
     * @param mixed          $rightValue  Evaluated right value     e.g. 100
     * @param ExplainNode[]  $children    Child nodes (AND, OR, NOT)
     * @param ExplainStatus  $status      Evaluation state — see enum.
     * @param ?string        $detail      Human-readable detail when status is MISSING
     *                                    (the missing variable path) or ERROR (the
     *                                    exception message). NULL otherwise.
     * @param bool           $leftMissing For ?? nodes: true when left was an absent variable
     *                                    (as opposed to a variable present but null).
     *                                    Allows the caller to distinguish "x was missing"
     *                                    from "x = null".
     */
    public function __construct(
        public readonly string        $expression,
        public readonly ?bool         $passed,
        public readonly string        $operator,
        public readonly mixed         $leftValue   = null,
        public readonly mixed         $rightValue  = null,
        public readonly array         $children    = [],
        public readonly ExplainStatus $status      = ExplainStatus::EVALUATED,
        public readonly ?string       $detail      = null,
        public readonly bool          $leftMissing = false,
    ) {}

    /** Leaf node = no children (comparison, IN, value) */
    public function isLeaf(): bool
    {
        return empty($this->children);
    }

    /** Compound node = AND, OR, NOT */
    public function isCompound(): bool
    {
        return !empty($this->children);
    }

    /** Short-circuited by AND/OR/ternary (sibling resolved the parent) */
    public function isSkipped(): bool
    {
        return $this->status === ExplainStatus::SHORT_CIRCUITED;
    }

    /** Required a variable absent from the context */
    public function isMissing(): bool
    {
        return $this->status === ExplainStatus::MISSING;
    }

    /** Raised an unrecoverable exception during evaluation */
    public function isError(): bool
    {
        return $this->status === ExplainStatus::ERROR;
    }

    /** Was actually evaluated and has a meaningful `passed` value */
    public function isEvaluated(): bool
    {
        return $this->status === ExplainStatus::EVALUATED;
    }
}
