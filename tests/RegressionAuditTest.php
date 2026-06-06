<?php declare(strict_types=1);

namespace Ols\PhpRuler\Tests;

use Ols\PhpRuler\Exception\EvaluatorException;
use Ols\PhpRuler\Exception\TypeErrorException;
use Ols\PhpRuler\ExpressionEvaluator;
use Ols\PhpRuler\Explainer\ExpressionExplainer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Régressions issues de l'audit (correctifs F2, F3, F6, F7, F8).
 * Chaque test verrouille un comportement qui n'était pas couvert
 * auparavant et que les correctifs garantissent désormais.
 */
final class RegressionAuditTest extends TestCase
{
    private ExpressionEvaluator $eval;

    protected function setUp(): void
    {
        $this->eval = new ExpressionEvaluator();
    }

    /** Build a left-deep expression that exceeds MAX_EVAL_DEPTH (=200). */
    private static function deepExpression(int $n): string
    {
        $expr = '1';
        for ($i = 0; $i < $n; $i++) {
            $expr = '(' . $expr . ' + 1)';
        }
        return $expr;
    }

    // =========================================================================
    // F2 — le garde de profondeur ne doit PAS empoisonner le compteur
    // =========================================================================

    #[Test]
    public function depth_guard_does_not_poison_the_counter_for_subsequent_calls(): void
    {
        $deep = self::deepExpression(250);

        // Premier dépassement : doit lever.
        try {
            $this->eval->evaluate($deep, []);
            $this->fail('First deep evaluation should have tripped the depth guard');
        } catch (EvaluatorException $e) {
            $this->assertStringContainsString('depth limit exceeded', $e->getMessage());
        }

        // Le garde doit TOUJOURS se déclencher au second appel identique
        // (avant le correctif, le compteur restait négatif et le garde
        // ne se redéclenchait plus — l'expression renvoyait 251).
        $this->eval->clearCache();
        $this->expectException(EvaluatorException::class);
        $this->eval->evaluate($deep, []);
    }

    #[Test]
    public function evaluator_stays_usable_after_a_depth_overflow(): void
    {
        try {
            $this->eval->evaluate(self::deepExpression(250), []);
        } catch (EvaluatorException) {
            // attendu
        }

        // Une évaluation normale sur la même instance doit fonctionner sainement.
        $this->assertSame(3, $this->eval->evaluate('1 + 2', []));
        $this->assertTrue($this->eval->evaluate('5 > 3', []));
    }

    // =========================================================================
    // F3 — NaN/INF transitent jusqu'à is_finite() (pas de rejet à l'entrée)
    // =========================================================================

    #[Test]
    public function nan_transits_through_a_bare_variable_without_throwing(): void
    {
        $result = $this->eval->evaluate('x', ['x' => NAN]);
        $this->assertTrue(is_float($result) && is_nan($result));
    }

    #[Test]
    public function inf_transits_through_a_bare_function_return_without_throwing(): void
    {
        $this->eval->registerFunction('getInf', fn(): float => INF);
        $result = $this->eval->evaluate('getInf()', []);
        $this->assertTrue(is_float($result) && is_infinite($result));
    }

    #[Test]
    public function but_nan_is_still_rejected_as_soon_as_it_enters_an_operator(): void
    {
        $this->expectException(TypeErrorException::class);
        $this->eval->evaluate('x + 1', ['x' => NAN]);
    }

    // =========================================================================
    // F7 — null = <NaN> lève (le NaN est rejeté avant le raccourci null)
    // =========================================================================

    #[Test]
    public function null_equality_against_nan_still_raises(): void
    {
        $this->expectException(TypeErrorException::class);
        $this->expectExceptionMessage('NaN');
        $this->eval->evaluate('null = x', ['x' => NAN]);
    }

    #[Test]
    public function null_equality_against_a_plain_value_does_not_raise(): void
    {
        // Le cas nominal reste sans exception.
        $this->assertFalse($this->eval->evaluate('null = 42', []));
        $this->assertTrue($this->eval->evaluate('null = null', []));
    }

    // =========================================================================
    // F6 — int('<chaîne>') hors plage est rejeté, pas clampé silencieusement
    // =========================================================================

    #[Test]
    public function int_rejects_an_out_of_range_integer_string(): void
    {
        $this->expectException(TypeErrorException::class);
        $this->expectExceptionMessage('out of range');
        // PHP_INT_MAX + beaucoup → (int) clamperait silencieusement.
        $this->eval->evaluate("int('99999999999999999999999')", []);
    }

    #[Test]
    #[DataProvider('inRangeIntStringsProvider')]
    public function int_still_accepts_in_range_integer_strings(string $input, int $expected): void
    {
        $this->assertSame($expected, $this->eval->evaluate("int('$input')", []));
    }

    public static function inRangeIntStringsProvider(): array
    {
        return [
            'plain'            => ['42', 42],
            'negative'         => ['-7', -7],
            'leading zeros'    => ['007', 7],
            'negative zeros'   => ['-0', 0],
            'php int max'      => [(string) PHP_INT_MAX, PHP_INT_MAX],
            'php int min'      => [(string) PHP_INT_MIN, PHP_INT_MIN],
        ];
    }

    // =========================================================================
    // F8 — l'Explainer round-trippe un ternaire en position de condition
    // =========================================================================

    #[Test]
    public function explainer_parenthesises_a_ternary_used_as_a_condition(): void
    {
        $explainer = new ExpressionExplainer($this->eval);
        $ctx = ['a' => true, 'b' => true, 'c' => false, 'd' => 1, 'e' => 2];

        $result = $explainer->explain('(a ? b : c) ? d : e', $ctx);

        // La reconstruction doit conserver les parenthèses autour de la condition.
        $this->assertSame('(a ? b : c) ? d : e', $result->root->expression);

        // Et elle doit être ré-injectable : un second passage donne le même rendu.
        $again = $explainer->explain($result->root->expression, $ctx);
        $this->assertSame($result->root->expression, $again->root->expression);
    }

    #[Test]
    public function explainer_still_prints_right_associative_ternary_without_extra_parens(): void
    {
        $explainer = new ExpressionExplainer($this->eval);
        $ctx = ['a' => true, 'b' => 1, 'c' => true, 'd' => 2, 'e' => 3];

        // else-position nested ternary = forme right-associative naturelle, pas de parens.
        $result = $explainer->explain('a ? b : c ? d : e', $ctx);
        $this->assertSame('a ? b : c ? d : e', $result->root->expression);
    }
}
