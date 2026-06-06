# Explainer

## Overview

The Explainer is a **diagnostic tool**: it evaluates an expression like `evaluate()` but also produces a tree representation of each node with:
- The reconstructed local expression (a human-readable form of what this node does)
- The status: evaluated / short-circuited / missing variable / error
- Intermediate values (left/right operands, result)
- The parent/child structure for AND/OR/NOT/ternary

Typical use case: show a back-office user **why** a rule passed or failed, condition by condition. Identify that `cart.total > 100` failed because `cart.total = 85`, or that `customer.vip = true` could not be evaluated because `customer.vip` is missing from the context.

⚠️ **The Explainer is not designed for high-throughput production use.** It is a diagnostic tool. See the "Cost" and "Limitations" sections.

## API

```php
namespace Ols\PhpRuler\Explainer;

final class ExpressionExplainer
{
    public function __construct(ExpressionEvaluator $eval);
    public function explain(string $expression, array $context): ExplainResult;
    public function explainAst(Node $ast, array $context): ExplainResult;
}

final class ExplainResult
{
    public readonly ?bool $passed;     // null if not evaluated (missing/error/root short-circuit)
    public readonly ExplainNode $root;

    public function failures(): array;   // evaluated leaves with passed === false
    public function successes(): array;  // evaluated leaves with passed === true
    public function leaves(): array;     // all leaves
    public function skipped(): array;    // short-circuited leaves
    public function missing(): array;    // leaves blocked by a missing variable
    public function errors(): array;     // leaves blocked by an error (type, division, etc.)
    public function unresolved(): array; // combines missing() + errors() — everything that blocked evaluation
}

final class ExplainNode
{
    public readonly string $expression;     // reconstructed local expression
    public readonly ?bool $passed;
    public readonly string $operator;       // '=', 'AND', 'NOT', '?:', 'value', 'skipped', 'missing', 'error'
    public readonly mixed $leftValue;
    public readonly mixed $rightValue;
    public readonly array $children;        // ExplainNode[]
    public readonly ExplainStatus $status;
    public readonly ?string $detail;        // missing path or error message
    public readonly bool $leftMissing;      // specific to ?? nodes: true if the left side was absent

    public function isLeaf(): bool;
    public function isCompound(): bool;
    public function isSkipped(): bool;
    public function isMissing(): bool;
    public function isError(): bool;
    public function isEvaluated(): bool;
}

enum ExplainStatus
{
    case EVALUATED;
    case SHORT_CIRCUITED;
    case MISSING;
    case ERROR;
}
```

## Behaviors

### `explain(string $expression, array $context): ExplainResult`

Builds the full explanation tree for the expression in the given context.

**Never throws on**: missing variable, type error, division by zero, NaN/INF. These conditions become `MISSING` or `ERROR` nodes in the tree.

**Always throws on**: syntax error (compile-time), exceeded AST depth, corrupted AST. These structural errors cannot be "explained" — there is nothing to traverse.

### Tree structure

The tree mirrors the AST structure with a **diagnostic-oriented view**:

| AST type | Explainer representation |
|---|---|
| `BinaryNode` AND/OR operator | Compound, 2 children |
| `UnaryNode` NOT | Compound, 1 child (except NOT IN, see below) |
| `TernaryNode` | Compound, 3 children (condition + 2 branches) |
| `BinaryNode` comparison operator `=`, `!=`, `>`, `>=`, `<`, `<=` | Leaf with `leftValue` and `rightValue` |
| `InNode` (and `NOT IN`: `UnaryNode(NOT, InNode)`) | Single leaf, `operator: 'IN'` or `'NOT IN'` |
| `BinaryNode` `??` operator | Leaf, distinguishes "left absent" vs "left null" via `leftMissing` |
| Arithmetic, functions, literals, single variables | Leaf, `operator: 'value'`, `leftValue` = the value |

### Compound vs leaf nodes

- **Compound**: AND, OR, NOT, ternary. Have children. `passed` is calculated based on the operator logic over evaluated children.
- **Leaves**: everything else. No children. `passed` reflects the "truth" of the node (a comparison that returns true, an IN that matches, a truthy value...).

`ExplainResult::failures()`, `successes()`, etc. **operate on leaves only**. For a top-level summary, look directly at `ExplainResult::root`.

### Node statuses

#### `EVALUATED`
The node was visited and its result is in `passed`.

