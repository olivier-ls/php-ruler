<?php declare(strict_types=1);

namespace Ols\PhpRuler\Tests;

use Ols\PhpRuler\AliasResolver;
use Ols\PhpRuler\Evaluator\SafeResult;
use Ols\PhpRuler\Exception\CircularContextException;
use Ols\PhpRuler\Exception\EvaluatorException;
use Ols\PhpRuler\Exception\SyntaxErrorException;
use Ols\PhpRuler\Exception\TypeErrorException;
use Ols\PhpRuler\Exception\UnknownVariableException;
use Ols\PhpRuler\ExpressionEvaluator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Couvre exceptions.md.
 *
 * Tests the exception hierarchy, propagation policy, and the chaining
 * guarantees for custom functions. These are transverse concerns, but
 * critical contracts of the public API.
 */
final class ExceptionsTest extends TestCase
{
    private ExpressionEvaluator $eval;

    protected function setUp(): void
    {
        $this->eval = new ExpressionEvaluator();
    }

    // =========================================================================
    // Hiérarchie
    // =========================================================================

    #[Test]
    public function evaluator_exception_extends_runtime_exception(): void
    {
        $this->assertTrue(is_subclass_of(EvaluatorException::class, \RuntimeException::class));
    }

    #[Test]
    public function all_lib_exceptions_extend_evaluator_exception(): void
    {
        $this->assertTrue(is_subclass_of(SyntaxErrorException::class,     EvaluatorException::class));
        $this->assertTrue(is_subclass_of(TypeErrorException::class,       EvaluatorException::class));
        $this->assertTrue(is_subclass_of(UnknownVariableException::class, EvaluatorException::class));
        $this->assertTrue(is_subclass_of(CircularContextException::class, EvaluatorException::class));
    }

    #[Test]
    public function catching_evaluator_exception_catches_all_subclasses(): void
    {
        // The doc highlights this as the main benefit of the hierarchy.
        $caught = 0;
        $cases = [
            fn() => $this->eval->evaluate('@',         []),                   // SyntaxError
            fn() => $this->eval->evaluate('missing',   []),                   // UnknownVariable
            fn() => $this->eval->evaluate("'a' AND b", ['b' => true]),        // TypeError
        ];
        foreach ($cases as $run) {
            try { $run(); } catch (EvaluatorException) { $caught++; }
        }
        $this->assertSame(3, $caught);
    }

    // =========================================================================
    // SyntaxErrorException — porte une position
    // =========================================================================

    #[Test]
    public function syntax_error_exposes_position_in_bytes(): void
    {
        try {
            $this->eval->evaluate('a + ', []);
            $this->fail('Expected SyntaxErrorException');
        } catch (SyntaxErrorException $e) {
            $this->assertIsInt($e->position);
            $this->assertGreaterThanOrEqual(0, $e->position);
        }
    }

    #[Test]
    public function syntax_error_position_is_readable(): void
    {
        try {
            $this->eval->evaluate('@', []);
            $this->fail();
        } catch (SyntaxErrorException $e) {
            $this->assertSame(0, $e->position);
        }
    }

    #[Test]
    public function validate_only_throws_syntax_error(): void
    {
        // validate() may throw SyntaxError, but should NEVER touch the context
        // (no UnknownVariable possible — never evaluated).
        try {
            $this->eval->validate('a + ');
            $this->fail();
        } catch (SyntaxErrorException) {
            $this->assertTrue(true);
        }
    }

    // =========================================================================
    // UnknownVariableException — message detail
    // =========================================================================

    #[Test]
    public function unknown_variable_at_root_has_no_failed_at_suffix(): void
    {
        try {
            $this->eval->evaluate('customer', []);
            $this->fail();
        } catch (UnknownVariableException $e) {
            $this->assertSame('Unknown variable: "customer"', $e->getMessage());
        }
    }

    #[Test]
    public function unknown_variable_in_a_nested_path_has_failed_at_suffix(): void
    {
        try {
            $this->eval->evaluate('cart.shipping', ['cart' => ['total' => 100]]);
            $this->fail();
        } catch (UnknownVariableException $e) {
            $this->assertStringContainsString('Unknown variable: "cart.shipping"', $e->getMessage());
            $this->assertStringContainsString('failed at', $e->getMessage());
        }
    }

    // =========================================================================
    // TypeErrorException — politique générale
    // =========================================================================

    #[Test]
    public function type_error_is_raised_on_logical_with_non_bool(): void
    {
        $this->expectException(TypeErrorException::class);
        $this->eval->evaluate("'hello' AND true", []);
    }

    #[Test]
    public function type_error_is_raised_on_division_by_zero(): void
    {
        $this->expectException(TypeErrorException::class);
        $this->expectExceptionMessage('Division by zero');
        $this->eval->evaluate('1 / 0', []);
    }

    #[Test]
    public function type_error_is_raised_on_array_equality(): void
    {
        // The doc explicitly forbids direct array equality.
        $this->expectException(TypeErrorException::class);
        $this->eval->evaluate('a = b', ['a' => [1, 2], 'b' => [1, 2]]);
    }

    // =========================================================================
    // Custom function exception policy — la garantie centrale
    // =========================================================================

