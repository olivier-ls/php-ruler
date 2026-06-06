<?php declare(strict_types=1);
namespace Ols\PhpRuler\Explainer;

use Ols\PhpRuler\ExpressionEvaluator;
use Ols\PhpRuler\Exception\UnknownVariableException;
use Ols\PhpRuler\Parser\{Node, BinaryNode, UnaryNode, LiteralNode, VariableNode, InNode, FunctionNode, TernaryNode};

final class ExpressionExplainer
{
    public function __construct(private readonly ExpressionEvaluator $eval) {}

    /**
     * Intermediate value traces indexed by spl_object_id().
     * Populated once by buildTrace() before the explain traversal.
     *
     * Evaluation count per node type :
     *   - FunctionNode    → called exactly once via callFunction(), using already-traced
     *                       argument values. This prevents triple evaluation of functions
     *                       at the leaf level.
     *   - All other nodes → evaluated via evaluateAst(), which re-walks the full sub-tree.
     *                       Any FunctionNode nested inside a compound node (BinaryNode,
     *                       UnaryNode, InNode, TernaryNode) is therefore called a second
     *                       time when the parent's evaluateAst() re-traverses its children.
     *
     * Concrete consequence: a function used inside any compound expression is called
     * **exactly twice per occurrence** in the expression — not "sometimes", not "maybe".
     * The factor is multiplicative with occurrences, not with nesting depth:
     *   - counter() + counter()        →  4 calls total (2 occurrences × 2)
     *   - counter() > 0 AND counter()  →  6 calls total (3 occurrences × 2)
     *   - now() at the root alone      →  1 call (no compound parent)
     *
     * This is the structural cost of producing per-node traces with a two-phase
     * (pre-evaluate, then walk) design. It is acceptable for a diagnostic tool but
     * makes the Explainer unsuitable for any expression whose functions have side
     * effects (counters, DB writes, mail sending, etc.). See explain() for the
     * user-facing contract.
     *
     * @var array<int, mixed>
     */
    private array $trace = [];

    /**
     * Error map populated alongside $trace when a node cannot be evaluated.
     * Indexed by spl_object_id() like $trace; the two maps are mutually exclusive
     * for any given node (a node is either traced OR errored, never both).
     *
     *   ['status' => ExplainStatus::MISSING, 'detail' => 'cart.total']
     *   ['status' => ExplainStatus::ERROR,   'detail' => 'Operator "+" ...']
     *
     * @var array<int, array{status: ExplainStatus, detail: string}>
     */
    private array $errors = [];

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Explains the result of a string expression.
     * Parses and evaluates while collecting the detail of each condition.
     *
     * Never throws on missing variables, type errors, or any other evaluation
     * failure — those are surfaced as MISSING / ERROR nodes in the result tree
     * so the caller can diagnose precisely what went wrong and where.
     *
     * @warning Functions nested inside any compound expression (binary, unary, IN, ternary)
     *          will be called **exactly twice per occurrence** in the expression — not
     *          "sometimes", not "may be". This is the structural diagnostic cost of producing
     *          detailed per-node traces (see $trace property docblock for the mechanism).
     *          Examples:
     *            - counter() + counter()        → 4 calls (2 occurrences × 2)
     *            - counter() > 0 AND counter()  → 6 calls (3 occurrences × 2)
     *          Functions with side effects (incrementing counters, writing to DB, sending
     *          emails, etc.) must NOT be used in expressions passed to explain() or
     *          explainAst(). This restriction does not apply to evaluate(), evaluateSafe(),
     *          or any other non-explain evaluation path.
     */
    public function explain(string $expression, array $context): ExplainResult
    {
        return $this->explainAst($this->eval->getAst($expression), $context);
    }

    /**
     * Explains the result of a pre-compiled AST.
     * Useful for batch processing (single parse).
     *
     * @warning See explain() — the same side-effect restriction applies here.
     */
    public function explainAst(Node $ast, array $context): ExplainResult
    {
        $this->trace  = [];
        $this->errors = [];
        $this->buildTrace($ast, $context);

        $root = $this->explainNode($ast, $context);

        // The result's `passed` is meaningful only when the root was actually evaluated.
        // MISSING/ERROR/SHORT_CIRCUITED roots cannot be summarized as a bool.
        $passed = $root->isEvaluated() ? $root->passed : null;
        return new ExplainResult($passed, $root);
    }

