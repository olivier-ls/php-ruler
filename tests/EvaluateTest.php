<?php declare(strict_types=1);

namespace Ols\PhpRuler\Tests;

use Ols\PhpRuler\Exception\EvaluatorException;
use Ols\PhpRuler\Exception\TypeErrorException;
use Ols\PhpRuler\Exception\UnknownVariableException;
use Ols\PhpRuler\ExpressionEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Couvre evaluate.md (strict mode).
 *
 * Strict mode is the default — every anomaly (missing var, type error, NaN/INF,
 * etc.) raises an exception. For the lenient counterpart, see EvaluateSafeTest.
 * For language-level details (precedence, operator semantics), see LanguageReferenceTest.
 */
final class EvaluateTest extends TestCase
{
    private ExpressionEvaluator $eval;

    protected function setUp(): void
    {
        $this->eval = new ExpressionEvaluator();
    }

    // =========================================================================
    // evaluate() — basic return types
    // =========================================================================

    #[Test]
    public function evaluate_returns_int_for_integer_arithmetic(): void
    {
        $this->assertSame(3, $this->eval->evaluate('1 + 2', []));
    }

    #[Test]
    public function evaluate_returns_float_when_any_operand_is_float(): void
    {
        $this->assertSame(3.5, $this->eval->evaluate('1.0 + 2.5', []));
    }

    #[Test]
    public function evaluate_returns_bool_for_comparison(): void
    {
        $this->assertTrue($this->eval->evaluate('5 > 3', []));
        $this->assertFalse($this->eval->evaluate('5 < 3', []));
    }

    #[Test]
    public function evaluate_returns_string_for_string_function(): void
    {
        $this->assertSame('ALICE', $this->eval->evaluate('upper(name)', ['name' => 'alice']));
    }

    #[Test]
    public function evaluate_returns_array_for_list_literal(): void
    {
        $this->assertSame([1, 2, 3], $this->eval->evaluate('[1, 2, 3]', []));
    }

    #[Test]
    public function evaluate_returns_null_when_expression_resolves_to_null(): void
    {
        $this->assertNull($this->eval->evaluate('a', ['a' => null]));
    }

    // =========================================================================
    // evaluate() — context resolution
    // =========================================================================

    #[Test]
    public function evaluate_resolves_dotted_paths(): void
    {
        $ctx = ['cart' => ['total' => 150.0]];
        $this->assertTrue($this->eval->evaluate('cart.total > 100', $ctx));
    }

    #[Test]
    public function evaluate_throws_unknown_variable_when_path_is_missing(): void
    {
        $this->expectException(UnknownVariableException::class);
        $this->eval->evaluate('cart.shipping > 0', ['cart' => ['total' => 100]]);
    }

    #[Test]
    public function evaluate_treats_null_as_valid_value_not_missing(): void
    {
        // null IS a legitimate value; it just doesn't equal a number.
        $this->assertTrue($this->eval->evaluate('a = null', ['a' => null]));
    }

    // =========================================================================
    // evaluate() — strict bool policy (audit I1)
    // =========================================================================

    #[Test]
    #[DataProvider('nonBoolLogicalOperandsProvider')]
    public function evaluate_rejects_non_bool_operands_to_logical_operators(string $expression, array $context): void
    {
        // Strict bool: no truthy/falsy coercion. This matrix locks the policy
        // documented in evaluate.md ("no surprise" principle).
        $this->expectException(TypeErrorException::class);
        $this->eval->evaluate($expression, $context);
    }