#### `SHORT_CIRCUITED`
The node was not visited because a sibling or parent branch resolved the expression without it. Specifically:
- Right branch of AND with left being `false`
- Right branch of OR with left being `true`
- Untaken branch of a ternary

`passed` is `null`, `operator` is `'skipped'`.

#### `MISSING`
The node attempted to resolve a variable absent from the context. `detail` contains the `UnknownVariableException` message, `operator` is `'missing'`.

#### `ERROR`
The node threw a non-recoverable exception (type error, division by zero, NaN/INF, unknown function, invalid arity...). `detail` contains the message, `operator` is `'error'`.

### Error propagation

When a child has `MISSING` or `ERROR` status, the parent (AND, OR, ternary...) propagates this status **upward** rather than attempting to evaluate (which would re-throw the same exception).

Example:
```php
$result = $explainer->explain('a > 0 AND b < 100', ['a' => 5]);
// Leaf 'b < 100' has status=MISSING (detail: 'Unknown variable: "b"')
// Compound 'a > 0 AND b < 100' also has status=MISSING (propagation)
// Leaf 'a > 0' has status=EVALUATED, passed=true
```

For AND/OR, the sibling is **still traced** to provide a complete diagnostic:
- If left is missing and right can be resolved, right is traced
- If left can be resolved and it short-circuits, right is not traced (status: SHORT_CIRCUITED)

### Special case: `??` (null-coalescing)

`??` "absorbs" the absence or nullity of the left side:
- If left is absent or null → right is evaluated
- If left is present and non-null → right is skipped

The `??` node is represented as a **single leaf** (not a compound), with:
- `leftValue`: the value resolved on the left (`null` if absent, `null` if null)
- `rightValue`: the right-side value if the left was replaced (`null` otherwise)
- `leftMissing`: `true` if the left side was **absent** (missing variable), `false` if it was present but valued `null`. Allows distinguishing the two cases.

```php
$explainer->explain('a ?? 10', ['a' => null]);
// leftValue: null, rightValue: 10, leftMissing: false, passed: true (10 is truthy)

$explainer->explain('a ?? 10', []);
// leftValue: null, rightValue: 10, leftMissing: true, passed: true
```

### `ExplainResult::unresolved(): array`

Combines `missing()` and `errors()`: returns all leaves that prevented evaluation from completing, for whatever reason.

```php
$result = $explainer->explain('a > 0 AND b < 100', []);
// both a and b are missing

count($result->missing());    // 2
count($result->errors());     // 0
count($result->unresolved()); // 2 — equivalent to array_merge(missing, errors)
```

This is the most useful method in a back-office to answer "what blocked this expression?", without having to manually merge the two collections.

An `x NOT IN [...]` expression is represented in the AST as `UnaryNode(NOT, InNode(...))`. The Explainer treats it as a **single leaf** with `operator: 'NOT IN'` rather than producing a NOT compound containing an IN — this is more natural for the user.

The reconstructed expression is `"x NOT IN [...]"` (not `"NOT x IN [...]"` which would be invalid).

## Internal pipeline

The Explainer works in **two phases**:

1. **`buildTrace()`** — preliminary AST traversal that evaluates each node and stores its result (or its MISSING/ERROR status) in maps indexed by `spl_object_id()`. Respects short-circuits (AND/OR/ternary) to avoid evaluating what should not be.

2. **`explainNode()`** — producer traversal that builds the `ExplainNode` tree by consulting the already-traced values. No re-evaluation of functions at this stage.

This two-phase design allows:
- A complete trace of values before producing the tree (to distinguish "skipped" from "not yet reached")
- Protection against double evaluation of `FunctionNode` (functions are called once via `callFunction()` with their already-traced arguments)

## ⚠️ Cost: double evaluation of functions in compounds

This is the most important **structural limitation** of the Explainer.

### The mechanism

During `buildTrace()`:
- `FunctionNode` items are called via `callFunction()` with their **already-traced** arguments: a single call
- Other nodes (`BinaryNode`, `UnaryNode`, `InNode`, `TernaryNode`) are evaluated via `evaluateAst()` which **re-walks the entire sub-tree**

As a result, any function nested inside a compound node is **called a second time** when the parent compound evaluates its sub-tree via `evaluateAst()`.

### Quantification

The multiplier is **exactly 2 per occurrence**, no more. Not multiplicative with nesting depth:

```
counter() + counter()        → 4 calls  (2 occurrences × 2)
counter() > 0 AND counter()  → 6 calls  (3 occurrences × 2)
now() alone at root          → 1 call   (no parent compound)
```

### Consequence

**⚠️ Side-effect functions (counters, database writes, mail sends, etc.) must NEVER be used in expressions passed to `explain()` / `explainAst()`.**

This prohibition also applies to functions whose idempotence is not 100% guaranteed (reads with side effects, UUID generation, timestamps, etc.). When in doubt: do not use with the Explainer.

This restriction **does not apply** to other evaluation modes (`evaluate()`, `evaluateSafe()`, etc.) where each function is called exactly once.

### Why not fix it?

The alternative design (single-pass evaluation that produces the tree as it goes) has drawbacks:
- Harder to handle short-circuits cleanly (you need to know whether a branch was visited before producing the parent `ExplainNode`)
- Harder to distinguish "skipped" from "errored" without the full context

The current trade-off: the Explainer is a diagnostic tool, not a production evaluator. The constraint "no side-effect functions in explained expressions" is documented and acceptable.

## Expression reconstruction: `printNode()`

Each `ExplainNode` contains an `expression` string that is the local expression **reconstructed from the AST** (not extracted from the original string).

Benefits:
- Available even for nodes whose original source expression has been lost (typically with `explainAst()` on an AST loaded from `importAst()`)
- Normalized (spaces, parentheses): `'1+1'` and `'1 + 1'` produce the same output

The printer handles:
- Operator precedence (parentheses added at the minimum necessary)
- The `NOT IN` case (clean rendering, re-injectable into the Parser)
- Quoted literals with escaping (`L'Oréal` → `'L''Oréal'`)
- Lists `[1, 2, 3]`, booleans, `null`

The printer's precedence table is **synchronized** with the Parser's. If levels change in the Parser, they must change here too.

## Design decisions

### Indexing by `spl_object_id`

The `$trace` and `$errors` maps are indexed by `spl_object_id($node)`. Guarantees uniqueness even when the same node is referenced multiple times (DAG-like sharing tolerated by `importAst`).

No memory leak: the maps are reset on each call to `explainAst()` (`$this->trace = []`).

### Mutually exclusive: trace OR error

A node is either in `$trace` (successful evaluation) or in `$errors` (MISSING or ERROR status), **never both**. Allows unambiguous distinction between "value known" and "resolution impossible".

### Short-circuits faithful to strict mode

Short-circuits in `buildTrace()` reproduce **exactly** the semantics of `Evaluator::evaluate()`:
- AND with false left → right not visited
- OR with true left → right not visited
- Ternary → only the chosen branch is visited

No "best-effort" behavior that would explore untaken branches to give more information: that would be a dangerous divergence from actual evaluation.

### Unknown function error: `ERROR` not `MISSING`

An unregistered function produces `EvaluatorException("Unknown function...")`, classified as `ERROR`. This is consistent: an unknown function is an expression (or configuration) problem, not a data problem.

### Root `passed`: `null` if not evaluated

`ExplainResult::passed` is `null` when the root could not be fully evaluated (missing, error, short-circuited). This is distinct from `false`. It allows the caller to distinguish "rule evaluated and false" from "rule not evaluable".

### Leaf collection: O(n)

`failures()`, `successes()`, etc. use an accumulator passed by reference (no recursive `array_merge`). Important to stay linear in tree size.

### No "warnings"

The Explainer does not signal "soft" anomalies (e.g. a literal compared to itself `5 = 5` that will always be true). The diagnostic is purely runtime: what happened, not what might have been suspicious.

## Known limitations

### Side effects and double evaluation

See the dedicated section above. **Critical**: do not use side-effect functions in an explained expression.

### No evaluation history

The Explainer describes **one** call, not a history. To compare two evaluations (same rule, two contexts), make two calls and diff on the caller side.

### Tree serialization

`ExplainNode` and `ExplainResult` are standard objects, serializable via `json_encode()` directly (all properties are public readonly).

⚠️ `leftValue` / `rightValue` may contain arbitrary PHP values (arrays, etc.). JSON serialization handles scalars and arrays well; no object case to signal today since the evaluator rejects objects upstream.

### No semantic diff between nodes

Two semantically equivalent expressions (`'a > 0 AND b > 0'` and `'b > 0 AND a > 0'`) produce different trees. This is expected: the tree follows the AST structure, not a normalized form.