    // -------------------------------------------------------------------------
    // Pre-evaluation (trace)
    // -------------------------------------------------------------------------

    /**
     * Walks the AST depth-first and stores the value of each node in $trace,
     * or its error/missing status in $errors. Mutually exclusive: a node is
     * either traced or errored, never both.
     *
     * Exceptions raised by sub-trees are caught and translated into entries
     * in $errors so the explain traversal can produce structured diagnostic
     * nodes instead of bubbling the exception up to the caller.
     *
     * Recursion structure (short-circuit / ?? branching) is preserved.
     */
    private function buildTrace(Node $node, array $context): void
    {
        $id = spl_object_id($node);
        if (array_key_exists($id, $this->trace) || array_key_exists($id, $this->errors)) {
            return;
        }

        // --- Phase 1: recurse into children, with structural short-circuit ---

        if ($node instanceof BinaryNode) {
            if (in_array($node->operator, ['AND', 'OR'], true)) {
                // Short-circuit: only trace the right branch if needed,
                // mirroring the Evaluator's use of && and ||.
                // If left errored, we treat it as "uncertain" — like an unresolved
                // variable in evaluateSafe — and trace the right branch anyway,
                // so the diagnostic shows what we know about both sides.
                $this->buildTrace($node->left, $context);
                if ($this->isErrored($node->left)) {
                    // Left failed — we still trace right for completeness,
                    // then mark the parent as ERROR (it can't be resolved without left).
                    $this->buildTrace($node->right, $context);
                    $this->errors[$id] = $this->errors[spl_object_id($node->left)];
                    return;
                }
                $leftValue = $this->trace[spl_object_id($node->left)];
                $needRight = $node->operator === 'AND' ? (bool) $leftValue : !(bool) $leftValue;
                if ($needRight) {
                    $this->buildTrace($node->right, $context);
                }
            } elseif ($node->operator === '??') {
                // Left may be an absent variable or produce a type error — fall through to right
                $this->buildTrace($node->left, $context);
                $leftFailed = $this->isErrored($node->left);
                $leftValue  = $leftFailed ? null : $this->trace[spl_object_id($node->left)];

                // Right is only needed when left was missing/failed or null
                if ($leftValue === null) {
                    $this->buildTrace($node->right, $context);
                }

                if ($leftFailed || $leftValue === null) {
                    // ?? "absorbs" a left failure: the result is right's value
                    // (or right's failure, if it failed too).
                    $rightId = spl_object_id($node->right);
                    if (array_key_exists($rightId, $this->trace)) {
                        $this->trace[$id] = $this->trace[$rightId];
                    } else {
                        // Right also failed — propagate its error to the ?? node
                        $this->errors[$id] = $this->errors[$rightId];
                    }
                    return;
                }
            } else {
                $this->buildTrace($node->left,  $context);
                $this->buildTrace($node->right, $context);
            }
        } elseif ($node instanceof UnaryNode) {
            $this->buildTrace($node->operand, $context);
        } elseif ($node instanceof InNode) {
            $this->buildTrace($node->subject, $context);
            $this->buildTrace($node->list,    $context);
        } elseif ($node instanceof FunctionNode) {
            foreach ($node->args as $arg) {
                $this->buildTrace($arg, $context);
            }
        } elseif ($node instanceof TernaryNode) {
            $this->buildTrace($node->condition, $context);
            if ($this->isErrored($node->condition)) {
                // Condition failed — can't decide which branch to take.
                // Propagate to the ternary itself; both branches stay un-traced.
                $this->errors[$id] = $this->errors[spl_object_id($node->condition)];
                return;
            }
            $condValue = $this->trace[spl_object_id($node->condition)];
            $this->buildTrace($condValue ? $node->then : $node->else, $context);
        }

        // --- Phase 2: if a child errored, propagate without attempting evaluation ---

        $erroredChild = $this->firstErroredChild($node);
        if ($erroredChild !== null) {
            $this->errors[$id] = $this->errors[spl_object_id($erroredChild)];
            return;
        }

        // --- Phase 3: evaluate this node, catching missing/type/eval errors ---

        try {
            $this->trace[$id] = $node instanceof FunctionNode
                ? $this->eval->callFunction(
                    $node->name,
                    array_map(fn(Node $arg) => $this->trace[spl_object_id($arg)], $node->args)
                )
                : $this->eval->evaluateAst($node, $context);
        } catch (UnknownVariableException $e) {
            $this->errors[$id] = [
                'status' => ExplainStatus::MISSING,
                'detail' => $e->getMessage(),
            ];
        } catch (\Ols\PhpRuler\Exception\TypeErrorException | \Ols\PhpRuler\Exception\EvaluatorException $e) {
            $this->errors[$id] = [
                'status' => ExplainStatus::ERROR,
                'detail' => $e->getMessage(),
            ];
        }
    }