    public static function nonBoolLogicalOperandsProvider(): array
    {
        return [
            // AND with various non-bool LEFTs
            'int left in AND'         => ['5 AND true',          []],
            'two ints in AND'         => ['5 AND 10',            []],
            'zero in AND'             => ['0 AND true',          []],
            'string left in AND'      => ["'hello' AND true",    []],
            'string false in AND'     => ["'false' AND true",    []],
            'empty string in AND'     => ["'' AND true",         []],
            'null left in AND'        => ['a AND true',          ['a' => null]],

            // OR
            'int left in OR'          => ['1 OR false',          []],
            'string left in OR'       => ["'hello' OR false",    []],
            'empty string in OR'      => ["'' OR false",         []],

            // NOT
            'NOT on int'              => ['NOT 5',               []],
            'NOT on string'           => ["NOT 'x'",             []],
            'NOT on null'             => ['NOT a',               ['a' => null]],

            // Ternary condition
            'ternary cond int'        => ['5 ? "y" : "n"',       []],
            'ternary cond zero'       => ['0 ? "y" : "n"',       []],
            'ternary cond string'     => ['"" ? "y" : "n"',      []],
        ];
    }

    #[Test]
    public function type_error_on_logical_operator_message_suggests_conversions(): void
    {
        try {
            $this->eval->evaluate("'hello' AND true", []);
            $this->fail();
        } catch (TypeErrorException $e) {
            // The doc commits to a helpful message with suggested next steps.
            $this->assertStringContainsString('expected boolean', $e->getMessage());
            $this->assertStringContainsString('explicit comparison', $e->getMessage());
        }
    }

    #[Test]
    public function evaluate_accepts_bool_operands_to_logical_operators(): void
    {
        // Sanity check: the strict policy does not break the nominal case.
        $this->assertTrue($this->eval->evaluate('true AND true', []));
        $this->assertFalse($this->eval->evaluate('true AND false', []));
        $this->assertTrue($this->eval->evaluate('false OR true', []));
        $this->assertFalse($this->eval->evaluate('NOT true', []));
    }

    // =========================================================================
    // evaluate() — arithmetic strict policy
    // =========================================================================

    #[Test]
    public function arithmetic_rejects_null_operand(): void
    {
        // -null is NOT 0; null + 1 is NOT 1.
        try {
            $this->eval->evaluate('-a', ['a' => null]);
            $this->fail();
        } catch (TypeErrorException $e) {
            $this->assertStringContainsString('must be a number', $e->getMessage());
        }
    }

    #[Test]
    public function arithmetic_rejects_string_operand(): void
    {
        $this->expectException(TypeErrorException::class);
        $this->eval->evaluate("'5' + 1", []);
    }

    #[Test]
    public function division_by_zero_throws_with_clear_message(): void
    {
        try {
            $this->eval->evaluate('1 / 0', []);
            $this->fail();
        } catch (TypeErrorException $e) {
            $this->assertSame('Division by zero', $e->getMessage());
        }
    }

    #[Test]
    public function modulo_by_zero_throws(): void
    {
        $this->expectException(TypeErrorException::class);
        $this->eval->evaluate('5 % 0', []);
    }

    // =========================================================================
    // NaN / INF — interdits dans le pipeline (audit I5)
    // =========================================================================

    #[Test]
    public function nan_from_context_is_rejected_at_arithmetic_operator(): void
    {
        $this->expectException(TypeErrorException::class);
        $this->expectExceptionMessage('NaN');
        $this->eval->evaluate('x + 1', ['x' => NAN]);
    }

    #[Test]
    public function inf_from_context_is_rejected_at_arithmetic_operator(): void
    {
        $this->expectException(TypeErrorException::class);
        $this->expectExceptionMessage('INF');
        $this->eval->evaluate('x + 1', ['x' => INF]);
    }

    #[Test]
    public function is_finite_is_the_escape_hatch_to_inspect_nan_inf(): void
    {
        // The ONLY way to probe a non-finite value without raising.
        $this->assertFalse($this->eval->evaluate('is_finite(x)', ['x' => NAN]));
        $this->assertFalse($this->eval->evaluate('is_finite(x)', ['x' => INF]));
        $this->assertTrue($this->eval->evaluate('is_finite(x)',  ['x' => 42]));
    }

