<?php declare(strict_types=1);

namespace Ols\PhpRuler\Tests;

use Ols\PhpRuler\Explainer\ExplainResult;
use Ols\PhpRuler\Explainer\ExplainStatus;
use Ols\PhpRuler\Explainer\ExpressionExplainer;
use Ols\PhpRuler\ExpressionEvaluator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Couvre explainer.md.
 *
 * The Explainer is a diagnostic tool: it produces a tree representation of
 * how each node behaved (evaluated / short-circuited / missing / error).
 * It does NOT throw on missing variables or runtime errors — these become
 * statuses in the tree.
 *
 * Reminder: do not put side-effect functions in an explained expression
 * (functions are called twice in compounds — see the doc).
 */
final class ExplainerTest extends TestCase
{
    private ExpressionEvaluator $eval;
    private ExpressionExplainer $explainer;

    protected function setUp(): void
    {
        $this->eval      = new ExpressionEvaluator();
        $this->explainer = new ExpressionExplainer($this->eval);
    }

    // =========================================================================
    // explain() — basics
    // =========================================================================

    #[Test]
    public function explain_returns_an_explain_result(): void
    {
        $this->assertInstanceOf(
            ExplainResult::class,
            $this->explainer->explain('5 > 3', [])
        );
    }

    #[Test]
    public function explain_root_passed_reflects_overall_result(): void
    {
        $this->assertTrue ($this->explainer->explain('5 > 3', [])->passed);
        $this->assertFalse($this->explainer->explain('5 < 3', [])->passed);
    }

    #[Test]
    public function explain_root_passed_is_null_when_root_was_not_fully_evaluated(): void
    {
        // Doc: passed === null distinguishes "rule evaluated and false" from "rule not evaluable".
        $result = $this->explainer->explain('missing > 0', []);
        $this->assertNull($result->passed);
    }

    // =========================================================================
    // Statuts — EVALUATED / SHORT_CIRCUITED / MISSING / ERROR
    // =========================================================================

    #[Test]
    public function evaluated_leaf_has_a_passed_value(): void
    {
        $result = $this->explainer->explain('5 > 3', []);
        $this->assertSame(ExplainStatus::EVALUATED, $result->root->status);
        $this->assertTrue($result->root->passed);
    }

    #[Test]
    public function missing_variable_produces_missing_status_with_detail(): void
    {
        $result = $this->explainer->explain('a > 0', []);
        $this->assertSame(ExplainStatus::MISSING, $result->root->status);
        $this->assertStringContainsString('Unknown variable', $result->root->detail ?? '');
    }

    #[Test]
    public function type_error_produces_error_status_with_detail(): void
    {
        $result = $this->explainer->explain('1 / 0', []);
        $this->assertSame(ExplainStatus::ERROR, $result->root->status);
        $this->assertStringContainsString('Division by zero', $result->root->detail ?? '');
    }

    #[Test]
    public function short_circuit_marks_right_side_as_skipped(): void
    {
        // false AND <missing> — the AND short-circuits, so 'missing' is SKIPPED, not MISSING.
        $result = $this->explainer->explain('false AND missing', []);
        $this->assertSame(ExplainStatus::EVALUATED, $result->root->status);
        $this->assertFalse($result->root->passed);

        // The right child should be marked SHORT_CIRCUITED.
        $rightChild = $result->root->children[1] ?? null;
        $this->assertNotNull($rightChild);
        $this->assertSame(ExplainStatus::SHORT_CIRCUITED, $rightChild->status);
    }

    // =========================================================================
    // explain() — never throws on runtime issues
    // =========================================================================

    #[Test]
    public function explain_does_not_throw_on_missing_variable(): void
    {
        // What would be UnknownVariableException in evaluate() becomes a tree node here.
        $result = $this->explainer->explain('a + b', []);
        // The root may be ERROR (if the compound node propagates the error) or
        // MISSING (if the missing variable status bubbles up). Both are valid.
        $this->assertContains(
            $result->root->status,
            [ExplainStatus::ERROR, ExplainStatus::MISSING],
        );
    }

    #[Test]
    public function explain_does_not_throw_on_division_by_zero(): void
    {
        $result = $this->explainer->explain('1 / 0', []);
        $this->assertSame(ExplainStatus::ERROR, $result->root->status);
    }

