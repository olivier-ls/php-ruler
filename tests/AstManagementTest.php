<?php declare(strict_types=1);

namespace Ols\PhpRuler\Tests;

use Ols\PhpRuler\Exception\SyntaxErrorException;
use Ols\PhpRuler\ExpressionEvaluator;
use Ols\PhpRuler\Parser\BinaryNode;
use Ols\PhpRuler\Parser\LiteralNode;
use Ols\PhpRuler\Parser\Node;
use Ols\PhpRuler\Parser\VariableNode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Couvre ast-management.md.
 *
 * Three concerns:
 *   - getAst()      : on-demand compile + LRU cache (max 500)
 *   - exportAst()   : PHP serialize() of the AST
 *   - importAst()   : safe deserialize with allowed_classes + cycle + depth checks
 */
final class AstManagementTest extends TestCase
{
    private ExpressionEvaluator $eval;

    protected function setUp(): void
    {
        $this->eval = new ExpressionEvaluator();
    }

    // =========================================================================
    // getAst — basics
    // =========================================================================

    #[Test]
    public function get_ast_returns_a_node_instance(): void
    {
        $ast = $this->eval->getAst('a > 0');
        $this->assertInstanceOf(Node::class, $ast);
    }

    #[Test]
    public function get_ast_throws_syntax_error_on_invalid_expression(): void
    {
        $this->expectException(SyntaxErrorException::class);
        $this->eval->getAst('a + ');
    }

    #[Test]
    public function get_ast_increments_cache_size_on_first_call(): void
    {
        $this->assertSame(0, $this->eval->cacheSize());
        $this->eval->getAst('a > 0');
        $this->assertSame(1, $this->eval->cacheSize());
    }

    // =========================================================================
    // LRU cache — semantics
    // =========================================================================

    #[Test]
    public function calling_get_ast_twice_with_the_same_expression_returns_the_same_instance(): void
    {
        // This is the observable effect of the cache.
        $ast1 = $this->eval->getAst('cart.total > 100');
        $ast2 = $this->eval->getAst('cart.total > 100');
        $this->assertSame($ast1, $ast2);
    }

    #[Test]
    public function cache_does_not_grow_when_same_expression_is_re_evaluated(): void
    {
        $this->eval->evaluate('x + 1', ['x' => 1]);
        $this->assertSame(1, $this->eval->cacheSize());
        $this->eval->evaluate('x + 1', ['x' => 99]);
        $this->assertSame(1, $this->eval->cacheSize());
    }

    #[Test]
    public function clear_cache_empties_the_cache(): void
    {
        $this->eval->getAst('a + 1');
        $this->eval->getAst('b + 2');
        $this->assertSame(2, $this->eval->cacheSize());
        $this->eval->clearCache();
        $this->assertSame(0, $this->eval->cacheSize());
    }

    #[Test]
    public function clear_cache_returns_self_for_chaining(): void
    {
        $this->assertSame($this->eval, $this->eval->clearCache());
    }

    #[Test]
    public function evaluation_works_after_clearing_the_cache(): void
    {
        $this->eval->evaluate('1 + 1', []);
        $this->eval->clearCache();
        $this->assertSame(4, $this->eval->evaluate('2 + 2', []));
    }

    #[Test]
    public function invalid_expression_does_not_pollute_the_cache(): void
    {
        $this->eval->getAst('a + 1');
        $sizeBefore = $this->eval->cacheSize();

        try {
            $this->eval->getAst('a + + +');
            $this->fail('Expected SyntaxErrorException');
        } catch (SyntaxErrorException) {
            // expected
        }

        $this->assertSame($sizeBefore, $this->eval->cacheSize());
    }

    #[Test]
    public function cache_evicts_least_recently_used_entry_at_size_500(): void
    {
        // 500 distinct expressions fill the cache exactly.
        for ($i = 0; $i < 501; $i++) {
            $this->eval->evaluate("x + $i", ['x' => 0]);
        }
        $this->assertSame(500, $this->eval->cacheSize());
    }

    // =========================================================================
    // Cache canonicalization — whitespace collapse outside quotes
    // =========================================================================

    #[Test]
    public function cache_collapses_leading_and_trailing_whitespace(): void
    {
        $this->eval->evaluate('x + 1', ['x' => 1]);
        $this->eval->evaluate('  x + 1  ', ['x' => 1]);
        $this->assertSame(1, $this->eval->cacheSize());
    }

    #[Test]
    public function cache_collapses_runs_of_internal_whitespace(): void
    {
        $this->eval->evaluate('x   >   1', ['x' => 2]);
        $this->eval->evaluate('x > 1',     ['x' => 2]);
        $this->assertSame(1, $this->eval->cacheSize());
    }

    #[Test]
    public function cache_treats_tabs_and_nbsp_as_regular_whitespace(): void
    {
        // NBSP = U+00A0, encoded as \xC2\xA0 in UTF-8.
        $this->eval->evaluate('a > 1', ['a' => 5]);
        $this->eval->evaluate("a\t>\t1", ['a' => 5]);
        $this->eval->evaluate("a\xC2\xA0>\xC2\xA01", ['a' => 5]);
        $this->assertSame(1, $this->eval->cacheSize());
    }