    #[Test]
    public function unary_minus_on_nan_is_rejected(): void
    {
        // Regression for audit I5: -getNan() must raise (not produce -NaN silently).
        $this->eval->registerFunction('getNan', fn(): float => NAN);
        $this->expectException(TypeErrorException::class);
        $this->eval->evaluate('-getNan()', []);
    }

    #[Test]
    public function unary_minus_on_inf_is_rejected(): void
    {
        $this->eval->registerFunction('getInf', fn(): float => INF);
        $this->expectException(TypeErrorException::class);
        $this->eval->evaluate('-getInf()', []);
    }

    // =========================================================================
    // Égalité — looseEqual (audit I4)
    // =========================================================================

    #[Test]
    public function null_equals_null_is_true(): void
    {
        $this->assertTrue($this->eval->evaluate('null = null', []));
    }

    #[Test]
    public function null_does_not_equal_a_value(): void
    {
        // null = <anything> → false (jamais d'exception, contrairement à >/<).
        $this->assertFalse($this->eval->evaluate('null = 42',      []));
        $this->assertFalse($this->eval->evaluate('42 = null',      []));
        $this->assertTrue ($this->eval->evaluate('null != 42',     []));
    }

    #[Test]
    public function int_equals_float_when_numerically_equal(): void
    {
        // Documented adaptive equality: 5 = 5.0 → true (no false negative).
        $this->assertTrue($this->eval->evaluate('5 = 5.0', []));
    }

    #[Test]
    public function equality_between_nan_is_rejected_not_silently_false(): void
    {
        // The doc forbids NaN comparisons in any form, even via =.
        $this->eval->registerFunction('getNan', fn(): float => NAN);
        $this->expectException(TypeErrorException::class);
        $this->eval->evaluate('getNan() = getNan()', []);
    }

    #[Test]
    public function array_equality_is_explicitly_forbidden(): void
    {
        // Documented: array = array → TypeErrorException. Use IN.
        try {
            $this->eval->evaluate('a = b', ['a' => [1, 2], 'b' => [1, 2]]);
            $this->fail();
        } catch (TypeErrorException $e) {
            $this->assertStringContainsString('IN', $e->getMessage());
        }
    }

    #[Test]
    public function equality_of_incompatible_types_throws(): void
    {
        $this->expectException(TypeErrorException::class);
        $this->eval->evaluate("'5' = 5", []);
    }

    // =========================================================================
    // Comparaison d'ordre — string-string OK, number-string KO
    // =========================================================================

    #[Test]
    public function order_comparison_works_on_numbers(): void
    {
        $this->assertTrue($this->eval->evaluate('5 > 3', []));
        $this->assertTrue($this->eval->evaluate('5.5 >= 5', []));
    }

    #[Test]
    public function order_comparison_works_on_strings_lexicographically(): void
    {
        // Especially useful for date strings 'Y-m-d'.
        $this->assertTrue($this->eval->evaluate("'2026-01-15' > '2026-01-01'", []));
        $this->assertTrue($this->eval->evaluate("'apple' < 'banana'", []));
    }

    #[Test]
    public function order_comparison_between_string_and_number_throws(): void
    {
        $this->expectException(TypeErrorException::class);
        $this->eval->evaluate("'a' > 5", []);
    }

    #[Test]
    public function order_comparison_with_null_throws(): void
    {
        $this->expectException(TypeErrorException::class);
        $this->eval->evaluate('a > 5', ['a' => null]);
    }

    // =========================================================================
    // IN / NOT IN
    // =========================================================================

    #[Test]
    public function scalar_in_list_returns_true_when_member(): void
    {
        $this->assertTrue($this->eval->evaluate("'php' IN tags", ['tags' => ['php', 'js']]));
    }

