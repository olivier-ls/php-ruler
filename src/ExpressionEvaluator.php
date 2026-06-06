<?php declare(strict_types=1);
namespace Ols\PhpRuler;

use Ols\PhpRuler\ContextResolver;
use Ols\PhpRuler\Evaluator\Evaluator;
use Ols\PhpRuler\Exception\{SyntaxErrorException, TypeErrorException};
use Ols\PhpRuler\Lexer\Lexer;
use Ols\PhpRuler\Parser\Node;
use Ols\PhpRuler\Parser\Parser;
use Ols\PhpRuler\Evaluator\SafeResult;

final class ExpressionEvaluator
{
    private Lexer     $lexer;
    private Parser    $parser;
    private Evaluator $evaluator;

    /**
     * Cache AST indexé par expression canonicalisée.
     *
     * La clé de cache est l'expression normalisée whitespace-only via
     * canonicalizeForCache() : seuls les runs de whitespaces sont collapsés
     * (hors littéraux quotés). Aucune normalisation lexicale plus poussée
     * n'est effectuée — notamment, l'espacement autour des opérateurs n'est
     * pas régularisé. Conséquence : "1+1" et "1 + 1" sont sémantiquement
     * équivalents pour le Lexer mais produisent DEUX entrées de cache
     * distinctes. C'est un choix assumé : une vraie canonicalisation
     * impliquerait une tokenisation partielle ici, ce qui dupliquerait
     * la grammaire du Lexer dans la couche cache.
     *
     * @var array<string, Node>
     */
    private array $cache = [];

    private int $cacheMaxSize;

    /**
     * @param int $cacheMaxSize Maximum number of compiled ASTs to keep in the
     *                          LRU cache. 0 disables caching entirely.
     *                          Increase for batch processing of many distinct
     *                          expressions; decrease (or set to 0) when memory
     *                          is constrained.
     * @throws \InvalidArgumentException if $cacheMaxSize is negative.
     */
    public function __construct(int $cacheMaxSize = 500)
    {
        if ($cacheMaxSize < 0) {
            throw new \InvalidArgumentException(
                'ExpressionEvaluator: $cacheMaxSize must be >= 0 (0 = disabled), ' . $cacheMaxSize . ' given.'
            );
        }
        $this->cacheMaxSize = $cacheMaxSize;
        $this->lexer        = new Lexer();
        $this->parser       = new Parser();
        $this->evaluator    = new Evaluator();
    }

    // -------------------------------------------------------------------------
    // API publique
    // -------------------------------------------------------------------------

    public function evaluate(string $expression, array $context): mixed
    {
        return $this->evaluator->evaluate($this->getAst($expression), $context);
    }

    public function evaluateBoolean(string $expression, array $context): bool
    {
        $result = $this->evaluate($expression, $context);

        if (!is_bool($result)) {
            throw new TypeErrorException(
                "Expression \"$expression\" does not return a boolean (returned: " . gettype($result) . ")"
            );
        }

        return $result;
    }

    public function evaluateNumeric(string $expression, array $context): float
    {
        $result = $this->evaluate($expression, $context);

        if (!is_int($result) && !is_float($result)) {
            throw new TypeErrorException(
                "Expression \"$expression\" does not return a number (returned: " . gettype($result) . ")"
            );
        }

        return (float) $result;
    }

    // Evaluates a pre-compiled AST directly — bypasses Lexer and Parser
    public function evaluateAst(Node $ast, array $context): mixed
    {
        return $this->evaluator->evaluate($ast, $context);
    }

    public function evaluateAstBoolean(Node $ast, array $context): bool
    {
        $result = $this->evaluateAst($ast, $context);

        if (!is_bool($result)) {
            throw new TypeErrorException(
                "The provided AST does not return a boolean (returned: " . gettype($result) . ")"
            );
        }

        return $result;
    }

    public function evaluateAstNumeric(Node $ast, array $context): float
    {
        $result = $this->evaluateAst($ast, $context);

        if (!is_int($result) && !is_float($result)) {
            throw new TypeErrorException(
                "The provided AST does not return a number (returned: " . gettype($result) . ")"
            );
        }

        return (float) $result;
    }