    #[Test]
    public function runtime_exception_in_custom_function_is_wrapped_in_type_error(): void
    {
        $this->eval->registerFunction('boom', fn() => throw new \RuntimeException('boom rt'));

        try {
            $this->eval->evaluate('boom()', []);
            $this->fail('Expected TypeErrorException');
        } catch (TypeErrorException $e) {
            $this->assertStringContainsString('Error in function "boom"', $e->getMessage());
            $this->assertStringContainsString('boom rt', $e->getMessage());
            $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
            $this->assertSame('boom rt', $e->getPrevious()->getMessage());
        }
    }

    #[Test]
    public function logic_exception_in_custom_function_is_wrapped_in_type_error(): void
    {
        $this->eval->registerFunction('boom', fn() => throw new \LogicException('logic err'));

        try {
            $this->eval->evaluate('boom()', []);
            $this->fail();
        } catch (TypeErrorException $e) {
            $this->assertInstanceOf(\LogicException::class, $e->getPrevious());
        }
    }

    #[Test]
    public function php_type_error_in_custom_function_is_wrapped_in_type_error(): void
    {
        // \TypeError is a native PHP Error (not Exception). The lib must still wrap it.
        $this->eval->registerFunction('boom', function() { throw new \TypeError('php type err'); });

        try {
            $this->eval->evaluate('boom()', []);
            $this->fail();
        } catch (TypeErrorException $e) {
            $this->assertInstanceOf(\TypeError::class, $e->getPrevious());
        }
    }

    #[Test]
    public function invalid_argument_in_custom_function_is_wrapped_in_type_error(): void
    {
        $this->eval->registerFunction('boom', function() { throw new \InvalidArgumentException('inv'); });

        try {
            $this->eval->evaluate('boom()', []);
            $this->fail();
        } catch (TypeErrorException $e) {
            $this->assertInstanceOf(\InvalidArgumentException::class, $e->getPrevious());
        }
    }

    #[Test]
    public function lib_exception_in_custom_function_transits_unchanged(): void
    {
        // A custom function that internally throws a lib exception must NOT be wrapped.
        // The doc presents this as a strong guarantee: callers can rely on the
        // typed exception even when produced by user code.
        $this->eval->registerFunction('boom', function() {
            throw new TypeErrorException('original lib error');
        });

        try {
            $this->eval->evaluate('boom()', []);
            $this->fail();
        } catch (TypeErrorException $e) {
            // Pass-through: same message, no "Error in function..." prefix, no previous.
            $this->assertSame('original lib error', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
    }

    #[Test]
    public function unknown_variable_thrown_from_custom_function_transits_unchanged(): void
    {
        // Crucial subtlety from evaluate.md/functions.md: an UnknownVariableException
        // raised inside a custom function propagates as-is. It is NOT converted
        // to "missing" by evaluateSafe — see evaluate-safe.md for that guarantee.
        $this->eval->registerFunction('lookup', function() {
            throw new UnknownVariableException('Unknown variable: "x"');
        });

        $this->expectException(UnknownVariableException::class);
        $this->eval->evaluate('lookup()', []);
    }

    // =========================================================================
    // SafeResult exceptions — \LogicException sur accès à value en cas d'échec
    // =========================================================================

    #[Test]
    public function safe_result_get_value_throws_logic_exception_when_success_is_false(): void
    {
        $result = $this->eval->evaluateSafe('missing', []);
        $this->assertInstanceOf(SafeResult::class, $result);
        $this->assertFalse($result->success);

        $this->expectException(\LogicException::class);
        $result->getValue();
    }

    // =========================================================================
    // importAst — \InvalidArgumentException (hors hiérarchie EvaluatorException)
    // =========================================================================

    #[Test]
    public function import_ast_throws_invalid_argument_on_garbage_input(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->eval->importAst('not_valid_serialized_data');
    }

    #[Test]
    public function import_ast_throws_invalid_argument_on_empty_input(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->eval->importAst('');
    }

    #[Test]
    public function import_ast_rejects_arbitrary_class_payload(): void
    {
        // The unserialize whitelist restricts allowed classes to Node descendants.
        $serializedStdClass = serialize(new \stdClass());

        $this->expectException(\InvalidArgumentException::class);
        $this->eval->importAst($serializedStdClass);
    }

    // =========================================================================
    // AliasResolver — \InvalidArgumentException (hors hiérarchie)
    // =========================================================================

    #[Test]
    public function alias_resolver_throws_invalid_argument_on_invalid_alias(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new AliasResolver())->add('cart.total', 'foo+bar');
    }

    // =========================================================================
    // Garantie : aucune exception PHP brute ne sort de evaluate()
    // =========================================================================

    #[Test]
    public function evaluate_never_propagates_raw_php_errors_from_custom_functions(): void
    {
        // Test the contract globally: whatever the user code throws, the caller
        // only ever sees a lib exception.
        $rawExceptions = [
            fn() => throw new \RuntimeException('rt'),
            fn() => throw new \LogicException('lg'),
            fn() => throw new \Exception('generic'),
            fn() => throw new \TypeError('type'),
            fn() => throw new \DivisionByZeroError('div'),
        ];

        foreach ($rawExceptions as $i => $fn) {
            $eval = new ExpressionEvaluator();
            $eval->registerFunction("f$i", $fn);
            try {
                $eval->evaluate("f$i()", []);
                $this->fail("Case $i: expected an exception");
            } catch (\Throwable $e) {
                $this->assertInstanceOf(
                    EvaluatorException::class,
                    $e,
                    "Case $i: expected EvaluatorException, got " . $e::class
                );
            }
        }
    }
}