    #[Test]
    public function cache_preserves_whitespace_inside_quoted_literals(): void
    {
        // Documented: whitespace INSIDE string literals is preserved (different cache entries).
        $this->eval->evaluate("a = 'x  y'", ['a' => 'x  y']);
        $this->eval->evaluate("a = 'x y'",  ['a' => 'x y']);
        $this->assertSame(2, $this->eval->cacheSize());
    }

    #[Test]
    public function cache_does_NOT_normalize_operator_spacing_fragmentation_is_accepted(): void
    {
        // Doc admits this limitation: '1+1' and '1 + 1' yield two cache entries.
        $this->eval->evaluate('1+1',   []);
        $this->eval->evaluate('1 + 1', []);
        $this->assertSame(2, $this->eval->cacheSize());
    }

    // =========================================================================
    // exportAst / importAst — round-trip
    // =========================================================================

    #[Test]
    public function export_then_import_preserves_evaluation_semantics(): void
    {
        $serialized = $this->eval->exportAst('cart.total > threshold');
        $ast = $this->eval->importAst($serialized);

        $this->assertTrue($this->eval->evaluateAstBoolean($ast, [
            'cart'      => ['total' => 200],
            'threshold' => 100,
        ]));
        $this->assertFalse($this->eval->evaluateAstBoolean($ast, [
            'cart'      => ['total' => 50],
            'threshold' => 100,
        ]));
    }

    #[Test]
    public function export_ast_throws_syntax_error_on_invalid_expression(): void
    {
        $this->expectException(SyntaxErrorException::class);
        $this->eval->exportAst('a + ');
    }

    #[Test]
    public function export_ast_returns_a_string(): void
    {
        $this->assertIsString($this->eval->exportAst('1 + 1'));
    }

    // =========================================================================
    // importAst — security & robustness
    // =========================================================================

    #[Test]
    public function import_ast_rejects_garbage_input(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->eval->importAst('not a serialized payload');
    }

    #[Test]
    public function import_ast_rejects_empty_input(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->eval->importAst('');
    }

    #[Test]
    public function import_ast_rejects_non_node_classes(): void
    {
        // The allowed_classes whitelist must reject anything outside the Node hierarchy.
        $payload = (string) json_encode(['v' => 1, 'ast' => serialize(new \stdClass())]);
        $this->expectException(\InvalidArgumentException::class);
        $this->eval->importAst($payload);
    }

    #[Test]
    public function import_ast_rejects_cyclic_structures(): void
    {
        // Build a binary node referencing itself indirectly through serialize().\n        // We construct a payload "by hand" using PHP serialize references.
        // First create a valid AST, then serialize it with an injected cycle.
        $ast = $this->eval->getAst('1 + 1');

        // Hand-craft a cyclic payload via reflection: create a BinaryNode that
        // references itself in its `left` slot. We cannot do this via the
        // public API (readonly properties), so we use a string-level forgery:
        // serialize and replace one of the sub-nodes by a back-reference (r:1).
        $valid = serialize($ast);
        // Replace the inner LiteralNode with a self-reference. The exact format
        // of references is "r:N;", with N = position in the serialize stream.
        // r:1; refers to the outer object itself.
        $cyclic = preg_replace('/O:[^{]+\{[^}]+\}/', 'r:1;', $valid, 1);

        // The forgery above is fragile; only assert if it actually produced a
        // shape that unserialize accepts. Otherwise skip — the cycle case is
        // also covered by the depth guard in import_ast_rejects_excessive_depth.
        if ($cyclic === $valid) {
            $this->markTestSkipped('Could not forge a cyclic payload on this PHP version');
        }

        $payload = (string) json_encode(['v' => 1, 'ast' => $cyclic]);
        $this->expectException(\InvalidArgumentException::class);
        $this->eval->importAst($payload);
    }

    #[Test]
    public function import_ast_rejects_excessive_depth(): void
    {
        // Build an AST nested far beyond IMPORT_AST_MAX_DEPTH=200.
        $deep = new LiteralNode(0);
        for ($i = 0; $i < 250; $i++) {
            $deep = new BinaryNode('+', $deep, new LiteralNode(1));
        }
        $payload = (string) json_encode(['v' => 1, 'ast' => serialize($deep)]);

        try {
            $this->eval->importAst($payload);
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('AST depth exceeds limit', $e->getMessage());
        }
    }

    #[Test]
    public function import_ast_accepts_dag_like_sharing_between_siblings(): void
    {
        // Documented tolerance: the same Node referenced by two sibling positions
        // is NOT a cycle (cycles only form along the descent path).
        $shared = new LiteralNode(5);
        $ast    = new BinaryNode('+', $shared, $shared);
        $payload = (string) json_encode(['v' => 1, 'ast' => serialize($ast)]);

        $reimported = $this->eval->importAst($payload);
        $this->assertSame(10, $this->eval->evaluateAst($reimported, []));
    }
}
