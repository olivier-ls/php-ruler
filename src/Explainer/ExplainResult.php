<?php declare(strict_types=1);
namespace Ols\PhpRuler\Explainer;

final class ExplainResult
{
    /**
     * @param ?bool $passed NULL when the root could not be fully evaluated
     *                      (missing variable, type error, …). Distinct from `false`,
     *                      which means "evaluated and returned a falsy value".
     */
    public function __construct(
        public readonly ?bool       $passed,
        public readonly ExplainNode $root,
    ) {}

    /**
     * Failed *evaluated* leaf nodes — i.e. blocking criteria that returned false.
     * Excludes leaves that were skipped, missing or errored: those are reported
     * separately via skipped() / missing() / errors() to avoid mixing
     * "the rule returned false" with "the rule could not be evaluated".
     *
     * @return ExplainNode[]
     */
    public function failures(): array
    {
        return $this->collectLeaves($this->root, fn(ExplainNode $n) => $n->isEvaluated() && $n->passed === false);
    }

    /**
     * Successful evaluated leaf nodes.
     *
     * @return ExplainNode[]
     */
    public function successes(): array
    {
        return $this->collectLeaves($this->root, fn(ExplainNode $n) => $n->isEvaluated() && $n->passed === true);
    }

    /**
     * All leaf nodes regardless of status.
     *
     * @return ExplainNode[]
     */
    public function leaves(): array
    {
        return $this->collectLeaves($this->root, fn(ExplainNode $n) => true);
    }

    /**
     * Short-circuited leaf nodes (skipped by AND/OR/ternary).
     *
     * @return ExplainNode[]
     */
    public function skipped(): array
    {
        return $this->collectLeaves($this->root, fn(ExplainNode $n) => $n->isSkipped());
    }

    /**
     * Leaf nodes that required a missing variable.
     *
     * @return ExplainNode[]
     */
    public function missing(): array
    {
        return $this->collectLeaves($this->root, fn(ExplainNode $n) => $n->isMissing());
    }

    /**
     * Leaf nodes that raised an unrecoverable exception during evaluation
     * (type error, division by zero, NaN/INF, unknown function, …).
     *
     * @return ExplainNode[]
     */
    public function errors(): array
    {
        return $this->collectLeaves($this->root, fn(ExplainNode $n) => $n->isError());
    }

    /**
     * All leaf nodes that blocked evaluation — combines missing() and errors().
     *
     * This is the most common backoffice query: "what prevented this expression
     * from evaluating?". Using unresolved() avoids the caller having to merge
     * the two collections manually.
     *
     * @return ExplainNode[]
     */
    public function unresolved(): array
    {
        return array_merge($this->missing(), $this->errors());
    }

    // -------------------------------------------------------------------------

    /**
     * Traverses the tree, collecting LEAF nodes matching `$predicate`.
     *
     * The collector descends into every node's children regardless of the
     * parent's status — this matters because a compound node in MISSING/ERROR
     * (e.g. AND propagating a child's failure) can still contain evaluated
     * children that should appear in the counts.
     *
     * Only true leaves (no children) are tested against the predicate, so
     * compound nodes are never themselves included — the caller should look
     * at `$result->root` directly for a top-level summary.
     *
     * Implementation note (audit A5): uses an accumulator passed by reference
     * instead of merging arrays at every recursive return. array_merge in a
     * recursive walk is O(n²) on deep trees because each merge copies the
     * already-accumulated array; the reference accumulator is O(n) regardless
     * of tree shape. Behaviour and ordering are unchanged.
     *
     * @param callable(ExplainNode):bool $predicate
     * @return ExplainNode[]
     */
    private function collectLeaves(ExplainNode $node, callable $predicate): array
    {
        $result = [];
        $this->collectLeavesInto($node, $predicate, $result);
        return $result;
    }

    /**
     * Worker for collectLeaves() — appends matching leaves into $result by
     * reference. Extracted so collectLeaves() keeps its single-return shape
     * as a public-ish helper while the recursion accumulates in O(n).
     *
     * @param callable(ExplainNode):bool $predicate
     * @param ExplainNode[]              $result
     */
    private function collectLeavesInto(ExplainNode $node, callable $predicate, array &$result): void
    {
        if ($node->isLeaf()) {
            if ($predicate($node)) {
                $result[] = $node;
            }
            return;
        }
        foreach ($node->children as $child) {
            $this->collectLeavesInto($child, $predicate, $result);
        }
    }
}