    // -------------------------------------------------------------------------
    // Safe evaluation — missing variables are collected, not thrown
    // -------------------------------------------------------------------------

    /**
     * Evaluates an expression without throwing on missing context variables.
     *
     * missingVars answers "what was needed but absent?", not "what was absent overall?".
     * This distinction matters for ?? (null-coalescing):
     *   - a ?? b  with a absent  → b is evaluated; if b is also absent → missing=['b'] only.
     *     'a' is intentionally absent from missingVars: its absence is the ?? nominal case,
     *     not a failure. Only what was ultimately needed and missing is reported.
     *   - a ?? b  with a = null  → same result, missing=['b'] if b absent. a was present.
     *   - a ?? b  with a present → missing=[]; b never evaluated.
     *
     * Short-circuit semantics are fully preserved:
     *   - false AND <missing>  → SafeResult(true,  false, [])       — right never evaluated
     *   - true  OR  <missing>  → SafeResult(true,  true,  [])       — right never evaluated
     *   - <missing> AND false  → SafeResult(false, null,  ['path']) — left missing, reported
     *   - <missing> OR  true   → SafeResult(false, null,  ['path']) — left missing, reported
     *   - <missing> AND <expr> → SafeResult(false, null,  ['path']) — left was needed
     *
     * Note on <missing> AND false and <missing> OR true:
     * Even though the result is determined by the right side alone, the left variable
     * is still reported as missing. "Safe" answers "was the context complete?", not just
     * "was the result computable?". A caller receiving success=false knows the context
     * was incomplete — regardless of whether the final value could be inferred.
     *
     * Other exceptions (TypeErrorException, EvaluatorException) still propagate normally.
     * "Safe" means: UnknownVariableException is caught and collected, not suppressed entirely.
     * TypeErrorException is never swallowed — a type error is a code bug, not a data issue.
     */
    public function evaluateSafe(string $expression, array $context): SafeResult
    {
        return $this->evaluator->evaluateSafe($this->getAst($expression), $context);
    }

    public function evaluateSafeAst(Node $ast, array $context): SafeResult
    {
        return $this->evaluator->evaluateSafe($ast, $context);
    }

    // -------------------------------------------------------------------------
    // Static AST analysis
    // -------------------------------------------------------------------------

    /**
     * Returns all variable paths referenced in an expression, deduplicated and sorted.
     * Purely static — no context needed, no evaluation performed.
     * All branches are walked regardless of short-circuit or ternary logic.
     *
     * extractVariables('a > 0 AND b.c = d ?? e')
     *   → ['a', 'b.c', 'd', 'e']
     *
     * @return string[]
     */
    public function extractVariables(string $expression): array
    {
        $vars = [];
        $this->walkAst($this->getAst($expression), function (Node $node) use (&$vars): void {
            if ($node instanceof \Ols\PhpRuler\Parser\VariableNode) {
                $vars[] = $node->path;
            }
        });
        $vars = array_values(array_unique($vars));
        sort($vars);
        return $vars;
    }

    /**
     * Returns all function names used in an expression, deduplicated and sorted.
     * Purely static — no context needed, no evaluation performed.
     *
     * Useful for validating that an expression only calls registered functions
     * before evaluating it, e.g. in a backoffice where rules are user-defined.
     *
     * extractFunctions('round(a, 2) > min(b, c)')
     *   → ['min', 'round']
     *
     * @return string[]
     */
    public function extractFunctions(string $expression): array
    {
        $fns = [];
        $this->walkAst($this->getAst($expression), function (Node $node) use (&$fns): void {
            if ($node instanceof \Ols\PhpRuler\Parser\FunctionNode) {
                $fns[] = $node->name;
            }
        });
        $fns = array_values(array_unique($fns));
        sort($fns);
        return $fns;
    }

