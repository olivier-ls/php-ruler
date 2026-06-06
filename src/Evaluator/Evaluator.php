<?php declare(strict_types=1);
namespace Ols\PhpRuler\Evaluator;

use Ols\PhpRuler\ContextResolver;
use Ols\PhpRuler\BuiltinFunctions;
use Ols\PhpRuler\Exception\{EvaluatorException, TypeErrorException, UnknownVariableException};
use Ols\PhpRuler\Parser\{Node, BinaryNode, UnaryNode, LiteralNode, VariableNode, InNode, FunctionNode, TernaryNode};

final class Evaluator
{
    /**
     * Registered functions, indexed by name.
     * Each entry stores the callable along with its arity bounds, captured once
     * via reflection at registration time so callFunction() can validate the
     * argument count without re-introspecting on every call.
     *
     * @var array<string, array{fn: callable, min: int, max: int}>
     *   - min: number of required parameters
     *   - max: total number of declared parameters, or PHP_INT_MAX if variadic
     */
    private array $functions = [];

    /**
     * Reentrant depth counter shared by evaluate() and evalSafe().
     * Guards against pathological ASTs (especially cyclic ones produced by a
     * tampered importAst payload — see audit B13/I8) that would otherwise
     * stack-overflow PHP.
     *
     * MAX_EVAL_DEPTH is intentionally larger than ContextResolver::MAX_DEPTH
     * because an AST nests one level per operator/argument, whereas
     * ContextResolver counts nesting in the *data*. A 200-deep AST is already
     * pathological for a human-written expression but well within reach of a
     * pretty-printed JSON exported from a configurator UI; we leave headroom.
     */
    private int $evalDepth = 0;
    private const MAX_EVAL_DEPTH = 200;

    public function __construct()
    {
        // Built-in functions — can be overridden via registerFunction().
        // Routed through registerFunction() so arity metadata is captured
        // uniformly for builtins and custom functions alike.
        foreach (BuiltinFunctions::all() as $name => $fn) {
            $this->registerFunction($name, $fn);
        }
    }

    public function registerFunction(string $name, callable $fn): void
    {
        $ref         = new \ReflectionFunction($fn);
        $params      = $ref->getParameters();
        $hasVariadic = !empty($params) && end($params)->isVariadic();

        $this->functions[$name] = [
            'fn'  => $fn,
            'min' => $ref->getNumberOfRequiredParameters(),
            'max' => $hasVariadic ? PHP_INT_MAX : $ref->getNumberOfParameters(),
        ];
    }

    // -------------------------------------------------------------------------
    // Standard evaluation
    // -------------------------------------------------------------------------

    public function evaluate(Node $node, array $context): mixed
    {
        // Depth guard — check BEFORE incrementing. The frame that trips the
        // limit must not touch the counter: if it did (and then threw), the
        // unwinding finally{} blocks of every parent frame would each run
        // $this->evalDepth-- and drive the counter negative, permanently
        // disarming the guard for the remaining lifetime of this (reusable,
        // cache-backed) instance. Checking first means the throwing frame
        // never increments, so the parents' finally{} unwind the counter back
        // to exactly 0 on their own — no manual reset, no poisoned state.
        if ($this->evalDepth >= self::MAX_EVAL_DEPTH) {
            throw new EvaluatorException(
                'Evaluation depth limit exceeded (' . self::MAX_EVAL_DEPTH . '). ' .
                'Likely a cyclic AST or pathologically deep expression.'
            );
        }
        $this->evalDepth++;
        try {
            return match (true) {
                $node instanceof LiteralNode   => $node->value,
                $node instanceof VariableNode  => $this->resolveVariable($node->path, $context),
                $node instanceof UnaryNode     => $this->evaluateUnary($node, $context),
                $node instanceof BinaryNode    => $this->evaluateBinary($node, $context),
                $node instanceof InNode        => $this->evaluateIn($node, $context),
                $node instanceof FunctionNode  => $this->evaluateFunction($node, $context),
                $node instanceof TernaryNode   => $this->evaluateTernary($node, $context),
                default => throw new EvaluatorException("Unknown node: " . get_class($node)),
            };
        } finally {
            $this->evalDepth--;
        }
    }

    private function evaluateTernary(TernaryNode $node, array $context): mixed
    {
        $cond = $this->evaluate($node->condition, $context);
        $this->assertBool($cond, '?:');
        return $cond
            ? $this->evaluate($node->then, $context)
            : $this->evaluate($node->else, $context);
    }

