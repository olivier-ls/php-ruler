<?php declare(strict_types=1);

namespace Ols\PhpRuler\Tests;

use Ols\PhpRuler\Evaluator\SafeResult;
use Ols\PhpRuler\Exception\TypeErrorException;
use Ols\PhpRuler\Exception\UnknownVariableException;
use Ols\PhpRuler\ExpressionEvaluator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Couvre evaluate-safe.md.
 *
 * Core invariant: missingVars answers "what was NEEDED but absent?",
 * not "what was absent in the expression?". A short-circuited node does
 * NOT contribute to missingVars because its absence did not block the result.
 *
 * Type errors STILL propagate — "safe" only catches UnknownVariableException.
 */
final class EvaluateSafeTest extends TestCase
{
    private ExpressionEvaluator $eval;

    protected function setUp(): void
    {
        $this->eval = new ExpressionEvaluator();
    }

    // =========================================================================
    // SafeResult shape
    // =========================================================================

    #[Test]
    public function safe_result_exposes_readonly_properties(): void
    {
        $result = $this->eval->evaluateSafe('1 + 1', []);
        $this->assertInstanceOf(SafeResult::class, $result);
        $this->assertTrue($result->success);
        $this->assertSame(2, $result->value);
        $this->assertSame([], $result->missingVars);
    }

    #[Test]
    public function get_value_returns_value_on_success(): void
    {
        $result = $this->eval->evaluateSafe('1 + 1', []);
        $this->assertSame(2, $result->getValue());
    }

    #[Test]
    public function get_value_throws_logic_exception_on_failure(): void
    {
        $result = $this->eval->evaluateSafe('missing', []);
        $this->expectException(\LogicException::class);
        $result->getValue();
    }

    #[Test]
    public function get_value_or_returns_default_on_failure(): void
    {
        $result = $this->eval->evaluateSafe('missing', []);
        $this->assertSame('default', $result->getValueOr('default'));
    }

    #[Test]
    public function get_value_or_returns_actual_value_on_success_not_default(): void
    {
        $result = $this->eval->evaluateSafe('5', []);
        $this->assertSame(5, $result->getValueOr('default'));
    }

    // =========================================================================
    // Variable seule — null is a value, absence is failure
    // =========================================================================

    #[Test]
    public function present_variable_yields_success(): void
    {
        $r = $this->eval->evaluateSafe('a', ['a' => 5]);
        $this->assertTrue($r->success);
        $this->assertSame(5, $r->value);
        $this->assertSame([], $r->missingVars);
    }

    #[Test]
    public function missing_variable_collects_it_in_missing_vars(): void
    {
        $r = $this->eval->evaluateSafe('a', []);
        $this->assertFalse($r->success);
        $this->assertSame(['a'], $r->missingVars);
    }

    #[Test]
    public function present_null_value_is_success_not_failure(): void
    {
        // Subtle but documented: null IS a value, not an absence.
        $r = $this->eval->evaluateSafe('a', ['a' => null]);
        $this->assertTrue($r->success);
        $this->assertNull($r->value);
        $this->assertSame([], $r->missingVars);
    }

    // =========================================================================
    // ?? — sémantique "nécessaire et absent"
    // =========================================================================

    #[Test]
    public function coalesce_absorbs_missing_left_without_reporting_it(): void
    {
        // Doc rationale: missing-left is the nominal case for ??, not a failure.
        $r = $this->eval->evaluateSafe('a ?? b', ['b' => 10]);
        $this->assertTrue($r->success);
        $this->assertSame(10, $r->value);
        $this->assertNotContains('a', $r->missingVars);
    }

    #[Test]
    public function coalesce_reports_only_right_when_both_missing(): void
    {
        // Subtle but explicit: 'a' is NOT in missingVars even when 'b' also misses.
        $r = $this->eval->evaluateSafe('a ?? b', []);
        $this->assertFalse($r->success);
        $this->assertContains('b', $r->missingVars);
        $this->assertNotContains('a', $r->missingVars);
    }

    #[Test]
    public function coalesce_with_present_left_succeeds(): void
    {
        $r = $this->eval->evaluateSafe('a ?? b', ['a' => 5]);
        $this->assertTrue($r->success);
        $this->assertSame(5, $r->value);
    }

    #[Test]
    public function coalesce_with_null_left_evaluates_right(): void
    {
        $r = $this->eval->evaluateSafe('a ?? b', ['a' => null, 'b' => 10]);
        $this->assertTrue($r->success);
        $this->assertSame(10, $r->value);
    }

    #[Test]
    public function coalesce_with_null_left_reports_right_if_also_missing(): void
    {
        $r = $this->eval->evaluateSafe('a ?? b', ['a' => null]);
        $this->assertFalse($r->success);
        $this->assertContains('b', $r->missingVars);
    }

    // =========================================================================
    // Short-circuit AND / OR — un nœud court-circuité ne contribue pas
    // =========================================================================

    #[Test]
    public function false_and_missing_right_succeeds_with_false(): void
    {
        $r = $this->eval->evaluateSafe('false AND b', []);
        $this->assertTrue($r->success);
        $this->assertFalse($r->value);
        $this->assertSame([], $r->missingVars);
    }

    #[Test]
    public function true_or_missing_right_succeeds_with_true(): void
    {
        $r = $this->eval->evaluateSafe('true OR b', []);
        $this->assertTrue($r->success);
        $this->assertTrue($r->value);
        $this->assertSame([], $r->missingVars);
    }

