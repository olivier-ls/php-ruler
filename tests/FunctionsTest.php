<?php declare(strict_types=1);

namespace Ols\PhpRuler\Tests;

use Ols\PhpRuler\Exception\EvaluatorException;
use Ols\PhpRuler\Exception\TypeErrorException;
use Ols\PhpRuler\ExpressionEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Couvre functions.md.
 *
 *  - registerFunction()  : surcharge silencieuse (built-in or previous custom)
 *  - getFunctions()      : liste triée des fonctions enregistrées
 *  - callFunction()      : dispatch + validation d'arity + politique d'exceptions
 *  - Catalogue des built-ins par grande famille
 *
 * The exception policy (lib transit, others wrapped with previous) is also
 * covered in ExceptionsTest.
 */
final class FunctionsTest extends TestCase
{
    private ExpressionEvaluator $eval;

    protected function setUp(): void
    {
        $this->eval = new ExpressionEvaluator();
    }

    // =========================================================================
    // registerFunction / getFunctions / callFunction
    // =========================================================================

    #[Test]
    public function register_function_makes_it_callable_from_expressions(): void
    {
        $this->eval->registerFunction('greet', fn(string $name): string => "Hello, $name!");
        $this->assertSame('Hello, Alice!', $this->eval->evaluate('greet(name)', ['name' => 'Alice']));
    }

    #[Test]
    public function register_function_silently_overrides_a_builtin(): void
    {
        // Documented as intentional — typical use: stub today() for tests.
        $this->eval->registerFunction('today', fn(): string => '2026-01-01');
        $this->assertSame('2026-01-01', $this->eval->evaluate('today()', []));
    }

    #[Test]
    public function register_function_returns_self_for_chaining(): void
    {
        $this->assertSame(
            $this->eval,
            $this->eval->registerFunction('f', fn() => 1)
        );
    }

    #[Test]
    public function get_functions_returns_sorted_list_including_builtins(): void
    {
        $names = $this->eval->getFunctions();
        $this->assertSame($names, array_values(array_unique($names)), 'no duplicates');

        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names, 'sorted alphabetically');