    /**
     * Generic AST walker — visits every node depth-first and calls $visitor on each.
     * Used by extractVariables() and extractFunctions().
     */
    private function walkAst(Node $node, callable $visitor): void
    {
        $visitor($node);

        if ($node instanceof \Ols\PhpRuler\Parser\UnaryNode) {
            $this->walkAst($node->operand, $visitor);
        } elseif ($node instanceof \Ols\PhpRuler\Parser\BinaryNode) {
            $this->walkAst($node->left,  $visitor);
            $this->walkAst($node->right, $visitor);
        } elseif ($node instanceof \Ols\PhpRuler\Parser\InNode) {
            $this->walkAst($node->subject, $visitor);
            $this->walkAst($node->list,    $visitor);
        } elseif ($node instanceof \Ols\PhpRuler\Parser\TernaryNode) {
            $this->walkAst($node->condition, $visitor);
            $this->walkAst($node->then,      $visitor);
            $this->walkAst($node->else,      $visitor);
        } elseif ($node instanceof \Ols\PhpRuler\Parser\FunctionNode) {
            foreach ($node->args as $arg) {
                $this->walkAst($arg, $visitor);
            }
        }
        // LiteralNode and VariableNode are leaves — no children to recurse into
    }

    // -------------------------------------------------------------------------
    // AST serialization — persistent / external cache
    // -------------------------------------------------------------------------

    /**
     * Current export format version.
     * Bump this constant whenever a change to the Node class hierarchy would
     * make previously-exported strings incompatible (new properties, renamed
     * classes, removed nodes, etc.).
     *
     * importAst() rejects any payload whose "v" field does not match this value,
     * so callers are forced to re-compile expressions after a version change
     * rather than silently running with a mismatched AST.
     */
    private const AST_EXPORT_VERSION = 1;

    /**
     * Serializes a compiled AST to a string suitable for external storage (database, cache, file).
     * The serialized string can later be restored via importAst() without going through
     * the Lexer or Parser.
     *
     * The output is a JSON envelope {"v": <version>, "ast": "<serialized>"} wrapping
     * the PHP-serialized AST. The version field allows importAst() to detect and reject
     * stale exports produced by an incompatible version of the library.
     *
     * @security Never pass a serialized string from an untrusted source to importAst().
     *           PHP's unserialize() can execute arbitrary code if the input has been tampered with.
     *           This is safe as long as the serialized data originates from your own application.
     */
    public function exportAst(string $expression): string
    {
        return (string) json_encode([
            'v'   => self::AST_EXPORT_VERSION,
            'ast' => serialize($this->getAst($expression)),
        ]);
    }

    /**
     * Restores an AST from a serialized string produced by exportAst().
     * Bypasses the Lexer and Parser entirely — use this for cached expressions.
     *
     * Validation policy (audit B13):
     *   - The JSON envelope is decoded and the "v" field is checked against
     *     AST_EXPORT_VERSION. A mismatch means the payload was produced by an
     *     incompatible version of the library — the caller must re-compile.
     *   - unserialize() runs with allowed_classes restricted to the lib's own
     *     Node hierarchy, so no foreign class can be instantiated.
     *   - On top of that, the deserialized graph is walked once to detect
     *     cyclic references (which PHP's serialize/unserialize do support)
     *     and excessive depth. Without this, a tampered payload containing
     *     a self-referential BinaryNode would stack-overflow inside
     *     Evaluator::evaluate(). The evaluator now has its own depth guard
     *     (audit I8) as a defence in depth, but catching the malformed AST
     *     at the import boundary yields a clearer, earlier error.
     *
     * Cycle detection uses SplObjectStorage tracking on the active recursion
     * path: a node is considered cyclic if and only if it is visited a second
     * time on the same descent. This deliberately allows DAG-like sharing
     * (the same literal node referenced from two arguments of a function), in
     * line with what an export/import round-trip on a tree could legitimately
     * produce if a future optimisation pass introduced node interning.
     *
     * @security Never call this with data from an untrusted source. See exportAst().
     * @throws \InvalidArgumentException if the string is not a valid export payload,
     *         if the version does not match, or if the deserialized AST contains
     *         a cycle / exceeds depth limit.
     */
    public function importAst(string $serialized): Node
    {
        $envelope = json_decode($serialized, true);

        if (!is_array($envelope) || !isset($envelope['v'], $envelope['ast'])) {
            throw new \InvalidArgumentException(
                'importAst(): the provided string is not a valid php-ruler AST export (expected JSON envelope)'
            );
        }

        if ($envelope['v'] !== self::AST_EXPORT_VERSION) {
            throw new \InvalidArgumentException(
                'importAst(): AST export version mismatch — got v' . $envelope['v'] .
                ', expected v' . self::AST_EXPORT_VERSION .
                '. Re-compile the expression with exportAst() to refresh the stored payload.'
            );
        }

        $ast = @unserialize($envelope['ast'], ['allowed_classes' => [
            \Ols\PhpRuler\Parser\BinaryNode::class,
            \Ols\PhpRuler\Parser\UnaryNode::class,
            \Ols\PhpRuler\Parser\LiteralNode::class,
            \Ols\PhpRuler\Parser\VariableNode::class,
            \Ols\PhpRuler\Parser\InNode::class,
            \Ols\PhpRuler\Parser\FunctionNode::class,
            \Ols\PhpRuler\Parser\TernaryNode::class,
        ]]);

        if (!$ast instanceof Node) {
            throw new \InvalidArgumentException(
                'importAst(): the provided string is not a valid serialized AST node'
            );
        }

        $this->validateAstStructure($ast, 0, new \SplObjectStorage());

        return $ast;
    }