    private function resolveVariable(string $path, array $context): mixed
    {
        // NB: deliberately NOT calling assertFinite() here. NaN/INF values
        // coming from the context must be allowed to flow as far as is_finite()
        // (the user's escape hatch to inspect them). They are caught at the
        // operator level — every arithmetic op, comparison, equality test
        // applies assertFinite() to its operands. So they can travel, but they
        // cannot participate in a calculation silently.
        $value = ContextResolver::resolve($path, $context);
        $this->assertSupportedValue($value, $path);
        return $value;
    }

    /**
     * Rejects unsupported value types entering the evaluator from the context.
     *
     * The expression language only deals with scalars, null and arrays of
     * those — anything else (objects, closures, resources, …) has no defined
     * semantics here and would otherwise "float" silently until an operator
     * raises a confusing native TypeError ("cannot compare object and string").
     * Catching the offending value at the resolution boundary turns that into
     * a clear, actionable error pointing at the variable path.
     *
     * Walks lists recursively because ContextResolver treats array_is_list
     * arrays as terminal values and never inspects their content — without
     * recursion, ['tags' => [new Thing()]] would pass and the object would
     * resurface at the first index access or list function call.
     *
     * @throws TypeErrorException
     */
    private function assertSupportedValue(mixed $value, string $path): void
    {
        if ($value === null || is_scalar($value)) {
            return;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $this->assertSupportedValue($item, $path . '[' . $key . ']');
            }
            return;
        }
        throw new TypeErrorException(
            "Variable \"$path\" resolved to " . get_debug_type($value) . " — only scalar values, " .
            "null, and arrays of those are supported as context values."
        );
    }

    /**
     * NaN and INF have no meaningful semantics in expression evaluation:
     *   - NaN propagates silently (NaN > 0 → false, NaN = NaN → false in IEEE 754)
     *   - INF survives most operations and produces nonsense (INF - INF = NaN, INF = INF → true)
     *
     * This guard is called at every entry point where a value enters the evaluation
     * pipeline (variable resolution, function return) and at every operator that can
     * produce them (arithOp, safeDivide). Once values are validated at the frontier,
     * downstream operators can assume finite numbers throughout.
     *
     * Escape hatch for callers that need to test for INF/NaN explicitly: the built-in
     * is_finite() function, which returns true/false without throwing.
     *
     * @param string $context  Free-form label injected in the error message
     *                         (e.g. 'variable "x"', 'function "pow"', 'operator "+"').
     * @throws TypeErrorException
     */
    private function assertFinite(mixed $value, string $context): void
    {
        if (!is_float($value)) {
            return;
        }
        if (is_nan($value)) {
            throw new TypeErrorException(
                "$context: value is NaN (not-a-number). " .
                "NaN cannot be used in expression evaluation. Use is_finite() to test for it."
            );
        }
        if (is_infinite($value)) {
            throw new TypeErrorException(
                "$context: value is " . ($value > 0 ? 'INF' : '-INF') . ". " .
                "Infinite values cannot be used in expression evaluation. Use is_finite() to test for it."
            );
        }
    }

    /**
     * Logical operators (AND, OR, NOT) and ternary condition require strict bool
     * operands. PHP's native &&, ||, ! and ?: apply truthy/falsy coercion silently
     * — that's the source of bug I1: "false" AND true → true (the string "false"
     * is truthy in PHP), 5 AND 10 → true (not 10 as in Python).
     *
     * The library is already strict on comparisons (true = 1 → TypeError), so
     * being lax on logical operators broke the "no surprise" contract. This guard
     * restores symmetry: anything entering a logical operator must be an actual
     * bool. The escape hatches for the caller are documented in the message.
     *
     * @throws TypeErrorException
     */
    private function assertBool(mixed $value, string $op): void
    {
        if (!is_bool($value)) {
            throw new TypeErrorException(
                "Operator \"$op\": expected boolean, " . gettype($value) . " given. " .
                "Use an explicit comparison (e.g. \"value = true\") or register a bool() function to convert."
            );
        }
    }

    private function evaluateUnary(UnaryNode $node, array $context): mixed
    {
        $operand = $this->evaluate($node->operand, $context);
        return $this->applyUnaryOp($node->operator, $operand);
    }

    /**
     * Applies a unary operator to an already-resolved operand.
     * Called by both evaluateUnary() and evalSafe()'s UnaryNode branch —
     * mirrors applyBinaryOp() for symmetry between the two paths.
     *
     * Type policy for the arithmetic unary minus:
     *   - PHP's native `-` accepts almost anything (booleans coerce to int,
     *     null becomes 0, numeric strings become numbers). That silent
     *     coercion violates this library's "no surprise" contract — every
     *     other arithmetic operator (arithOp) explicitly rejects non-numbers.
     *   - We therefore require int|float explicitly. `-null` → TypeError,
     *     `-true` → TypeError, `-"5"` → TypeError, mirroring arithOp().
     *
     * Audit history: B9 documented that `-null` silently returned 0 in earlier
     * versions. I4 documented the duplication between evaluateUnary and
     * evalSafe::UnaryNode. Both closed by this single helper.
     *
     * @throws TypeErrorException
     * @throws EvaluatorException  on unknown unary operator (AST corruption)
     */
    private function applyUnaryOp(string $op, mixed $operand): mixed
    {
        if ($op === 'NOT') {
            $this->assertBool($operand, 'NOT');
            return !$operand;
        }

        if ($op === '-') {
            if (!is_int($operand) && !is_float($operand)) {
                throw new TypeErrorException(
                    'Operator "-": operand must be a number, ' . gettype($operand) . ' given'
                );
            }
            // Guard against -NaN / -INF: without this check, unary minus would
            // silently propagate non-finite values, breaking the invariant
            // enforced by every other arithmetic operator (arithOp, safeDivide,
            // compareOp, looseEqual).
            $this->assertFinite($operand, 'operator "-"');
            return -$operand;
        }

        throw new EvaluatorException("Unknown unary operator: $op");
    }

    private function evaluateBinary(BinaryNode $node, array $context): mixed
    {
        if ($node->operator === 'AND') {
            $left = $this->evaluate($node->left, $context);
            $this->assertBool($left, 'AND');
            // Short-circuit: left is false → right is not evaluated (mirrors PHP &&).
            // A type error on the right side will not be raised in this branch,
            // which is consistent with how PHP, evalSafeBinary, and most languages
            // handle short-circuit logical operators.
            if (!$left) {
                return false;
            }
            $right = $this->evaluate($node->right, $context);
            $this->assertBool($right, 'AND');
            return $right;
        }
        if ($node->operator === 'OR') {
            $left = $this->evaluate($node->left, $context);
            $this->assertBool($left, 'OR');
            // Short-circuit: left is true → right is not evaluated (mirrors PHP ||).
            if ($left) {
                return true;
            }
            $right = $this->evaluate($node->right, $context);
            $this->assertBool($right, 'OR');
            return $right;
        }

        // Null-coalescing: left absent or null → right
        if ($node->operator === '??') {
            try {
                $left = $this->evaluate($node->left, $context);
                return $left !== null ? $left : $this->evaluate($node->right, $context);
            } catch (UnknownVariableException) {
                return $this->evaluate($node->right, $context);
            }
        }

        $left  = $this->evaluate($node->left,  $context);
        $right = $this->evaluate($node->right, $context);

        try {
            return $this->applyBinaryOp($node->operator, $left, $right);
        } catch (EvaluatorException $e) {
            throw $e;
        } catch (\TypeError | \DivisionByZeroError $e) {
            throw new TypeErrorException(
                "Type error with operator \"{$node->operator}\": " . $e->getMessage()
            );
        }
    }

    private function evaluateIn(InNode $node, array $context): bool
    {
        $subject = $this->evaluate($node->subject, $context);
        $list    = $this->evaluate($node->list,    $context);
        return $this->applyIn($subject, $list);
    }

    private function evaluateFunction(FunctionNode $node, array $context): mixed
    {
        $args = array_map(fn(Node $arg) => $this->evaluate($arg, $context), $node->args);
        return $this->callFunction($node->name, $args);
    }

    // -------------------------------------------------------------------------
    // Safe evaluation — collects missing variables instead of throwing
    // -------------------------------------------------------------------------

    /**
     * Evaluates the AST without throwing UnknownVariableException.
     *
     * Short-circuit semantics are preserved:
     *   - AND with a certain false left  → right branch never visited (its missing vars are irrelevant)
     *   - OR  with a certain true  left  → right branch never visited (its missing vars are irrelevant)
     *   - Ternary                        → only the taken branch is visited
     *
     * If any needed variable is missing → SafeResult(false, null, [...paths])
     * If all variables are resolved     → SafeResult(true,  $value, [])
     */
    public function evaluateSafe(Node $node, array $context): SafeResult
    {
        $missing = [];
        $value   = $this->evalSafe($node, $context, $missing);
        $missing = array_values(array_unique($missing));
        sort($missing);

        return empty($missing)
            ? new SafeResult(true,  $value, [])
            : new SafeResult(false, null,   $missing);
    }

    /**
     * Recursive safe walk. Missing variable paths are accumulated in $missing.
     * Returns null as a sentinel whenever at least one variable is absent —
     * the caller checks $missing before using the return value.
     */
    private function evalSafe(Node $node, array $context, array &$missing): mixed
    {
        // Same guard as evaluate() — shared counter so mutual recursion
        // (evalSafe → callFunction → custom code → evaluate) is also bounded.
        // Check BEFORE incrementing for the same reason as evaluate(): the
        // throwing frame must not touch the counter, otherwise the parents'
        // unwinding finally{} blocks would leave evalDepth negative and disarm
        // the guard for subsequent calls on this instance.
        if ($this->evalDepth >= self::MAX_EVAL_DEPTH) {
            throw new EvaluatorException(
                'Evaluation depth limit exceeded (' . self::MAX_EVAL_DEPTH . '). ' .
                'Likely a cyclic AST or pathologically deep expression.'
            );
        }
        $this->evalDepth++;
        try {
            return $this->evalSafeDispatch($node, $context, $missing);
        } finally {
            $this->evalDepth--;
        }
    }

    /**
     * The actual dispatch logic for evalSafe(), extracted so the depth guard
     * in evalSafe() above can wrap it in a clean try/finally without bloating
     * the method.
     */
    private function evalSafeDispatch(Node $node, array $context, array &$missing): mixed
    {
        if ($node instanceof LiteralNode) {
            return $node->value;
        }

        if ($node instanceof VariableNode) {
            try {
                return $this->resolveVariable($node->path, $context);
            } catch (UnknownVariableException) {
                $missing[] = $node->path;
                return null;
            }
        }

        if ($node instanceof UnaryNode) {
            // Use a local $operandMissing instead of passing $missing directly,
            // so that missing vars accumulated by sibling nodes earlier in the
            // recursion do not trigger a premature null return here.
            $operandMissing = [];
            $operand = $this->evalSafe($node->operand, $context, $operandMissing);
            array_push($missing, ...$operandMissing);
            if (!empty($operandMissing)) return null;

            // Delegate to the shared helper — keeps NOT/- semantics in lock-step
            // between the standard and safe paths (audit I4). Type errors are
            // never suppressed in safe mode (cf. ?: condition above: "a malformed
            // expression must surface even in safe mode").
            return $this->applyUnaryOp($node->operator, $operand);
        }

        if ($node instanceof BinaryNode) {
            return $this->evalSafeBinary($node, $context, $missing);
        }

        if ($node instanceof TernaryNode) {
            // Evaluate condition first — if it has missing vars we cannot pick a branch
            $condMissing = [];
            $cond = $this->evalSafe($node->condition, $context, $condMissing);
            array_push($missing, ...$condMissing);
            if (!empty($condMissing)) return null;

            // Condition fully resolved → strict bool check, just like the standard path.
            // A type error here takes precedence over any potential missing var in branches:
            // a malformed expression must surface even in safe mode.
            $this->assertBool($cond, '?:');

            // Only the taken branch is visited — mirrors standard short-circuit behaviour
            return $cond
                ? $this->evalSafe($node->then, $context, $missing)
                : $this->evalSafe($node->else, $context, $missing);
        }

        if ($node instanceof InNode) {
            $localMissing = [];
            $subject = $this->evalSafe($node->subject, $context, $localMissing);
            $list    = $this->evalSafe($node->list,    $context, $localMissing);
            array_push($missing, ...$localMissing);
            if (!empty($localMissing)) return null;
            return $this->applyIn($subject, $list);
        }

        if ($node instanceof FunctionNode) {
            $localMissing = [];
            $args = [];
            foreach ($node->args as $arg) {
                $args[] = $this->evalSafe($arg, $context, $localMissing);
            }
            array_push($missing, ...$localMissing);
            if (!empty($localMissing)) return null;
            return $this->callFunction($node->name, $args);
        }

        throw new EvaluatorException("Unknown node: " . get_class($node));
    }

    private function evalSafeBinary(BinaryNode $node, array $context, array &$missing): mixed
    {
        // AND — short-circuit on certain false left
        if ($node->operator === 'AND') {
            $leftMissing = [];
            $left = $this->evalSafe($node->left, $context, $leftMissing);

            // Left fully resolved → strict bool check (type errors take priority over missing).
            // Done before the short-circuit so that "hello" AND <anything> always raises,
            // matching the standard path.
            if (empty($leftMissing)) {
                $this->assertBool($left, 'AND');
            }

            // Left is certainly false and fully resolved → short-circuit, right branch irrelevant
            if (empty($leftMissing) && !$left) {
                return false;
            }

            // Left is uncertain or true → we must evaluate right (and collect its missing vars)
            $rightMissing = [];
            $right = $this->evalSafe($node->right, $context, $rightMissing);

            // Same strict check on right when fully resolved.
            if (empty($rightMissing)) {
                $this->assertBool($right, 'AND');
            }

            // Right is certainly false and fully resolved → right short-circuits the AND.
            // But if left had missing vars, we still report them: the caller must know
            // that the result is false *despite* incomplete data, not because of clean evaluation.
            if (empty($rightMissing) && !$right) {
                array_push($missing, ...$leftMissing);
                if (!empty($leftMissing)) return null;
                return false;
            }

            array_push($missing, ...$leftMissing, ...$rightMissing);
            if (!empty($leftMissing) || !empty($rightMissing)) return null;

            return $left && $right;
        }

        // OR — short-circuit on certain true left
        if ($node->operator === 'OR') {
            $leftMissing = [];
            $left = $this->evalSafe($node->left, $context, $leftMissing);

            // Left fully resolved → strict bool check (same rationale as AND above).
            if (empty($leftMissing)) {
                $this->assertBool($left, 'OR');
            }

            // Left is certainly true and fully resolved → short-circuit, right branch irrelevant
            if (empty($leftMissing) && $left) {
                return true;
            }

            // Left is uncertain or false → we must evaluate right
            $rightMissing = [];
            $right = $this->evalSafe($node->right, $context, $rightMissing);

            if (empty($rightMissing)) {
                $this->assertBool($right, 'OR');
            }

            // Right is certainly true and fully resolved → right short-circuits the OR.
            // But if left had missing vars, we still report them: same rationale as AND above.
            if (empty($rightMissing) && $right) {
                array_push($missing, ...$leftMissing);
                if (!empty($leftMissing)) return null;
                return true;
            }

            array_push($missing, ...$leftMissing, ...$rightMissing);
            if (!empty($leftMissing) || !empty($rightMissing)) return null;

            return $left || $right;
        }

        // Null-coalescing: left absent or null → right (left's missing vars are silenced)
        if ($node->operator === '??') {
            $leftMissing = [];
            $left = $this->evalSafe($node->left, $context, $leftMissing);
            // Left resolved to a non-null value → use it, right branch irrelevant
            if (empty($leftMissing) && $left !== null) {
                return $left;
            }
            // Left was missing or null → evaluate right, its missing vars DO propagate
            return $this->evalSafe($node->right, $context, $missing);
        }

        // All other operators: evaluate both sides, collect missing, then apply
        $left  = $this->evalSafe($node->left,  $context, $missing);
        $right = $this->evalSafe($node->right, $context, $missing);
        if (!empty($missing)) return null;

        try {
            return $this->applyBinaryOp($node->operator, $left, $right);
        } catch (EvaluatorException $e) {
            throw $e;
        } catch (\TypeError | \DivisionByZeroError $e) {
            throw new TypeErrorException(
                "Type error with operator \"{$node->operator}\": " . $e->getMessage()
            );
        }
    }

    // -------------------------------------------------------------------------
    // Shared operator helpers (used by both standard and safe paths)
    // -------------------------------------------------------------------------

    /**
     * Applies a non-logical binary operator to already-resolved values.
     * Called by both evaluateBinary() and evalSafeBinary().
     */
    private function applyBinaryOp(string $op, mixed $left, mixed $right): mixed
    {
        return match ($op) {
            '='  => $this->looseEqual($left, $right, '='),
            '!=' => !$this->looseEqual($left, $right, '!='),
            '>'  => $this->compareOp($left, $right, '>'),
            '>=' => $this->compareOp($left, $right, '>='),
            '<'  => $this->compareOp($left, $right, '<'),
            '<=' => $this->compareOp($left, $right, '<='),
            '+'  => $this->arithOp($left, $right, '+'),
            '-'  => $this->arithOp($left, $right, '-'),
            '*'  => $this->arithOp($left, $right, '*'),
            '%'  => $this->arithOp($left, $right, '%'),
            '/'  => $this->safeDivide($left, $right),
            default => throw new EvaluatorException("Unknown operator: $op"),
        };
    }

    /**
     * Applies IN membership check to already-resolved values.
     * Called by both evaluateIn() and evalSafe().
     *
     * Semantics:
     *   - scalar IN [items]   → standard membership (element-wise looseEqual)
     *   - array  IN [items]   → DUAL: first checks if the whole subject is one
     *                            of the items (strict equality, audit B5),
     *                            then falls back to intersection (any element
     *                            of subject is also in the list).
     *
     * TypeError policy: incompatible types between subject and a given list item
     * are tolerated *per pair* (we want "1 IN [1, 'a']" → true, and "2 IN [1, 'a']"
     * → false), but if NO pair could be compared at all then we have not actually
     * answered the membership question — we just failed silently. Returning false
     * in that case would lie to the caller (worse: NOT IN would then return true,
     * a positive claim derived from a non-comparison). So we track whether at
     * least one comparison succeeded; if none did, the last TypeError is re-thrown.
     *
     * The pre-pass for arrays does not contribute to "valid comparison" tracking
     * because === on arrays is total (never raises) — its absence of match simply
     * means "the whole subject isn't a list element", and we then move on.
     *
     * @throws TypeErrorException
     */
    private function applyIn(mixed $subject, mixed $list): bool
    {
        if (!is_array($list)) {
            throw new TypeErrorException("The right operand of IN must be an array");
        }

        // Empty list — no comparison to attempt, answer is unambiguously false.
        // (Same answer for [] IN [] via the array branch: the outer loop never runs.)
        if (empty($list)) {
            return false;
        }

        $hadAnyValidComparison = false;
        $lastTypeError         = null;

        // List IN List → first ask "is the whole subject present as an element
        // of the list?" (audit B5), THEN fall back to element-wise intersection
        // for the legacy case.
        //
        // Audit B5 decision: this dual semantics is DELIBERATE.
        //   - [1,2] IN [[1,2], 3]   → true   (the subject IS one of the items)
        //   - [1,2] IN [1, 2, 3]    → true   (intersection: 1 is in both)
        //   - [4,5] IN [1, 2, 3]    → false  (no intersection, no whole match)
        //
        // The pre-pass uses strict equality on arrays (PHP's $a === $b on arrays
        // compares them structurally), so it never raises. It is a fast,
        // type-safe path that handles the "membership of the array as a value"
        // intent unambiguously. The intersection fallback preserves the prior
        // behaviour for the common "any element in common" intent.
        //
        // Note: only the pre-pass is executed when subject is array AND a whole
        // match is found. Otherwise both passes contribute to the decision —
        // the pre-pass cannot return false on its own (it has no notion of
        // "tried but failed type-wise"), so absence of a whole match always
        // falls through to the intersection pass.
        //
        // Do NOT revisit this design without revisiting the rationale: the
        // intersection-only semantics was a silent false negative for users
        // who naturally expect array-as-value membership.
        if (is_array($subject)) {
            // Pre-pass: whole-subject as a list element.
            foreach ($list as $item) {
                if (is_array($item) && $subject === $item) {
                    return true;
                }
            }

            // Empty subject — no element-wise comparison possible.
            // (Empty subject can still match an empty list element above, e.g.
            //  [] IN [[], 1] → true was already handled by the pre-pass.)
            if (empty($subject)) {
                return false;
            }

            // Intersection fallback: at least one element in common.
            // Tracking is at the pair granularity, not the subjectItem granularity:
            // [1] IN ['a', 'b'] must raise (all pairs incompatible), not return false.
            foreach ($subject as $subjectItem) {
                foreach ($list as $item) {
                    try {
                        if ($this->looseEqual($subjectItem, $item)) {
                            return true;
                        }
                        $hadAnyValidComparison = true;
                    } catch (TypeErrorException $e) {
                        $lastTypeError = $e;
                    }
                }
            }
        } else {
            // Scalar IN List → standard behaviour
            foreach ($list as $item) {
                try {
                    if ($this->looseEqual($subject, $item)) {
                        return true;
                    }
                    $hadAnyValidComparison = true;
                } catch (TypeErrorException $e) {
                    $lastTypeError = $e;
                }
            }
        }

        // No pair was comparable — we never actually answered the question.
        // Surface the underlying type error rather than fabricating a false.
        if (!$hadAnyValidComparison && $lastTypeError !== null) {
            throw $lastTypeError;
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Arithmetic / comparison / equality primitives
    // -------------------------------------------------------------------------

    /**
     * Arithmetic operators — int|float only.
     *
     * @throws TypeErrorException
     */
    private function arithOp(mixed $left, mixed $right, string $op): int|float
    {
        if (!is_int($left) && !is_float($left)) {
            throw new TypeErrorException(
                "Operator \"$op\": left operand must be a number, " . gettype($left) . " given"
            );
        }
        if (!is_int($right) && !is_float($right)) {
            throw new TypeErrorException(
                "Operator \"$op\": right operand must be a number, " . gettype($right) . " given"
            );
        }
        $result = match ($op) {
            '+' => $left + $right,
            '-' => $left - $right,
            '*' => $left * $right,
            '%' => is_int($left) && is_int($right) ? $left % $right : fmod((float) $left, (float) $right),
        };

        // Integer overflow: when both operands are int but PHP silently
        // downcast the result to float, precision is lost. Symmetric to the
        // lex-time check that rejects literal integers exceeding PHP_INT_MAX.
        // Without this guard, (PHP_INT_MAX + 1 = PHP_INT_MAX) would return
        // true silently.
        // The check only applies to +, -, * — modulo of two ints stays int
        // (no overflow path), and the fmod branch is only reached when at
        // least one operand is already float (no downcast happening).
        if (is_int($left) && is_int($right) && is_float($result) && in_array($op, ['+', '-', '*'], true)) {
            throw new TypeErrorException(
                "Operator \"$op\": integer overflow ($left $op $right exceeds PHP_INT_MAX). " .
                "Cast one operand to float explicitly if you accept the precision loss."
            );
        }

        // fmod(0.0, 0.0) and similar operations can produce NaN. Float overflow
        // (e.g. 1e200 * 1e200) produces INF. Both are silent failures that
        // would propagate downstream. Single assertion catches both.
        $this->assertFinite($result, "operator \"$op\"");

        return $result;
    }

    /**
     * Order comparison operators — int|float vs int|float, or string vs string.
     * Allows comparing dates in Y-m-d format via lexicographic order.
     *
     * @throws TypeErrorException
     */
    private function compareOp(mixed $left, mixed $right, string $op): bool
    {
        $leftIsNumeric  = is_int($left)  || is_float($left);
        $rightIsNumeric = is_int($right) || is_float($right);

        if ($leftIsNumeric && $rightIsNumeric) {
            // Mirror the NaN/INF guard already in looseEqual. Without it,
            // NaN > 0 returns false silently (IEEE 754), and INF > 0 returns
            // true silently — both fail the "no surprise" contract.
            $this->assertFinite($left,  "operator \"$op\"");
            $this->assertFinite($right, "operator \"$op\"");
            return match ($op) {
                '>'  => $left >  $right,
                '>=' => $left >= $right,
                '<'  => $left <  $right,
                '<=' => $left <= $right,
            };
        }

        if (is_string($left) && is_string($right)) {
            return match ($op) {
                '>'  => $left >  $right,
                '>=' => $left >= $right,
                '<'  => $left <  $right,
                '<=' => $left <= $right,
            };
        }

        throw new TypeErrorException(
            "Operator \"$op\": cannot compare " . gettype($left) . " and " . gettype($right) . ". "
            . "Both operands must be numbers or strings."
        );
    }

    private function safeDivide(mixed $left, mixed $right): float|int
    {
        if (!is_int($left) && !is_float($left)) {
            throw new TypeErrorException(
                "Operator \"/\": left operand must be a number, " . gettype($left) . " given"
            );
        }
        if (!is_int($right) && !is_float($right)) {
            throw new TypeErrorException(
                "Operator \"/\": right operand must be a number, " . gettype($right) . " given"
            );
        }
        if ($right === 0 || $right === 0.0) {
            throw new TypeErrorException("Division by zero");
        }
        $result = $left / $right;
        // 1e308 / 1e-308 produces INF; division of very small floats can
        // also overflow to INF. Catch here for the same reason as arithOp.
        $this->assertFinite($result, 'operator "/"');
        return $result;
    }

    /**
     * Adapted equality: int/float compared numerically, other types strictly.
     * Avoids the false negative int(150) === float(150.0).
     * Throws a TypeErrorException for incompatible types — consistent with compareOp().
     * Throws a TypeErrorException if either operand is NAN — NAN has no meaningful
     * equality semantics in expression evaluation (IEEE 754: NAN !== NAN).
     *
     * Arrays are explicitly forbidden as operands of = / != (audit I6).
     * Rationale: silent structural equality of arrays is a well-known footgun
     * (two lists that happen to share contents would compare equal, the user
     * almost always wanted "is this value in that list", which is the IN
     * operator). Aligns with compareOp() which already rejects arrays.
     * Decision is deliberate and FINAL — do not reintroduce array equality
     * without revisiting the IN operator's role first.
     *
     * @throws TypeErrorException
     */
    private function looseEqual(mixed $a, mixed $b, string $operator = '='): bool
    {
        // NaN/INF cannot be meaningfully compared — must be rejected BEFORE the
        // null shortcut, otherwise "null = getNan()" would return false silently
        // and violate the invariant that NaN/INF never transit through an operator.
        // (assertFinite is a no-op on non-floats, so no overhead for the common path.)
        $this->assertFinite($a, 'operator "' . $operator . '"');
        $this->assertFinite($b, 'operator "' . $operator . '"');

        // Arrays are rejected as operands of = / != (audit I6).
        // The fall-through to `$a === $b` would have allowed structural array
        // equality, which is rarely what the user wants — they almost always
        // meant "is this value in that list" (use IN instead).
        if (is_array($a) || is_array($b)) {
            throw new TypeErrorException(
                'Operator "' . $operator . '": cannot compare arrays directly. ' .
                'Use IN to check membership of a value in a list.'
            );
        }

        // null = null → true ; null = <anything> → false (no exception)
        if ($a === null || $b === null) {
            return $a === $b;
        }

        $aIsNum = is_int($a) || is_float($a);
        $bIsNum = is_int($b) || is_float($b);

        if ($aIsNum && $bIsNum) {
            return (float) $a === (float) $b;
        }

        if (gettype($a) !== gettype($b)) {
            throw new TypeErrorException(
                'Operator "' . $operator . '": cannot compare ' . gettype($a) . ' and ' . gettype($b)
            );
        }

        return $a === $b;
    }

    // -------------------------------------------------------------------------
    // Function dispatch
    // -------------------------------------------------------------------------

    /**
     * Returns the names of all registered functions (built-in + custom).
     * Useful for backoffice autocompletion or pre-evaluation validation.
     *
     * @return string[]
     */
    public function getFunctionNames(): array
    {
        $names = array_keys($this->functions);
        sort($names);
        return $names;
    }

    /**
     * Calls a registered function with already-resolved arguments.
     * Used by ExpressionExplainer to avoid double-evaluating args.
     *
     * Exception policy when the function body throws:
     *   - EvaluatorException (and subclasses: TypeErrorException,
     *     UnknownVariableException, …) → re-thrown as-is. This handles the
     *     legitimate case of a custom function delegating to evaluate() /
     *     getContextValue() internally: the library's own exceptions transit
     *     unchanged so callers (evaluateSafe via FunctionNode, ExpressionExplainer
     *     via buildTrace) can classify them properly (MISSING vs ERROR).
     *   - Any other \Throwable (\RuntimeException, \LogicException, \Error, …)
     *     → wrapped in TypeErrorException with the original as `previous`.
     *     This guarantees that evaluate() only ever propagates EvaluatorException
     *     to the caller, regardless of what user-supplied custom functions throw.
     *
     * Known limitation — UnknownVariableException raised from inside a custom
     * function body (e.g. a custom fn calling $eval->getContextValue('x', [])
     * without a default) will traverse evaluateSafe() unchanged. evaluateSafe
     * cannot collect that variable in its `missing` list because the path is
     * not carried by the exception, and silently swallowing it would hide a real
     * problem. Custom function authors should either pass a default to
     * getContextValue() or catch UnknownVariableException themselves. The
     * library's `missing` collection contract applies to variables *referenced
     * in the expression*, not to lookups performed inside function bodies.
     *
     * @throws EvaluatorException
     * @throws TypeErrorException
     */
    public function callFunction(string $name, array $resolvedArgs): mixed
    {
        if (!isset($this->functions[$name])) {
            throw new EvaluatorException("Unknown function: \"{$name}\"");
        }

        $meta = $this->functions[$name];
        $n    = count($resolvedArgs);

        // PHP silently ignores extra arguments passed to a non-variadic
        // closure (only under-arity raises a TypeError). Without this guard,
        // round(1, 2, 3) would discard the 3 and return 1.0 — a silent wrong
        // call. We validate against the arity captured at registration time.
        if ($n < $meta['min'] || $n > $meta['max']) {
            $expected = $meta['min'] === $meta['max']
                ? "exactly {$meta['min']}"
                : ($meta['max'] === PHP_INT_MAX
                    ? "at least {$meta['min']}"
                    : "between {$meta['min']} and {$meta['max']}");
            throw new TypeErrorException(
                "Function \"{$name}\" expects {$expected} arguments, {$n} given"
            );
        }

        try {
            // NB: NOT calling assertFinite() on the result here, for the same
            // reason as resolveVariable() — a function may legitimately return
            // NaN/INF, and the user needs is_finite() to inspect it. Operators
            // downstream apply assertFinite() on their operands.
            return ($meta['fn'])(...$resolvedArgs);
        } catch (EvaluatorException $e) {
            // Library exceptions transit unchanged — see policy above.
            throw $e;
        } catch (\Throwable $e) {
            // Anything else (RuntimeException, LogicException, Error, …) is
            // wrapped so evaluate() never leaks raw PHP exceptions from user
            // code, and ExpressionExplainer / evaluateSafe can classify it.
            throw new TypeErrorException(
                "Error in function \"{$name}\": " . $e->getMessage(),
                previous: $e
            );
        }
    }
}