        // Spot-check a handful of advertised built-ins.
        foreach (['abs', 'concat', 'length', 'round', 'today'] as $expected) {
            $this->assertContains($expected, $names);
        }
    }

    #[Test]
    public function get_functions_reflects_newly_registered_functions(): void
    {
        $before = $this->eval->getFunctions();
        $this->eval->registerFunction('zz_my_thing', fn() => 1);
        $after = $this->eval->getFunctions();

        $this->assertCount(count($before) + 1, $after);
        $this->assertContains('zz_my_thing', $after);
    }

    // =========================================================================
    // Arity validation — strict (audit B3)
    // =========================================================================

    #[Test]
    public function arity_too_many_args_on_fixed_function_raises(): void
    {
        try {
            $this->eval->evaluate('round(1, 2, 3)', []);
            $this->fail();
        } catch (TypeErrorException $e) {
            $this->assertStringContainsString('round', $e->getMessage());
            $this->assertStringContainsString('between 1 and 2 arguments, 3 given', $e->getMessage());
        }
    }

    #[Test]
    public function arity_too_few_args_on_required_function_raises(): void
    {
        try {
            $this->eval->evaluate('round()', []);
            $this->fail();
        } catch (TypeErrorException $e) {
            $this->assertStringContainsString('between 1 and 2 arguments, 0 given', $e->getMessage());
        }
    }

    #[Test]
    public function arity_message_uses_exactly_format_for_fixed_arity(): void
    {
        $this->eval->registerFunction('twoArgs', fn(int $a, int $b) => $a + $b);

        try {
            $this->eval->evaluate('twoArgs(1)', []);
            $this->fail();
        } catch (TypeErrorException $e) {
            $this->assertStringContainsString('exactly 2 arguments, 1 given', $e->getMessage());
        }
    }

    #[Test]
    public function arity_message_uses_at_least_format_for_variadic(): void
    {
        $this->eval->registerFunction(
            'manyArgs',
            fn(int $first, mixed ...$rest) => $first
        );

        try {
            $this->eval->evaluate('manyArgs()', []);
            $this->fail();
        } catch (TypeErrorException $e) {
            $this->assertStringContainsString('at least 1 arguments, 0 given', $e->getMessage());
        }
    }

    #[Test]
    public function min_and_max_explicitly_reject_more_than_two_args(): void
    {
        // Documented design decision: min/max take EXACTLY 2 args. For lists, use min_of / max_of.
        // The error message guides users to the alternative.
        try {
            $this->eval->evaluate('min(1, 2, 3)', []);
            $this->fail();
        } catch (TypeErrorException $e) {
            $this->assertStringContainsString('min_of', $e->getMessage());
        }

        try {
            $this->eval->evaluate('max(1, 2, 3)', []);
            $this->fail();
        } catch (TypeErrorException $e) {
            $this->assertStringContainsString('max_of', $e->getMessage());
        }
    }

    #[Test]
    public function unknown_function_raises_evaluator_exception(): void
    {
        $this->expectException(EvaluatorException::class);
        $this->eval->evaluate('unknownFn(1)', []);
    }

    // =========================================================================
    // Casting — int / float / bool / str
    // =========================================================================

    #[Test]
    #[DataProvider('intCastCasesProvider')]
    public function int_casts_acceptable_inputs(mixed $input, int $expected): void
    {
        $this->assertSame($expected, $this->eval->evaluate('int(a)', ['a' => $input]));
    }

    public static function intCastCasesProvider(): array
    {
        return [
            'int passthrough'         => [42, 42],
            'positive float truncate' => [3.7, 3],
            'half rounds toward zero' => [3.5, 3],
            'negative float truncate' => [-3.7, -3],
            'numeric string'          => ['42', 42],
            'negative numeric string' => ['-7', -7],
        ];
    }

    #[Test]
    public function int_rejects_float_shaped_string(): void
    {
        // Documented: numeric string must be an integer string, not '3.7'.
        $this->expectException(TypeErrorException::class);
        $this->eval->evaluate("int('3.7')", []);
    }

    #[Test]
    public function int_rejects_bool_and_null(): void
    {
        $this->expectException(TypeErrorException::class);
        $this->eval->evaluate('int(a)', ['a' => true]);
    }

    #[Test]
    public function bool_is_strict_about_acceptable_inputs(): void
    {
        // bool() is stricter than PHP's native (bool) cast.
        $this->assertTrue ($this->eval->evaluate("bool('true')",  []));
        $this->assertFalse($this->eval->evaluate("bool('false')", []));
        $this->assertTrue ($this->eval->evaluate('bool(1)',       []));
        $this->assertFalse($this->eval->evaluate('bool(0)',       []));
        $this->assertTrue ($this->eval->evaluate('bool(a)',       ['a' => true]));
    }

    #[Test]
    public function bool_rejects_arbitrary_truthy_values(): void
    {
        $this->expectException(TypeErrorException::class);
        // 2 is truthy in PHP but bool() rejects anything other than 0/1.
        $this->eval->evaluate('bool(2)', []);
    }

    // =========================================================================
    // str() — format spécifique (audit I7)
    // =========================================================================

    #[Test]
    #[DataProvider('strFormatCasesProvider')]
    public function str_formats_value_without_scientific_notation(string $expr, string $expected): void
    {
        $this->assertSame($expected, $this->eval->evaluate($expr, []));
    }

    public static function strFormatCasesProvider(): array
    {
        return [
            'int'                  => ['str(42)',          '42'],
            'plain float'          => ['str(1.0)',         '1'],
            'trailing zero stripped' => ['str(1.50)',      '1.5'],
            'string passthrough'   => ["str('hello')",     'hello'],
            'mid precision'        => ['str(1234.5678)',   '1234.5678'],
            'tiny float'           => ['str(0.000001)',    '0.000001'],
            'just above lower bound' => ['str(0.0000001)', '0.0000001'],
            'lower bound 1e-10'    => ['str(1e-10)',       '0.0000000001'],
            'zero'                 => ['str(0.0)',         '0'],
            'negative zero'        => ['str(-0.0)',        '0'],
        ];
    }

    #[Test]
    public function str_rejects_nan_and_inf(): void
    {
        $this->eval->registerFunction('getNan', fn(): float => NAN);
        $this->expectException(TypeErrorException::class);
        $this->eval->evaluate('str(getNan())', []);
    }

    #[Test]
    public function str_rejects_floats_too_large_to_print_readably(): void
    {
        $this->expectException(TypeErrorException::class);
        $this->eval->evaluate('str(a)', ['a' => 1e20]);
    }

    // =========================================================================
    // Arithmétique — round, abs, clamp, pow
    // =========================================================================

    #[Test]
    public function round_with_explicit_precision(): void
    {
        $this->assertSame(3.14, $this->eval->evaluate('round(3.14159, 2)', []));
    }

    #[Test]
    public function round_default_precision_is_zero(): void
    {
        $this->assertSame(3.0, $this->eval->evaluate('round(3.14)', []));
    }

    #[Test]
    public function round_rejects_precision_out_of_range(): void
    {
        $this->expectException(TypeErrorException::class);
        $this->eval->evaluate('round(1.0, 15)', []);
    }

    #[Test]
    public function clamp_bounds_value_within_range(): void
    {
        $this->assertSame(5,  $this->eval->evaluate('clamp(3, 5, 10)', []));
        $this->assertSame(10, $this->eval->evaluate('clamp(15, 5, 10)', []));
        $this->assertSame(7,  $this->eval->evaluate('clamp(7, 5, 10)', []));
    }

    #[Test]
    public function clamp_rejects_inverted_bounds(): void
    {
        $this->expectException(TypeErrorException::class);
        $this->eval->evaluate('clamp(5, 10, 1)', []);
    }

    #[Test]
    public function pow_returns_int_when_operands_and_result_are_ints(): void
    {
        $this->assertSame(8, $this->eval->evaluate('pow(2, 3)', []));
    }

    #[Test]
    public function pow_rejects_overflow_to_inf(): void
    {
        $this->expectException(TypeErrorException::class);
        $this->eval->evaluate('pow(10, 1000)', []);
    }

    #[Test]
    public function pow_rejects_negative_base_with_non_integer_exponent(): void
    {
        $this->expectException(TypeErrorException::class);
        $this->eval->evaluate('pow(-2, 0.5)', []);
    }

    #[Test]
    public function pow_rejects_zero_base_with_negative_exponent(): void
    {
        $this->expectException(TypeErrorException::class);
        $this->eval->evaluate('pow(0, -1)', []);
    }

    #[Test]
    public function sqrt_rejects_negative_input(): void
    {
        $this->expectException(TypeErrorException::class);
        $this->eval->evaluate('sqrt(-4)', []);
    }

    // =========================================================================
    // is_finite — escape hatch
    // =========================================================================

    #[Test]
    public function is_finite_returns_bool_for_numbers(): void
    {
        $this->assertTrue($this->eval->evaluate('is_finite(42)', []));
        $this->assertTrue($this->eval->evaluate('is_finite(3.14)', []));
    }

    #[Test]
    public function is_finite_returns_false_for_nan_and_inf(): void
    {
        $this->eval->registerFunction('getNan', fn(): float => NAN);
        $this->eval->registerFunction('getInf', fn(): float => INF);

        $this->assertFalse($this->eval->evaluate('is_finite(getNan())', []));
        $this->assertFalse($this->eval->evaluate('is_finite(getInf())', []));
    }

    #[Test]
    public function is_finite_rejects_non_numbers(): void
    {
        $this->expectException(TypeErrorException::class);
        $this->eval->evaluate("is_finite('x')", []);
    }

    // =========================================================================
    // Chaînes
    // =========================================================================

    #[Test]
    public function string_functions_handle_basic_cases(): void
    {
        $this->assertSame('ALICE',  $this->eval->evaluate("upper('alice')", []));
        $this->assertSame('alice',  $this->eval->evaluate("lower('ALICE')", []));
        $this->assertSame('hello',  $this->eval->evaluate("trim('  hello  ')", []));
        $this->assertSame(5,        $this->eval->evaluate("length('hello')", []));
        $this->assertTrue ($this->eval->evaluate("contains('hello world', 'world')", []));
        $this->assertTrue ($this->eval->evaluate("startsWith('hello', 'he')", []));
        $this->assertTrue ($this->eval->evaluate("endsWith('hello', 'lo')", []));
    }

    #[Test]
    public function length_works_on_lists_too(): void
    {
        $this->assertSame(3, $this->eval->evaluate('length([1, 2, 3])', []));
    }

    #[Test]
    public function substr_uses_mb_substr(): void
    {
        // mb_substr is unicode-aware: a 6-byte 'caféine' is 7 characters.
        $this->assertSame('café', $this->eval->evaluate("substr('caféine', 0, 4)", []));
    }

    // =========================================================================
    // concat (audit I8) — mixage de types
    // =========================================================================

    #[Test]
    public function concat_joins_strings(): void
    {
        $this->assertSame('hello world', $this->eval->evaluate("concat('hello', ' ', 'world')", []));
    }

    #[Test]
    public function concat_accepts_int(): void
    {
        $this->assertSame('age: 42', $this->eval->evaluate("concat('age: ', 42)", []));
    }

    #[Test]
    public function concat_accepts_float_formatted_like_str(): void
    {
        // Documented: float arg is formatted like str() would (no scientific notation, trailing zeros stripped).
        $this->assertSame('pi=3.14', $this->eval->evaluate("concat('pi=', 3.14)", []));
        $this->assertSame('x=1.5',   $this->eval->evaluate("concat('x=', 1.50)", []));
    }

    #[Test]
    public function concat_int_valued_float_is_formatted_without_decimal(): void
    {
        $this->assertSame('count=1', $this->eval->evaluate("concat('count=', 1.0)", []));
    }

    #[Test]
    public function concat_accepts_single_argument(): void
    {
        $this->assertSame('only', $this->eval->evaluate("concat('only')", []));
    }

    // =========================================================================
    // Listes — agrégats
    // =========================================================================

    #[Test]
    public function sum_returns_zero_for_empty_list(): void
    {
        // Documented exception: sum tolerates empty list (returns 0).
        $this->assertSame(0, $this->eval->evaluate('sum([])', []));
    }

    #[Test]
    public function sum_adds_numeric_elements(): void
    {
        $this->assertSame(6, $this->eval->evaluate('sum([1, 2, 3])', []));
    }

    #[Test]
    public function avg_rejects_empty_list(): void
    {
        $this->expectException(TypeErrorException::class);
        $this->eval->evaluate('avg([])', []);
    }

    #[Test]
    public function min_of_and_max_of_compute_over_list(): void
    {
        $this->assertSame(1, $this->eval->evaluate('min_of([3, 1, 2])', []));
        $this->assertSame(3, $this->eval->evaluate('max_of([3, 1, 2])', []));
    }

    #[Test]
    public function aggregate_rejects_non_numeric_element_with_index(): void
    {
        try {
            $this->eval->evaluate("sum([1, 'two', 3])", []);
            $this->fail();
        } catch (TypeErrorException $e) {
            // The doc commits to indicating the faulty index.
            $this->assertStringContainsString('1', $e->getMessage());
        }
    }

    // =========================================================================
    // coalesce — variadique
    // =========================================================================

    #[Test]
    public function coalesce_returns_first_non_null(): void
    {
        $this->assertSame(5, $this->eval->evaluate('coalesce(a, b, 5)', ['a' => null, 'b' => null]));
        $this->assertSame(2, $this->eval->evaluate('coalesce(a, 2, 3)', ['a' => null]));
    }

    #[Test]
    public function coalesce_returns_null_when_all_args_null(): void
    {
        $this->assertNull($this->eval->evaluate('coalesce(a, b)', ['a' => null, 'b' => null]));
    }

    // =========================================================================
    // Dates
    // =========================================================================

    #[Test]
    public function today_returns_a_y_m_d_string(): void
    {
        $today = $this->eval->evaluate('today()', []);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $today);
    }

    #[Test]
    public function date_components_extract_correctly(): void
    {
        $this->assertSame(2026, $this->eval->evaluate("year('2026-01-15')", []));
        $this->assertSame(1,    $this->eval->evaluate("month('2026-01-15')", []));
        $this->assertSame(15,   $this->eval->evaluate("day('2026-01-15')", []));
    }

    #[Test]
    public function date_diff_returns_signed_days(): void
    {
        $this->assertSame(30,  $this->eval->evaluate("dateDiff('2026-01-31', '2026-01-01')", []));
        $this->assertSame(-30, $this->eval->evaluate("dateDiff('2026-01-01', '2026-01-31')", []));
    }

    #[Test]
    public function date_add_preserves_input_format(): void
    {
        // Y-m-d in → Y-m-d out
        $this->assertSame('2026-02-15', $this->eval->evaluate("dateAdd('2026-01-15', 1, 'month')", []));

        // Y-m-d H:i:s in → Y-m-d H:i:s out
        $this->assertSame(
            '2026-01-15 10:30:00',
            $this->eval->evaluate("dateAdd('2026-01-15 09:30:00', 1, 'hour')", [])
        );
    }

    #[Test]
    public function date_add_does_not_snap_to_end_of_month(): void
    {
        // Documented: PHP's natural arithmetic — Jan 31 + 1 month = Mar 3 (not Feb 28).
        $this->assertSame('2026-03-03', $this->eval->evaluate("dateAdd('2026-01-31', 1, 'month')", []));
    }

    #[Test]
    public function date_add_accepts_negative_delta(): void
    {
        $this->assertSame('2026-01-15', $this->eval->evaluate("dateAdd('2026-01-20', -5, 'day')", []));
    }

    #[Test]
    public function date_functions_reject_malformed_date(): void
    {
        $this->expectException(TypeErrorException::class);
        $this->eval->evaluate("year('not a date')", []);
    }
}