    /**
     * Maximum AST depth accepted by importAst().
     * Mirrors Evaluator::MAX_EVAL_DEPTH for consistency — any AST that would
     * trip the evaluator's depth guard is rejected at the import boundary
     * with a clearer message.
     */
    private const IMPORT_AST_MAX_DEPTH = 200;

    /**
     * Walks an imported AST to validate its structural integrity.
     *
     * Two checks:
     *   1. Cycle detection: any node visited twice on the active path is
     *      reported as a cyclic reference. We attach() on descent and
     *      detach() on ascent, so node interning (same node referenced
     *      from sibling branches) does not trigger a false positive.
     *   2. Depth limit: caps recursion at IMPORT_AST_MAX_DEPTH to bound
     *      validation cost on pathologically deep trees, and to surface
     *      a clear "depth exceeded" message before the evaluator's own
     *      guard kicks in.
     *
     * Properties whose declared type is `Node` (left, right, operand,
     * subject, list, condition, then, else, args[*]) are recursed into.
     * Anything else (operator strings, literal values, function names)
     * is a leaf for the purpose of validation.
     */
    private function validateAstStructure(Node $node, int $depth, \SplObjectStorage $seen): void
    {
        if ($depth > self::IMPORT_AST_MAX_DEPTH) {
            throw new \InvalidArgumentException(
                'importAst(): AST depth exceeds limit (' . self::IMPORT_AST_MAX_DEPTH .
                '). Likely a pathologically deep or cyclic AST.'
            );
        }
        if ($seen->contains($node)) {
            throw new \InvalidArgumentException(
                'importAst(): cyclic reference detected in AST (a node references itself, ' .
                'directly or transitively). Refusing to evaluate.'
            );
        }
        $seen->attach($node);

        try {
            if ($node instanceof \Ols\PhpRuler\Parser\BinaryNode) {
                $this->validateAstStructure($node->left,  $depth + 1, $seen);
                $this->validateAstStructure($node->right, $depth + 1, $seen);
            } elseif ($node instanceof \Ols\PhpRuler\Parser\UnaryNode) {
                $this->validateAstStructure($node->operand, $depth + 1, $seen);
            } elseif ($node instanceof \Ols\PhpRuler\Parser\InNode) {
                $this->validateAstStructure($node->subject, $depth + 1, $seen);
                $this->validateAstStructure($node->list,    $depth + 1, $seen);
            } elseif ($node instanceof \Ols\PhpRuler\Parser\TernaryNode) {
                $this->validateAstStructure($node->condition, $depth + 1, $seen);
                $this->validateAstStructure($node->then,      $depth + 1, $seen);
                $this->validateAstStructure($node->else,      $depth + 1, $seen);
            } elseif ($node instanceof \Ols\PhpRuler\Parser\FunctionNode) {
                foreach ($node->args as $arg) {
                    if (!$arg instanceof Node) {
                        throw new \InvalidArgumentException(
                            'importAst(): FunctionNode argument is not a Node instance'
                        );
                    }
                    $this->validateAstStructure($arg, $depth + 1, $seen);
                }
            }
            // LiteralNode and VariableNode are leaves — nothing to recurse into.
        } finally {
            // detach() on ascent so siblings sharing a leaf (node interning)
            // don't trigger a spurious cycle. Cycles can only form along the
            // active descent path, not across siblings.
            $seen->detach($node);
        }
    }