    /** True if a node was processed but ended up in $errors rather than $trace. */
    private function isErrored(Node $node): bool
    {
        return array_key_exists(spl_object_id($node), $this->errors);
    }

    /**
     * Returns the first direct child of $node that is errored, or null if none.
     * Used during buildTrace to propagate a child's error upward without
     * attempting to evaluate the parent (which would re-throw the same error).
     */
    private function firstErroredChild(Node $node): ?Node
    {
        $children = match (true) {
            $node instanceof BinaryNode  => [$node->left, $node->right],
            $node instanceof UnaryNode   => [$node->operand],
            $node instanceof InNode      => [$node->subject, $node->list],
            $node instanceof FunctionNode => $node->args,
            // TernaryNode: only the taken branch is traced; we can't iterate
            // over both blindly, but the dedicated handling in buildTrace
            // already deals with errored condition / branch.
            default => [],
        };
        foreach ($children as $child) {
            if ($this->isErrored($child)) {
                return $child;
            }
        }
        return null;
    }

    /**
     * Returns the traced value of a node (guaranteed to be already computed
     * unless the node errored — in which case the caller should have
     * intercepted via isErrored() / explainNode's early return).
     */
    private function traced(Node $node): mixed
    {
        $id = spl_object_id($node);
        if (array_key_exists($id, $this->trace)) {
            return $this->trace[$id];
        }
        // Defensive fallback: if a caller asks for the traced value of an errored
        // node, return null rather than crashing on an undefined key. This should
        // not normally happen — explainNode intercepts errored nodes before
        // recursing into the dedicated explain*() methods.
        return null;
    }

    /**
     * Builds an ExplainNode for a node whose value couldn't be obtained
     * (MISSING / ERROR). The expression label is reconstructed from the AST.
     */
    private function makeFromError(Node $node): ExplainNode
    {
        $err = $this->errors[spl_object_id($node)] ?? [
            'status' => ExplainStatus::ERROR,
            'detail' => 'Unknown error',
        ];
        return new ExplainNode(
            expression: $this->printNode($node),
            passed:     null,
            operator:   $err['status'] === ExplainStatus::MISSING ? 'missing' : 'error',
            status:     $err['status'],
            detail:     $err['detail'],
        );
    }

    // -------------------------------------------------------------------------
    // AST traversal
    // -------------------------------------------------------------------------