    #[Test]
    public function missing_left_with_certain_false_right_still_reports_left(): void
    {
        // Critical guarantee: success answers "was the context complete?", not
        // just "was the result computable?". Even when the right side would
        // determine the AND result, a missing left is still a real gap.
        $r = $this->eval->evaluateSafe('a AND false', []);
        $this->assertFalse($r->success);
        $this->assertContains('a', $r->missingVars);
    }

    #[Test]
    public function missing_left_with_certain_true_right_still_reports_left(): void
    {
        $r = $this->eval->evaluateSafe('a OR true', []);
        $this->assertFalse($r->success);
        $this->assertContains('a', $r->missingVars);
    }

    #[Test]
    public function both_sides_missing_in_and_reports_both(): void
    {
        $r = $this->eval->evaluateSafe('a AND b', []);
        $this->assertFalse($r->success);
        $this->assertContains('a', $r->missingVars);
        $this->assertContains('b', $r->missingVars);
    }

    #[Test]
    public function nested_missing_via_or_in_and_collects_all(): void
    {
        // (a OR b) AND false
        // Right is certain-false but does not skip left: we evaluate left to collect
        // its missing vars. Both 'a' and 'b' surface.
        $r = $this->eval->evaluateSafe('(a OR b) AND false', []);
        $this->assertFalse($r->success);
        $this->assertContains('a', $r->missingVars);
        $this->assertContains('b', $r->missingVars);
    }

    // =========================================================================
    // Ternaire — seule la branche prise est visitée
    // =========================================================================

    #[Test]
    public function ternary_visits_only_chosen_branch(): void
    {
        $r = $this->eval->evaluateSafe('a > 0 ? b : c', ['a' => 5, 'b' => 'yes']);
        $this->assertTrue($r->success);
        $this->assertSame('yes', $r->value);
    }

    #[Test]
    public function ternary_does_not_report_missing_in_skipped_branch(): void
    {
        // 'c' is missing but never visited.
        $r = $this->eval->evaluateSafe('a > 0 ? b : c', ['a' => 5]);
        $this->assertFalse($r->success);
        $this->assertContains('b', $r->missingVars);
        $this->assertNotContains('c', $r->missingVars);
    }

    #[Test]
    public function ternary_with_missing_condition_does_not_visit_branches(): void
    {
        $r = $this->eval->evaluateSafe('a > 0 ? b : c', []);
        $this->assertFalse($r->success);
        $this->assertContains('a', $r->missingVars);
        // 'a > 0' could not be resolved, so neither branch is touched.
        $this->assertNotContains('b', $r->missingVars);
        $this->assertNotContains('c', $r->missingVars);
    }

    // =========================================================================
    // Type errors — NOT absorbed
    // =========================================================================

    #[Test]
    public function safe_does_not_absorb_type_error_in_arithmetic(): void
    {
        $this->expectException(TypeErrorException::class);
        $this->eval->evaluateSafe("'hello' + 1", []);
    }

    #[Test]
    public function safe_does_not_absorb_division_by_zero(): void
    {
        $this->expectException(TypeErrorException::class);
        $this->eval->evaluateSafe('1 / 0', []);
    }

    #[Test]
    public function safe_does_not_absorb_type_error_on_logical_with_resolved_non_bool(): void
    {
        // The left was RESOLVED to a non-bool value — that's a type error,
        // not a missing data problem. Must raise.
        $this->expectException(TypeErrorException::class);
        $this->eval->evaluateSafe('a AND true', ['a' => 5]);
    }

    #[Test]
    public function safe_collects_missing_on_logical_when_left_is_unresolved(): void
    {
        // The left is UNRESOLVED — no type assertion can be made. Collect missing.
        $r = $this->eval->evaluateSafe('a AND b', []);
        $this->assertFalse($r->success);
        $this->assertCount(2, $r->missingVars);
    }

    // =========================================================================
    // Déduplication & ordre
    // =========================================================================

    #[Test]
    public function missing_vars_is_deduplicated(): void
    {
        $r = $this->eval->evaluateSafe('x + x + x', []);
        $this->assertFalse($r->success);
        $this->assertSame(['x'], $r->missingVars);
    }

    // =========================================================================
    // Fonctions custom — propagation, pas de collecte transparente
    // =========================================================================

    #[Test]
    public function unknown_variable_from_custom_function_is_not_converted_to_missing(): void
    {
        // Documented limitation: lib cannot know if a lookup inside a custom function
        // is meant to participate in the missing-vars protocol.
        $this->eval->registerFunction('lookup', function () {
            throw new UnknownVariableException('Unknown variable: "x"');
        });

        $this->expectException(UnknownVariableException::class);
        $this->eval->evaluateSafe('lookup()', []);
    }

    // =========================================================================
    // evaluateSafeAst — identical semantics
    // =========================================================================

    #[Test]
    public function evaluate_safe_ast_yields_same_result_as_evaluate_safe_string(): void
    {
        $expr = 'a ?? b';
        $ast  = $this->eval->getAst($expr);

        $a = $this->eval->evaluateSafe   ($expr, ['b' => 7]);
        $b = $this->eval->evaluateSafeAst($ast,  ['b' => 7]);

        $this->assertSame($a->success,     $b->success);
        $this->assertSame($a->value,       $b->value);
        $this->assertSame($a->missingVars, $b->missingVars);
    }

    // =========================================================================
    // Trivial cases
    // =========================================================================

    #[Test]
    public function expression_without_variables_succeeds(): void
    {
        $r = $this->eval->evaluateSafe('1 + 1', []);
        $this->assertTrue($r->success);
        $this->assertSame(2, $r->value);
    }

    #[Test]
    public function double_false_yields_false_without_missing(): void
    {
        $r = $this->eval->evaluateSafe('false AND false', []);
        $this->assertTrue($r->success);
        $this->assertFalse($r->value);
    }
}