    // Exposes the compiled AST — allows the developer to cache it externally
    public function getAst(string $expression): Node
    {
        // Cache key canonicalization: collapse whitespace runs to a single space,
        // but ONLY outside quoted string literals. The expression itself is passed
        // to the Lexer unchanged — the Lexer is independently tolerant to whitespace
        // between tokens (its regex skips any unmatched whitespace).
        // See canonicalizeForCache() for the why.
        $cacheKey = $this->canonicalizeForCache($expression);

        if ($this->cacheMaxSize > 0 && isset($this->cache[$cacheKey])) {
            // Déplace l'entrée en fin de tableau pour simuler LRU
            $node = $this->cache[$cacheKey];
            unset($this->cache[$cacheKey]);
            $this->cache[$cacheKey] = $node;
            return $node;
        }

        // Parse first — if tokenize() or parse() throws, the cache must not be modified.
        // Evicting a valid entry before knowing whether the new expression is valid would
        // silently shrink the cache on every invalid expression call.
        $tokens = $this->lexer->tokenize($expression);
        $node   = $this->parser->parse($tokens);

        if ($this->cacheMaxSize > 0) {
            // Éviction de la plus ancienne entrée si le cache est plein
            if (count($this->cache) >= $this->cacheMaxSize) {
                reset($this->cache);
                unset($this->cache[key($this->cache)]);
            }
            $this->cache[$cacheKey] = $node;
        }

        return $node;
    }

    /**
     * Builds a canonical cache key by trimming surrounding whitespace and collapsing
     * internal runs of whitespace to a single space — but ONLY outside quoted string
     * literals.
     *
     *   "a > 1", "  a > 1  ", "a  >  1", "a\t>\t1"  →  all map to "a > 1"
     *   "a = 'x  y'", "a = 'x y'"                   →  remain DISTINCT keys
     *
     * Preserving whitespace inside quoted literals is essential: 'hello   world'
     * and 'hello world' are different string values, and must not share a cache
     * entry — sharing one would mean the second call returns an AST whose embedded
     * literal differs from what the user wrote.
     *
     * The split strategy mirrors AliasResolver::replaceOutsideStrings(). If you
     * extend the quoted-literal grammar (e.g. backtick strings, raw strings),
     * update both call sites.
     *
     * NBSP (U+00A0) is matched by \s in /u mode and collapsed like any other
     * whitespace outside literals — consistent with the Lexer's NBSP-as-space
     * policy for code-level whitespace.
     *
     * LIMIT — whitespace-only canonicalization:
     * The normalization handles whitespace runs but does NOT regularize the
     * spacing around operators. Expressions that are semantically equivalent
     * for the Lexer but lexically distinct still produce separate cache entries:
     *
     *   "1+1"   →  key "1+1"
     *   "1 + 1" →  key "1 + 1"     ← different entry, same AST
     *   "a>1"   →  key "a>1"
     *   "a > 1" →  key "a > 1"     ← different entry, same AST
     *
     * This is intentional. A "true" canonicalization (inserting a single space
     * around each operator) would require tokenizing the expression here, which
     * would duplicate the Lexer's grammar in the cache layer. With CACHE_MAX_SIZE
     * at 500, the resulting fragmentation is sub-optimal but not problematic.
     * Callers that build expressions programmatically and want maximum cache reuse
     * should produce them with a consistent spacing style.
     */
    private function canonicalizeForCache(string $expression): string
    {
        // /u activates UTF-8 mode (audit B3). Without it, the split regex
        // operated byte-by-byte, which happened to work for the current alphabet
        // (the Lexer only accepts ASCII identifiers, and quoted bytes are passed
        // through verbatim) but was inconsistent with the /u flag on the
        // whitespace-collapsing regex below. The two regexes must use the same
        // mode to keep their behaviour aligned if the grammar is ever extended
        // to accept Unicode identifiers.
        $pattern = '/(?P<quoted>\'(?:[^\']|\'\')*\'|"(?:[^"]|"")*")/u';
        $parts = preg_split(
            $pattern,
            $expression,
            flags: PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );
        if ($parts === false) {
            // Under /u, false signals malformed UTF-8. Fall back to the raw
            // expression as the cache key rather than corrupt the cache with
            // an empty key (which would collide across all malformed inputs).
            // The downstream Lexer will reject the invalid input with a clear
            // error — at worst the cache holds an extra (invalid) entry that
            // is never reused.
            return $expression;
        }

        $out = '';
        foreach ($parts as $part) {
            // A non-empty $part starting with ' or " is necessarily a quoted literal
            // segment returned by the capture group — unquoted segments are split AT
            // the quotes by preg_split and never start with one.
            if ($part !== '' && ($part[0] === "'" || $part[0] === '"')) {
                $out .= $part;
            } else {
                $normalized = preg_replace('/\s+/u', ' ', $part);
                if ($normalized === null) {
                    // Defensive: under /u, preg_replace returns null on malformed
                    // UTF-8 inside the unquoted segment. Fall back to a non-/u
                    // collapse for THIS segment rather than poisoning the whole
                    // cache key with null (which would coerce to "" via the
                    // concatenation and produce a colliding key for all such
                    // inputs). The Lexer will reject the malformed input
                    // immediately after, so the cache entry — if written — is
                    // harmless.
                    $normalized = preg_replace('/\s+/', ' ', $part) ?? $part;
                }
                $out .= $normalized;
            }
        }

        return trim($out);
    }