    #[Test]
    public function explain_does_throw_on_syntax_error(): void
    {
        // Syntax errors are compile-time — there is nothing to explain.
        $this->expectException(\Ols\PhpRuler\Exception\SyntaxErrorException::class);
        $this->explainer->explain('a + ', []);
    }

    // =========================================================================
    // failures / successes / leaves / missing / errors
    // =========================================================================

    #[Test]
    public function leaves_collections_separate_outcomes(): void
    {
        // (a > 0) AND (b > 0)
        // a=5, b=missing  →  left passes, right is MISSING
        $result = $this->explainer->explain('a > 0 AND b > 0', ['a' => 5]);

        $this->assertCount(1, $result->successes());
        $this->assertCount(1, $result->missing());
        $this->assertCount(0, $result->failures());
        $this->assertCount(0, $result->errors());
    }

    #[Test]
    public function failures_returns_only_evaluated_false_leaves(): void
    {
        // a > 0 AND b > 0 with a=5, b=-1 — left passes, right fails.
        // AND evaluates both branches since left is true (no short-circuit).
        $result = $this->explainer->explain('a > 0 AND b > 0', ['a' => 5, 'b' => -1]);

        $this->assertCount(1, $result->failures());
        $this->assertCount(1, $result->successes());
    }

    #[Test]
    public function leaves_includes_skipped_ones(): void
    {
        // Doc commit: leaves() returns ALL leaves, including SHORT_CIRCUITED ones.
        // Callers wanting only visited leaves must filter on isEvaluated().
        $result = $this->explainer->explain('false AND b > 0', []);

        $skippedLeaves = array_filter(
            $result->leaves(),
            fn($leaf) => $leaf->status === ExplainStatus::SHORT_CIRCUITED
        );
        $this->assertNotEmpty($skippedLeaves);
    }

    // =========================================================================
    // Représentation : compound vs leaf
    // =========================================================================

    #[Test]
    public function and_or_not_ternary_are_compound_nodes_with_children(): void
    {
        $and = $this->explainer->explain('true AND true', [])->root;
        $this->assertTrue($and->isCompound());
        $this->assertCount(2, $and->children);

        $or = $this->explainer->explain('true OR false', [])->root;
        $this->assertCount(2, $or->children);

        $not = $this->explainer->explain('NOT true', [])->root;
        $this->assertCount(1, $not->children);

        $ternary = $this->explainer->explain('true ? 1 : 2', [])->root;
        $this->assertCount(3, $ternary->children);
    }

    #[Test]
    public function comparison_is_a_leaf_carrying_left_and_right_values(): void
    {
        $result = $this->explainer->explain('a > 100', ['a' => 150]);
        $this->assertTrue($result->root->isLeaf());
        $this->assertSame(150, $result->root->leftValue);
        $this->assertSame(100, $result->root->rightValue);
    }

    #[Test]
    public function in_node_is_a_leaf_with_in_operator(): void
    {
        $result = $this->explainer->explain("'php' IN tags", ['tags' => ['php', 'js']]);
        $this->assertTrue($result->root->isLeaf());
        $this->assertSame('IN', $result->root->operator);
    }

    #[Test]
    public function not_in_is_rendered_as_a_single_leaf_with_natural_label(): void
    {
        // Documented: NOT IN is a special compound representation — flat leaf, label 'NOT IN'.
        $result = $this->explainer->explain('5 NOT IN [1, 2, 3]', []);
        $this->assertTrue($result->root->isLeaf());
        $this->assertSame('NOT IN', $result->root->operator);
    }

    // =========================================================================
    // ?? — leaf with leftMissing distinguishing absent vs null
    // =========================================================================

    #[Test]
    public function coalesce_with_missing_left_sets_left_missing_true(): void
    {
        $result = $this->explainer->explain('a ?? 10', []);
        $this->assertTrue($result->root->isLeaf());
        $this->assertTrue($result->root->leftMissing);
        $this->assertSame(10, $result->root->rightValue);
    }

    #[Test]
    public function coalesce_with_null_left_sets_left_missing_false(): void
    {
        $result = $this->explainer->explain('a ?? 10', ['a' => null]);
        $this->assertFalse($result->root->leftMissing);
        $this->assertSame(10, $result->root->rightValue);
    }

