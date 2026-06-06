<?php declare(strict_types=1);

namespace Ols\PhpRuler\Tests;

use Ols\PhpRuler\Exception\SyntaxErrorException;
use Ols\PhpRuler\ExpressionEvaluator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Couvre static-analysis.md.
 *
 * Static analysis inspects an expression without evaluating it:
 *   - extractVariables() — list referenced variable paths
 *   - extractFunctions() — list called function names
 *   - validate()         — parse-only check
 *
 * All three traverse the AST exhaustively (no short-circuit, no branch choice):
 * data presence does not influence the result.
 */
final class StaticAnalysisTest extends TestCase
{
    private ExpressionEvaluator $eval;

    protected function setUp(): void
    {
        $this->eval = new ExpressionEvaluator();
    }

    // =========================================================================
    // extractVariables
    // =========================================================================

    #[Test]
    public function extract_variables_returns_each_referenced_path(): void
    {
        $this->assertSame(
            ['a', 'b.c', 'd', 'e'],
            $this->eval->extractVariables('a > 0 AND b.c = d ?? e')
        );
    }

    #[Test]
    public function extract_variables_deduplicates_repeated_paths(): void
    {
        $this->assertSame(['x'], $this->eval->extractVariables('x + x + x'));
    }

    #[Test]
    public function extract_variables_sorts_results_alphabetically(): void
    {
        // The doc commits to alphabetical sort to ease equality assertions on caller side.
        $this->assertSame(
            ['cart.shipping', 'cart.total'],
            $this->eval->extractVariables('cart.total + cart.shipping')
        );
    }

    #[Test]
    public function extract_variables_visits_both_branches_of_a_ternary(): void
    {
        // Critical guarantee from the doc: static analysis doesn't know runtime values,
        // so it visits BOTH branches of a ternary, regardless of the condition.
        $this->assertSame(
            ['a', 'b', 'c'],
            $this->eval->extractVariables('a > 0 ? b : c')
        );
    }

    #[Test]
    public function extract_variables_descends_into_function_arguments(): void
    {
        $this->assertSame(
            ['cart.total', 'precision'],
            $this->eval->extractVariables('round(cart.total, precision)')
        );
    }

    #[Test]
    public function extract_variables_descends_into_nested_function_calls(): void
    {
        $this->assertSame(
            ['a', 'b', 'c'],
            $this->eval->extractVariables('max(a, min(b, c))')
        );
    }

    #[Test]
    public function extract_variables_returns_empty_array_when_no_variables(): void
    {
        $this->assertSame([], $this->eval->extractVariables('1 + 2 * 3'));
    }

    #[Test]
    public function extract_variables_visits_in_subject_and_list_variable(): void
    {
        $this->assertSame(['list', 'x'], $this->eval->extractVariables('x IN list'));
    }

    #[Test]
    public function extract_variables_visits_both_sides_of_coalesce(): void
    {
        $this->assertSame(['a', 'b'], $this->eval->extractVariables('a ?? b'));
    }

    #[Test]
    public function extract_variables_visits_both_sides_of_logical_and(): void
    {
        // No short-circuit at static analysis time.
        $this->assertSame(['a', 'b'], $this->eval->extractVariables('a AND b'));
    }

    #[Test]
    public function extract_variables_visits_operand_of_unary_operator(): void
    {
        $this->assertSame(['a'], $this->eval->extractVariables('-a'));
        $this->assertSame(['a'], $this->eval->extractVariables('NOT a'));
    }

    #[Test]
    public function extract_variables_throws_syntax_error_on_malformed_expression(): void
    {
        $this->expectException(SyntaxErrorException::class);
        $this->eval->extractVariables('a +');
    }

    // =========================================================================
    // extractFunctions
    // =========================================================================

    #[Test]
    public function extract_functions_returns_each_called_function(): void
    {
        $this->assertSame(
            ['min', 'round'],
            $this->eval->extractFunctions('round(a, 2) > min(b, c)')
        );
    }

    #[Test]
    public function extract_functions_deduplicates_repeated_calls(): void
    {
        $this->assertSame(['upper'], $this->eval->extractFunctions('upper(a) + upper(b)'));
    }

    #[Test]
    public function extract_functions_sorts_results_alphabetically(): void
    {
        $this->assertSame(
            ['abs', 'min', 'round'],
            $this->eval->extractFunctions('round(a) + min(b, c) - abs(d)')
        );
    }

    #[Test]
    public function extract_functions_descends_into_nested_calls(): void
    {
        $this->assertSame(
            ['floor', 'round', 'sqrt'],
            $this->eval->extractFunctions('floor(round(sqrt(x), 2))')
        );
    }

    #[Test]
    public function extract_functions_returns_empty_array_when_no_functions(): void
    {
        $this->assertSame([], $this->eval->extractFunctions('a + b > c'));
    }

    #[Test]
    public function extract_functions_visits_function_in_ternary_branches(): void
    {
        // Both branches walked regardless of the condition.
        $this->assertSame(
            ['floor', 'round'],
            $this->eval->extractFunctions('a > 0 ? round(b, 2) : floor(c)')
        );
    }

    #[Test]
    public function extract_functions_throws_syntax_error_on_malformed_expression(): void
    {
        $this->expectException(SyntaxErrorException::class);
        $this->eval->extractFunctions('round(a,');
    }

    // =========================================================================
    // validate
    // =========================================================================

    #[Test]
    public function validate_does_not_throw_on_well_formed_expression(): void
    {
        // The contract: returns nothing on success.
        $this->eval->validate('cart.total > 100');
        $this->eval->validate('upper(a) = "ALICE"');
        $this->eval->validate('a > 0 AND (b OR c)');
        $this->addToAssertionCount(3);
    }

    #[Test]
    public function validate_throws_syntax_error_with_position_on_invalid_input(): void
    {
        try {
            $this->eval->validate('a + ');
            $this->fail('Expected SyntaxErrorException');
        } catch (SyntaxErrorException $e) {
            $this->assertIsInt($e->position);
        }
    }

    #[Test]
    public function validate_does_not_check_function_existence(): void
    {
        // The doc is explicit: validate() only checks syntax. Unknown functions
        // are not surfaced here — that's the role of extractFunctions + getFunctions.
        $this->eval->validate('totallyMadeUpFn(a)');
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function validate_does_not_check_variable_presence(): void
    {
        // Same rationale: no context, no variable validation.
        $this->eval->validate('any.path.at.all > 0');
        $this->addToAssertionCount(1);
    }

    // =========================================================================
    // Combined usage — typical backoffice pattern
    // =========================================================================

    #[Test]
    public function combining_extract_functions_with_get_functions_detects_unknown_calls(): void
    {
        // Documented usage pattern: filter user rules against an allowlist of registered fns.
        $used      = $this->eval->extractFunctions('round(a) + unknownFn(b)');
        $available = $this->eval->getFunctions();
        $unknown   = array_diff($used, $available);

        $this->assertSame(['unknownFn'], array_values($unknown));
    }

    #[Test]
    public function combining_extract_variables_with_describe_context_detects_missing_inputs(): void
    {
        $required = $this->eval->extractVariables('cart.total > threshold');
        $present  = array_column($this->eval->describeContext(['cart' => ['total' => 100]]), 'path');
        $missing  = array_diff($required, $present);

        $this->assertSame(['threshold'], array_values($missing));
    }
}