    public function validate(string $expression): void
    {
        $this->getAst($expression);
    }

    /**
     * Registers a custom function, overriding any built-in with the same name.
     *
     * @warning Functions with side effects (incrementing counters, writing to DB, sending emails…)
     *          must NOT be used in expressions passed to ExpressionExplainer::explain() or
     *          explainAst(). The Explainer may call each function more than once per invocation
     *          as part of its diagnostic trace. All other evaluation paths (evaluate(),
     *          evaluateSafe(), evaluateAst()…) call each function exactly once.
     */
    public function registerFunction(string $name, callable $fn): self
    {
        $this->evaluator->registerFunction($name, $fn);
        return $this;
    }

    /**
     * Returns the names of all registered functions (built-in + custom), sorted alphabetically.
     * Useful for backoffice autocompletion or validating expressions before evaluation.
     *
     * @return string[]
     */
    public function getFunctions(): array
    {
        return $this->evaluator->getFunctionNames();
    }

    /**
     * Calls a registered function with already-resolved arguments.
     * Exposed for ExpressionExplainer — avoids double evaluation of args.
     */
    public function callFunction(string $name, array $resolvedArgs): mixed
    {
        return $this->evaluator->callFunction($name, $resolvedArgs);
    }

    public function clearCache(): self
    {
        $this->cache = [];
        return $this;
    }

    public function cacheSize(): int
    {
        return count($this->cache);
    }

    // -------------------------------------------------------------------------
    // Context access
    // -------------------------------------------------------------------------

    // Resolves a path — throws UnknownVariableException if not found
    public function getContextValue(string $path, array $context): mixed
    {
        return ContextResolver::resolve($path, $context);
    }

    // Returns the value or a default if the path does not exist
    public function getContextValueOrDefault(string $path, array $context, mixed $default = null): mixed
    {
        return ContextResolver::get($path, $context, $default);
    }

    // Checks whether a path exists in the context
    public function hasContextValue(string $path, array $context): bool
    {
        return ContextResolver::has($path, $context);
    }

    // Describes the context — useful for displaying available variables in a backoffice
    public function describeContext(array $context): array
    {
        return ContextResolver::describe($context);
    }

}