    // =========================================================================
    // Reconstruction d'expression : printNode (audit B4)
    // =========================================================================

    #[Test]
    public function explain_node_carries_reconstructed_expression_string(): void
    {
        $result = $this->explainer->explain('a > 100', ['a' => 50]);
        $this->assertSame('a > 100', $result->root->expression);
    }

    #[Test]
    public function reconstructed_expression_normalizes_whitespace(): void
    {
        // Both inputs produce the same printed form.
        $a = $this->explainer->explain('1+1', []);
        $b = $this->explainer->explain('1 + 1', []);
        $this->assertSame($a->root->expression, $b->root->expression);
    }

    #[Test]
    public function round_trip_print_then_parse_preserves_evaluation_result(): void
    {
        // Audit B4: printNode result must be re-parsable into an AST that
        // evaluates to the same value (under the same context).
        $cases = [
            ['(true ? 1 : 2) + 1', [], 2],
            ['(a > 0 ? 1 : 2) = 1', ['a' => 5], true],
            ['10 - (a ? 1 : 0)', ['a' => true], 9],
            ['(a ? 1 : 2) IN [1, 2]', ['a' => true], true],
        ];

        foreach ($cases as [$expr, $ctx, $expected]) {
            $original = $this->eval->evaluate($expr, $ctx);
            $reconstructed = $this->explainer->explain($expr, $ctx)->root->expression;
            $reparsed = $this->eval->evaluate($reconstructed, $ctx);

            $this->assertSame(
                $expected,
                $original,
                "Sanity check failed for '$expr'"
            );
            $this->assertSame(
                $original,
                $reparsed,
                "Round-trip mismatch: '$expr' → '$reconstructed'"
            );
        }
    }

    #[Test]
    public function printed_form_escapes_quotes_correctly(): void
    {
        // L'Oréal must be re-parseable.
        $result = $this->explainer->explain("a = 'L''Oréal'", ['a' => "L'Oréal"]);
        $reparsed = $this->eval->evaluate($result->root->expression, ['a' => "L'Oréal"]);
        $this->assertTrue($reparsed);
    }

    // =========================================================================
    // Custom function exceptions — classified as ERROR with detail
    // (Audit B7: hors lib exceptions are wrapped with previous)
    // =========================================================================

    #[Test]
    public function custom_function_runtime_exception_becomes_error_node(): void
    {
        $this->eval->registerFunction('boom', fn() => throw new \RuntimeException('boom rt'));

        $result = $this->explainer->explain('boom() = 1', []);
        $this->assertSame(ExplainStatus::ERROR, $result->root->status);
        $this->assertStringContainsString('boom', $result->root->detail ?? '');
    }

    #[Test]
    public function custom_function_lib_exception_also_becomes_error_node(): void
    {
        $this->eval->registerFunction('boom', function () {
            throw new \Ols\PhpRuler\Exception\TypeErrorException('explicit lib error');
        });

        $result = $this->explainer->explain('boom() = 1', []);
        $this->assertSame(ExplainStatus::ERROR, $result->root->status);
    }

    // =========================================================================
    // Unknown function → ERROR (not MISSING)
    // =========================================================================

    #[Test]
    public function unknown_function_is_classified_as_error_not_missing(): void
    {
        $result = $this->explainer->explain('unknownFn(a) > 0', ['a' => 5]);
        $this->assertSame(ExplainStatus::ERROR, $result->root->status);
    }

    // =========================================================================
    // explainAst — symétrie avec explain
    // =========================================================================

    #[Test]
    public function explain_ast_yields_same_shape_as_explain(): void
    {
        $expr = 'a > 0 AND b > 0';
        $ctx  = ['a' => 5, 'b' => 3];
        $ast  = $this->eval->getAst($expr);

        $a = $this->explainer->explain   ($expr, $ctx);
        $b = $this->explainer->explainAst($ast,  $ctx);

        $this->assertSame($a->passed,             $b->passed);
        $this->assertSame($a->root->status,       $b->root->status);
        $this->assertSame(count($a->root->children), count($b->root->children));
    }
}