    #[Test]
    public function scalar_in_list_returns_false_when_not_member(): void
    {
        $this->assertFalse($this->eval->evaluate("'sql' IN tags", ['tags' => ['php', 'js']]));
    }

    #[Test]
    public function not_in_is_the_inverse(): void
    {
        $this->assertTrue($this->eval->evaluate('5 NOT IN [1, 2, 3]', []));
        $this->assertFalse($this->eval->evaluate('2 NOT IN [1, 2, 3]', []));
    }

    #[Test]
    public function in_raises_when_all_pairs_are_type_incompatible(): void
    {
        // Regression for audit I9: 'foo' IN [1, 2] must raise (no false negative).
        $this->expectException(TypeErrorException::class);
        $this->eval->evaluate("'foo' IN [1, 2]", []);
    }

    #[Test]
    public function not_in_also_raises_when_all_pairs_incompatible(): void
    {
        // Critical asymmetry: silent false would make NOT IN return TRUE (a positive
        // claim derived from a non-comparison).
        $this->expectException(TypeErrorException::class);
        $this->eval->evaluate("'foo' NOT IN [1, 2]", []);
    }

    #[Test]
    public function in_succeeds_when_at_least_one_pair_compared(): void
    {
        // Audit I9: 1 IN [1, 'a'] — match found before the bad pair is even tried.
        $this->assertTrue($this->eval->evaluate("1 IN [1, 'a']", []));
        // 2 vs 1 compared, vs 'a' raised but is ignored because we had a valid comparison.
        $this->assertFalse($this->eval->evaluate("2 IN [1, 'a']", []));
    }

    #[Test]
    public function in_with_empty_list_via_variable_returns_false(): void
    {
        // Empty list literal in IN is a parse error, but via a variable it's runtime-fine.
        $this->assertFalse($this->eval->evaluate("'foo' IN tags", ['tags' => []]));
    }

    #[Test]
    public function array_in_list_first_checks_whole_subject_then_intersection(): void
    {
        // Documented dual semantics for array-on-the-left IN.
        $this->assertTrue ($this->eval->evaluate('[1, 2] IN [[1, 2], 3]', [])); // pre-pass: whole match
        $this->assertTrue ($this->eval->evaluate('a IN [1, 2, 3]', ['a' => [1, 2]])); // intersection: 1 in both
        $this->assertFalse($this->eval->evaluate('a IN [1, 2, 3]', ['a' => [4, 5]])); // neither
    }

    // =========================================================================
    // PHP_INT_MIN — audit I3
    // =========================================================================

    #[Test]
    public function php_int_min_literal_is_accepted_via_unary_minus(): void
    {
        // Specifically: -9223372036854775808 must be a valid literal.
        // 9223372036854775808 alone (PHP_INT_MAX + 1) is rejected by the lexer.
        $this->assertSame(PHP_INT_MIN, $this->eval->evaluate('-9223372036854775808', []));
    }

    #[Test]
    public function php_int_min_can_participate_in_arithmetic(): void
    {
        $this->assertSame(PHP_INT_MIN + 1, $this->eval->evaluate('-9223372036854775808 + 1', []));
    }

    #[Test]
    public function php_int_min_can_be_compared(): void
    {
        $this->assertTrue($this->eval->evaluate('-9223372036854775808 < -9223372036854775807', []));
        $this->assertTrue($this->eval->evaluate('-9223372036854775808 = -9223372036854775808', []));
    }

    #[Test]
    public function integer_overflow_at_runtime_is_detected_not_downcast_to_float(): void
    {
        // The doc explains: PHP_INT_MAX + 1 silently downcasts to float in PHP.
        // The lib catches this and raises rather than lose precision.
        $this->expectException(TypeErrorException::class);
        $this->eval->evaluate('a + 1', ['a' => PHP_INT_MAX]);
    }