    private function explainNode(Node $node, array $context): ExplainNode
    {
        // AND / OR → compound node with two children
        if ($node instanceof BinaryNode && in_array($node->operator, ['AND', 'OR'], true)) {
            return $this->explainLogical($node, $context);
        }

        // NOT → compound node with one child
        if ($node instanceof UnaryNode && $node->operator === 'NOT') {
            return $this->explainNot($node, $context);
        }

        // ?? → leaf node: exposes the resolved left value (or null if missing) and the fallback
        if ($node instanceof BinaryNode && $node->operator === '??') {
            return $this->explainCoalesce($node, $context);
        }

        // Comparisons → leaf node with actual values
        if ($node instanceof BinaryNode && in_array($node->operator, ['=', '!=', '>', '>=', '<', '<='], true)) {
            return $this->explainComparison($node, $context);
        }

        // IN → leaf node with subject and list
        if ($node instanceof InNode) {
            return $this->explainIn($node, $context);
        }

        // Ternary → compound node with condition as child
        if ($node instanceof TernaryNode) {
            return $this->explainTernary($node, $context);
        }

        // Fallback: arithmetic, functions, literals — leaf nodes.
        // If errored, render as a diagnostic leaf preserving the missing/error info.
        // Otherwise, evaluate and expose the value.
        if ($this->isErrored($node)) {
            return $this->makeFromError($node);
        }
        // (bool) $value rather than "is_bool ? value : true" — otherwise NOT(0) would give passed=false
        // while !0 = true
        $value  = $this->traced($node);
        $passed = (bool) $value;
        return new ExplainNode($this->printNode($node), $passed, 'value', leftValue: $value);
    }

    private function explainLogical(BinaryNode $node, array $context): ExplainNode
    {
        $left = $this->explainNode($node->left, $context);

        // Right child: three possibilities
        //   - traced     → recurse normally
        //   - errored    → recurse (explainNode will render the error leaf)
        //   - neither    → never visited (short-circuit) → render as SKIPPED
        if ($this->isTraced($node->right) || $this->isErrored($node->right)) {
            $right = $this->explainNode($node->right, $context);
        } else {
            $right = $this->makeSkipped($node->right);
        }

        // If the parent itself errored (e.g. propagated from an errored child),
        // keep the children for diagnostic readability but mark the parent
        // with the appropriate status. passed is null in that case.
        if ($this->isErrored($node)) {
            $err = $this->errors[spl_object_id($node)];
            return new ExplainNode(
                expression: $this->printNode($node),
                passed:     null,
                operator:   $node->operator,
                children:   [$left, $right],
                status:     $err['status'],
                detail:     $err['detail'],
            );
        }

        $passed = (bool) $this->traced($node);
        return new ExplainNode(
            expression: $this->printNode($node),
            passed:     $passed,
            operator:   $node->operator,
            children:   [$left, $right],
        );
    }

    /**
     * Creates a node marked as short-circuited.
     * The expression is reconstructed from the AST (always available),
     * but no value has been evaluated.
     */
    private function makeSkipped(Node $node): ExplainNode
    {
        return new ExplainNode(
            expression: $this->printNode($node),
            passed:     null,
            operator:   'skipped',
            status:     ExplainStatus::SHORT_CIRCUITED,
        );
    }

    /**
     * Checks whether a node has been traced (i.e. evaluated by buildTrace).
     * Uses array_key_exists to distinguish "not traced" from "traced as null".
     */
    private function isTraced(Node $node): bool
    {
        return array_key_exists(spl_object_id($node), $this->trace);
    }

    private function explainNot(UnaryNode $node, array $context): ExplainNode
    {
        // NOT IN → treated as a single leaf operation (not NOT(IN))
        if ($node->operand instanceof InNode) {
            $inNode = $node->operand;

            // If the NOT or its inner IN node errored, render as a diagnostic leaf
            // (the NOT IN is a single semantic unit — no children to display).
            if ($this->isErrored($node) || $this->isErrored($inNode)) {
                return $this->makeFromError($node);
            }

            // Defensive check: if the InNode was never traced (e.g. skipped branch),
            // return a skipped node rather than crashing on $this->traced().
            // Callers should protect against this, but we guard here too so that any
            // future caller that forgets the check doesn't produce a silent undefined-key crash.
            if (!$this->isTraced($inNode)) {
                return $this->makeSkipped($node);
            }

            $subject = $this->traced($inNode->subject);
            $list    = $this->traced($inNode->list);
            $passed  = !(bool) $this->traced($inNode);

            // printNode($node) — où $node est UnaryNode(NOT, InNode) — produit déjà
            // "subject NOT IN [...]" via la branche dédiée dans printNode().
            // Une seule source de vérité pour le rendu humain des NOT IN.
            return new ExplainNode(
                expression:  $this->printNode($node),
                passed:      $passed,
                operator:    'NOT IN',
                leftValue:   $subject,
                rightValue:  $list,
            );
        }

        $child = $this->explainNode($node->operand, $context);

        // If the NOT itself errored (almost always because its child did), keep
        // the child for diagnostic readability but mark the parent's status.
        if ($this->isErrored($node)) {
            $err = $this->errors[spl_object_id($node)];
            return new ExplainNode(
                expression: $this->printNode($node),
                passed:     null,
                operator:   'NOT',
                children:   [$child],
                status:     $err['status'],
                detail:     $err['detail'],
            );
        }

        return new ExplainNode(
            expression: $this->printNode($node),
            passed:     !$child->passed,
            operator:   'NOT',
            children:   [$child],
        );
    }