    #[Test]
    public function float_cast_lets_user_opt_out_of_overflow_detection(): void
    {
        // Documented workaround: cast a side to float to accept the precision loss.
        $this->assertIsFloat($this->eval->evaluate('a + 0.0 + 1', ['a' => PHP_INT_MAX]));
    }

    // =========================================================================
    // evaluateBoolean / evaluateNumeric — type guards on result
    // =========================================================================

    #[Test]
    public function evaluate_boolean_returns_a_bool_when_expression_is_a_comparison(): void
    {
        $this->assertTrue($this->eval->evaluateBoolean('5 > 3', []));
    }

    #[Test]
    public function evaluate_boolean_throws_when_result_is_not_a_bool(): void
    {
        $this->expectException(TypeErrorException::class);
        $this->eval->evaluateBoolean('1 + 1', []);
    }

    #[Test]
    public function evaluate_numeric_returns_a_float_even_for_int_arithmetic(): void
    {
        // Documented: int result is cast to float to give a uniform numeric type out.
        $this->assertSame(8.0, $this->eval->evaluateNumeric('5 + 3', []));
    }

    #[Test]
    public function evaluate_numeric_throws_when_result_is_not_numeric(): void
    {
        $this->expectException(TypeErrorException::class);
        $this->eval->evaluateNumeric('upper(name)', ['name' => 'x']);
    }

    // =========================================================================
    // Ast-based evaluation — same semantics
    // =========================================================================

    #[Test]
    public function evaluate_ast_yields_the_same_result_as_evaluate_string(): void
    {
        $ast = $this->eval->getAst('a + b');
        $this->assertSame(
            $this->eval->evaluate('a + b', ['a' => 5, 'b' => 3]),
            $this->eval->evaluateAst($ast, ['a' => 5, 'b' => 3])
        );
    }

    #[Test]
    public function evaluate_ast_with_different_contexts_re_uses_the_parse(): void
    {
        $ast = $this->eval->getAst('a > threshold');
        $this->assertTrue ($this->eval->evaluateAstBoolean($ast, ['a' => 20, 'threshold' => 10]));
        $this->assertFalse($this->eval->evaluateAstBoolean($ast, ['a' => 5,  'threshold' => 10]));
    }

    // =========================================================================
    // Garde-fou de profondeur
    // =========================================================================

    #[Test]
    public function evaluation_depth_guard_catches_runaway_recursion(): void
    {
        // Build a deeply nested expression beyond MAX_EVAL_DEPTH=200.
        $expr = '1';
        for ($i = 0; $i < 250; $i++) {
            $expr = '(' . $expr . ' + 1)';
        }

        $this->expectException(EvaluatorException::class);
        $this->eval->evaluate($expr, []);
    }

    // =========================================================================
    // Short-circuit AND / OR / ternaire
    // =========================================================================

    #[Test]
    public function and_short_circuits_when_left_is_false(): void
    {
        $counter = 0;
        $this->eval->registerFunction('bump', function () use (&$counter): bool {
            $counter++;
            return true;
        });

        $this->assertFalse($this->eval->evaluate('false AND bump()', []));
        $this->assertSame(0, $counter, 'right side must not be evaluated');
    }

    #[Test]
    public function or_short_circuits_when_left_is_true(): void
    {
        $counter = 0;
        $this->eval->registerFunction('bump', function () use (&$counter): bool {
            $counter++;
            return false;
        });

        $this->assertTrue($this->eval->evaluate('true OR bump()', []));
        $this->assertSame(0, $counter);
    }

    #[Test]
    public function ternary_evaluates_only_the_chosen_branch(): void
    {
        $counter = 0;
        $this->eval->registerFunction('bump', function () use (&$counter): int {
            $counter++;
            return 42;
        });

        $this->assertSame(1, $this->eval->evaluate('true ? 1 : bump()', []));
        $this->assertSame(0, $counter);

        $this->assertSame(42, $this->eval->evaluate('false ? bump() : 42', []));
        $this->assertSame(0, $counter);
    }
}