    private function explainComparison(BinaryNode $node, array $context): ExplainNode
    {
        // If the comparison itself errored (typically: incompatible types,
        // NaN/INF operand, or an errored child propagating up), render as a
        // diagnostic leaf. Surface the operand values we *did* manage to
        // resolve so the user can see what was attempted.
        if ($this->isErrored($node)) {
            $err = $this->errors[spl_object_id($node)];
            return new ExplainNode(
                expression:  $this->printNode($node),
                passed:      null,
                operator:    $node->operator,
                leftValue:   $this->tracedOrNull($node->left),
                rightValue:  $this->tracedOrNull($node->right),
                status:      $err['status'],
                detail:      $err['detail'],
            );
        }

        $leftValue  = $this->traced($node->left);
        $rightValue = $this->traced($node->right);
        // Reuse the Evaluator's result (looseEqual included)
        $passed     = (bool) $this->traced($node);

        return new ExplainNode(
            expression:  $this->printNode($node),
            passed:      $passed,
            operator:    $node->operator,
            leftValue:   $leftValue,
            rightValue:  $rightValue,
        );
    }

    private function explainIn(InNode $node, array $context): ExplainNode
    {
        // Same pattern as explainComparison.
        if ($this->isErrored($node)) {
            $err = $this->errors[spl_object_id($node)];
            return new ExplainNode(
                expression:  $this->printNode($node),
                passed:      null,
                operator:    'IN',
                leftValue:   $this->tracedOrNull($node->subject),
                rightValue:  $this->tracedOrNull($node->list),
                status:      $err['status'],
                detail:      $err['detail'],
            );
        }

        $subject = $this->traced($node->subject);
        $list    = $this->traced($node->list);
        $passed  = (bool) $this->traced($node);

        return new ExplainNode(
            expression:  $this->printNode($node),
            passed:      $passed,
            operator:    'IN',
            leftValue:   $subject,
            rightValue:  $list,
        );
    }

    private function explainCoalesce(BinaryNode $node, array $context): ExplainNode
    {
        // ?? absorbs left failures (missing / null / errored), so the node
        // itself errors only when BOTH branches failed.
        if ($this->isErrored($node)) {
            $err = $this->errors[spl_object_id($node)];
            return new ExplainNode(
                expression:  $this->printNode($node),
                passed:      null,
                operator:    '??',
                leftValue:   $this->tracedOrNull($node->left),
                rightValue:  $this->tracedOrNull($node->right),
                status:      $err['status'],
                detail:      $err['detail'],
                // leftMissing is intentionally false here — a fully-failed ??
                // is more about the right branch than the left.
            );
        }

        $leftTraced   = $this->isTraced($node->left);
        $leftValue    = $leftTraced ? $this->traced($node->left) : null;
        // leftMissing distinguishes "left was an absent variable" from "left was null".
        // It's true when left was NOT traced — which happens both for true missing
        // variables (MISSING) and for left-side errors (ERROR), both being cases
        // where the fallback was used.
        $leftMissing  = !$leftTraced;
        $usedFallback = !$leftTraced || $leftValue === null;

        // Right and the node itself may not be traced if right failed (missing var, type error)
        $rightValue  = ($usedFallback && $this->isTraced($node->right)) ? $this->traced($node->right) : null;
        $nodeTraced  = $this->isTraced($node);
        $passed      = $nodeTraced ? (bool) $this->traced($node) : false;

        return new ExplainNode(
            expression:   $this->printNode($node),
            passed:       $passed,
            operator:     '??',
            leftValue:    $leftValue,                  // null = missing or null (check leftMissing to distinguish)
            rightValue:   $rightValue,                 // null if fallback not used or right failed
            leftMissing:  $leftMissing,
        );
    }

    private function explainTernary(TernaryNode $node, array $context): ExplainNode
    {
        $condNode = $this->explainNode($node->condition, $context);

        // If the condition errored, neither branch was traced — we can't even
        // tell which would have been taken. Render the ternary as a diagnostic
        // leaf keeping the condition node for context.
        if ($this->isErrored($node->condition)) {
            $err = $this->errors[spl_object_id($node)] ?? $this->errors[spl_object_id($node->condition)];
            return new ExplainNode(
                expression: $this->printNode($node),
                passed:     null,
                operator:   '?:',
                children:   [$condNode, $this->makeSkipped($node->then), $this->makeSkipped($node->else)],
                status:     $err['status'],
                detail:     $err['detail'],
            );
        }

        $condValue = $this->traced($node->condition);

        // The taken branch is traced, the other is skipped — like AND/OR short-circuit
        [$takenNode, $skippedNode] = $condValue
            ? [$this->explainNode($node->then, $context), $this->makeSkipped($node->else)]
            : [$this->makeSkipped($node->then),           $this->explainNode($node->else, $context)];

        // If the parent ternary errored (which can happen if the taken branch
        // errored and propagated), keep all three children for readability.
        if ($this->isErrored($node)) {
            $err = $this->errors[spl_object_id($node)];
            return new ExplainNode(
                expression: $this->printNode($node),
                passed:     null,
                operator:   '?:',
                children:   [$condNode, $takenNode, $skippedNode],
                status:     $err['status'],
                detail:     $err['detail'],
            );
        }

        $value = $this->traced($node);
        return new ExplainNode(
            expression: $this->printNode($node),
            passed:     (bool) $value,
            operator:   '?:',
            leftValue:  $value,
            children:   [$condNode, $takenNode, $skippedNode],
        );
    }

    /** Like traced() but returns null when the node is errored rather than throwing. */
    private function tracedOrNull(Node $node): mixed
    {
        $id = spl_object_id($node);
        return $this->trace[$id] ?? null;
    }

    // -------------------------------------------------------------------------
    // Node printer — reconstructs the string from the AST
    // -------------------------------------------------------------------------

    private function printNode(Node $node, int $parentPrecedence = 0): string
    {
        if ($node instanceof LiteralNode)  return $this->printLiteral($node->value);
        if ($node instanceof VariableNode) return $node->path;

        if ($node instanceof BinaryNode) {
            $prec      = $this->operatorPrecedence($node->operator);
            $rightPrec = in_array($node->operator, ['-', '/', '%'], true) ? $prec + 1 : $prec;

            $inner = $this->printNode($node->left,  $prec)
                   . ' ' . $node->operator . ' '
                   . $this->printNode($node->right, $rightPrec);
            return $prec < $parentPrecedence ? "($inner)" : $inner;
        }

        if ($node instanceof UnaryNode) {
            // NOT IN → reconstruit "x NOT IN [...]" et non "NOT x IN [...]"
            // qui serait syntaxiquement invalide et non réinjecable dans le parser.
            if ($node->operator === 'NOT' && $node->operand instanceof InNode) {
                $in = $node->operand;
                $prec = $this->operatorPrecedence('IN');
                return $this->printNode($in->subject, $prec) . ' NOT IN ' . $this->printNode($in->list);
            }
            // NOT has high precedence (aligned with PHP's `!`, see parseNot).
            // Propagating that precedence to the operand ensures lower-precedence
            // binaries (??, comparisons, AND, OR, ternary) get parenthesised
            // when used as the operand of NOT, e.g. `NOT (a ?? b)` round-trips
            // correctly instead of being reprinted as `NOT a ?? b`
            // (which would re-parse as `(NOT a) ?? b`).
            $operand = $this->printNode($node->operand, $this->operatorPrecedence('NOT'));
            return $node->operator . ' ' . $operand;
        }

        if ($node instanceof InNode) {
            // Le subject peut être n'importe quelle expression (ternaire, ??, OR, +…).
            // Sans propagation de précédence, "a + b IN [...]" se reconstruirait
            // "a + b IN [...]" qui se reparse comme "a + (b IN [...])". On parenthèse
            // le subject quand sa précédence est inférieure à celle de IN.
            $prec = $this->operatorPrecedence('IN');
            return $this->printNode($node->subject, $prec)
                 . ' IN ' . $this->printNode($node->list);
        }

        if ($node instanceof FunctionNode) {
            $args = implode(', ', array_map(fn(Node $arg) => $this->printNode($arg), $node->args));
            return $node->name . '(' . $args . ')';
        }

        if ($node instanceof TernaryNode) {
            // Le ternaire a la précédence la plus basse du langage (sous OR).
            // Il doit donc être parenthésé dès qu'il est opérande d'à peu près
            // n'importe quel opérateur. Les enfants, eux, sont déjà délimités
            // syntaxiquement par '?' et ':' — on leur passe 0 pour qu'ils ne
            // soient jamais surparenthésés à cause du ternaire parent.
            $prec = $this->operatorPrecedence('?:');
            // The condition position is NOT delimited by '?'/':', so a nested
            // ternary there must be parenthesized to round-trip: without this,
            // '(a ? b : c) ? d : e' would reprint as 'a ? b : c ? d : e', which
            // the parser then reads as 'a ? b : (c ? d : e)' — a different tree.
            // then/else ARE delimited syntactically, so they stay at 0.
            $inner = $this->printNode($node->condition, $prec + 1)
                   . ' ? ' . $this->printNode($node->then, 0)
                   . ' : ' . $this->printNode($node->else, 0);
            return $prec < $parentPrecedence ? "($inner)" : $inner;
        }

        // Audit A8: a silent '?' fallback would hide additions to the Node
        // hierarchy. Throwing surfaces the bug at the earliest opportunity
        // (typically during the first call to explain() that touches the new
        // node type) instead of letting a stray '?' character appear in
        // user-facing explain output.
        throw new \LogicException(
            'ExpressionExplainer::printNode(): unsupported node type ' . get_class($node) .
            '. Add a branch to printNode and the corresponding precedence entry to operatorPrecedence().'
        );
    }

    /**
     * Operator precedence — higher number means higher priority.
     * Used to determine whether parentheses are needed during reconstruction.
     *
     * The table mirrors the precedence chain enforced by the Parser
     * (parseTernary → parseOr → parseAnd → parseCoalesce → parseComparison
     *  → parseAddSub → parseMulDiv → parseNot → parseUnary → parsePrimary)
     * which is itself aligned on PHP's native operator precedence.
     *
     * '?:' is the synthetic key for the ternary operator (lowest precedence,
     * below OR). 'IN'/'NOT IN' sit at comparison level. 'NOT' is placed
     * ABOVE the multiplicatives to match PHP's `!`, which is intentionally
     * very high — see parseNot in Parser.php for the rationale.
     *
     * Keep this table in lock-step with the Parser chain. If you ever
     * reorder precedence levels, audit issues B1/B2 will resurface.
     */
    private function operatorPrecedence(string $op): int
    {
        return match ($op) {
            '?:'                              => 0,
            'OR'                              => 1,
            'AND'                             => 2,
            '??'                              => 3,
            '=', '!=', '>', '>=', '<', '<='  => 4,
            'IN', 'NOT IN'                    => 4,
            '+', '-'                          => 5,
            '*', '/', '%'                     => 6,
            'NOT'                             => 7,
            default                           => 0,
        };
    }

    private function printLiteral(mixed $value): string
    {
        if (is_null($value))   return 'null';
        if (is_bool($value))   return $value ? 'true' : 'false';
        if (is_string($value)) return "'" . str_replace("'", "''", $value) . "'";
        if (is_array($value))  return '[' . implode(', ', array_map([$this, 'printLiteral'], $value)) . ']';
        return (string) $value;
    }
}
