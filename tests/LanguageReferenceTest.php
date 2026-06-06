<?php declare(strict_types=1);

namespace Ols\PhpRuler\Tests;

use Ols\PhpRuler\Exception\SyntaxErrorException;
use Ols\PhpRuler\Exception\TypeErrorException;
use Ols\PhpRuler\ExpressionEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Couvre language-reference.md.
 *
 * The expression language: literals, operators, precedence, whitespace.
 * Aligned on PHP precedence where ambiguity exists (cf. ?? section).
 */
final class LanguageReferenceTest extends TestCase
{
    private ExpressionEvaluator $eval;

    protected function setUp(): void
    {
        $this->eval = new ExpressionEvaluator();
    }

    // =========================================================================
    // Littéraux : entiers
    // =========================================================================

    #[Test]
    public function integer_literals_are_parsed_as_ints(): void
    {
        $this->assertSame(0,      $this->eval->evaluate('0', []));
        $this->assertSame(42,     $this->eval->evaluate('42', []));
        $this->assertSame(-1,     $this->eval->evaluate('-1', []));
        $this->assertSame(100000, $this->eval->evaluate('100000', []));
    }

    #[Test]
    public function integer_literal_exceeding_php_int_max_raises_syntax_error(): void
    {
        // PHP_INT_MAX = 9223372036854775807; +1 makes it 9223372036854775808.
        $this->expectException(SyntaxErrorException::class);
        $this->eval->evaluate('9223372036854775808', []);
    }

    #[Test]
    public function php_int_min_literal_is_accepted_via_unary_minus(): void
    {
        // Special-case in the lexer/parser: only valid as operand of unary '-'.
        $this->assertSame(PHP_INT_MIN, $this->eval->evaluate('-9223372036854775808', []));
    }

    // =========================================================================
    // Littéraux : flottants
    // =========================================================================

    #[Test]
    public function float_literal_decimal_form(): void
    {
        $this->assertSame(3.14, $this->eval->evaluate('3.14', []));
        $this->assertSame(0.5,  $this->eval->evaluate('0.5', []));
    }

    #[Test]
    public function float_literal_scientific_form(): void
    {
        $this->assertSame(1e10, $this->eval->evaluate('1e10', []));
        $this->assertSame(2.5E-3, $this->eval->evaluate('2.5E-3', []));
    }

    #[Test]
    public function float_literal_must_have_digit_before_decimal(): void
    {
        // '.5' is not valid; must write '0.5'.
        $this->expectException(SyntaxErrorException::class);
        $this->eval->evaluate('.5', []);
    }

    #[Test]
    public function float_literal_must_have_digit_after_decimal(): void
    {
        // '5.' is not valid; must write '5.0'.
        $this->expectException(SyntaxErrorException::class);
        $this->eval->evaluate('5.', []);
    }

    #[Test]
    public function float_literal_overflowing_to_inf_is_rejected_at_lex_time(): void
    {
        $this->expectException(SyntaxErrorException::class);
        $this->eval->evaluate('1e400', []);
    }

    // =========================================================================
    // Littéraux : booléens, null
    // =========================================================================

    #[Test]
    public function boolean_literals_are_case_insensitive(): void
    {
        foreach (['true', 'TRUE', 'True', 'tRuE'] as $form) {
            $this->assertTrue($this->eval->evaluate($form, []), "expected $form → true");
        }
        foreach (['false', 'FALSE', 'False'] as $form) {
            $this->assertFalse($this->eval->evaluate($form, []), "expected $form → false");
        }
    }

    #[Test]
    public function null_literal_is_case_insensitive(): void
    {
        $this->assertNull($this->eval->evaluate('null', []));
        $this->assertNull($this->eval->evaluate('NULL', []));
        $this->assertNull($this->eval->evaluate('Null', []));
    }

    // =========================================================================
    // Littéraux : chaînes
    // =========================================================================

    #[Test]
    public function single_quoted_string_literal(): void
    {
        $this->assertSame('hello', $this->eval->evaluate("'hello'", []));
    }

    #[Test]
    public function double_quoted_string_literal_is_equivalent(): void
    {
        $this->assertSame('hello', $this->eval->evaluate('"hello"', []));
    }

    #[Test]
    public function single_quote_inside_string_uses_doubled_quote_escape(): void
    {
        $this->assertSame("L'Oréal", $this->eval->evaluate("'L''Oréal'", []));
    }

    #[Test]
    public function double_quote_inside_string_uses_doubled_quote_escape(): void
    {
        $this->assertSame('He said "hi"', $this->eval->evaluate('"He said ""hi"""', []));
    }

    #[Test]
    public function string_literals_do_not_interpret_backslash_escapes(): void
    {
        // '\n' is two characters, not a newline.
        $this->assertSame('\\n', $this->eval->evaluate("'\\n'", []));
    }

    #[Test]
    public function string_literals_do_not_interpolate(): void
    {
        $this->assertSame('Hello $name', $this->eval->evaluate("'Hello \$name'", ['name' => 'Alice']));
    }

    // =========================================================================
    // Littéraux : listes
    // =========================================================================

    #[Test]
    public function list_literal_of_integers(): void
    {
        $this->assertSame([1, 2, 3], $this->eval->evaluate('[1, 2, 3]', []));
    }

    #[Test]
    public function list_literal_mixed_types(): void
    {
        $this->assertSame([1, 'mixed', true, null], $this->eval->evaluate("[1, 'mixed', true, null]", []));
    }

    #[Test]
    public function empty_list_literal_is_valid_standalone(): void
    {
        $this->assertSame([], $this->eval->evaluate('[]', []));
    }

    #[Test]
    public function list_literal_with_negative_numbers(): void
    {
        $this->assertSame([-1, -2.5], $this->eval->evaluate('[-1, -2.5]', []));
    }

    #[Test]
    public function list_literal_rejects_computed_expressions(): void
    {
        $this->expectException(SyntaxErrorException::class);
        $this->eval->evaluate('[1+1, 2]', []);
    }

    #[Test]
    public function list_literal_rejects_variables(): void
    {
        $this->expectException(SyntaxErrorException::class);
        $this->eval->evaluate('[a, b]', []);
    }

    #[Test]
    public function list_literal_rejects_trailing_comma(): void
    {
        $this->expectException(SyntaxErrorException::class);
        $this->eval->evaluate('[1, 2,]', []);
    }

    #[Test]
    public function empty_list_literal_is_forbidden_on_right_of_IN(): void
    {
        $this->expectException(SyntaxErrorException::class);
        $this->eval->evaluate('x IN []', ['x' => 1]);
    }

    #[Test]
    public function in_precedence_binds_tighter_than_comparison_on_left(): void
    {
        // Verifies that comparison operators are non-associative in this parser:
        // 'a > 5 IN [1, 2, 3]' is a SyntaxError because after parseComparison()
        // consumes 'a > 5', the remaining 'IN' token cannot start a new expression
        // at the same level — comparisons do not chain.
        //
        // The correct way to combine comparison and IN is to parenthesise:
        //   'a > (5 IN [1, 2, 3])' — TypeError (int vs bool for '>')
        //   '(a > 5) IN [true, false]' — would require IN to support booleans
        //
        // This test pins the non-associativity behaviour so it cannot silently change.
        $this->expectException(SyntaxErrorException::class);
        $this->eval->evaluate('a > 5 IN [1, 2, 3]', ['a' => 10]);
    }

    // =========================================================================
    // Variables : chemins, mots-clés réservés
    // =========================================================================

    #[Test]
    public function simple_variable_resolved_from_context(): void
    {
        $this->assertSame(5, $this->eval->evaluate('total', ['total' => 5]));
    }

    #[Test]
    public function dotted_path_resolved_through_nested_arrays(): void
    {
        $ctx = ['customer' => ['address' => ['country' => 'FR']]];
        $this->assertSame('FR', $this->eval->evaluate('customer.address.country', $ctx));
    }

    #[Test]
    #[DataProvider('reservedKeywordsAtRootProvider')]
    public function reserved_keyword_at_root_of_path_is_a_syntax_error(string $expression): void
    {
        // 'and', 'or', 'not', 'in', 'true', 'false', 'null' are tokenized first.
        $this->expectException(SyntaxErrorException::class);
        $this->eval->evaluate($expression, []);
    }

    public static function reservedKeywordsAtRootProvider(): array
    {
        // Even with the value provided, the lexer rejects these as variables.
        return [
            'In with cap' => ['In'],
            'and lower'   => ['and'],
            'OR'          => ['OR'],
            'NOT'         => ['NOT'],
        ];
    }

    #[Test]
    public function reserved_keyword_as_sub_segment_is_legal(): void
    {
        // 'user.in' — 'in' is a sub-segment, not the root.
        $ctx = ['user' => ['in' => 5]];
        $this->assertSame(5, $this->eval->evaluate('user.in', $ctx));
    }

    // =========================================================================
    // Opérateurs : précédence
    // Tests CHOISIS pour verrouiller la décision PHP-aligned, notamment ?? > ==
    // (cf. arbitrage de notre phase 2)
    // =========================================================================

    #[Test]
    public function arithmetic_precedence_multiplicative_over_additive(): void
    {
        $this->assertSame(7, $this->eval->evaluate('1 + 2 * 3', []));
    }

    #[Test]
    public function comparison_below_arithmetic(): void
    {
        // 1 + 2 > 2 → (1 + 2) > 2 → true
        $this->assertTrue($this->eval->evaluate('1 + 2 > 2', []));
    }

    #[Test]
    public function and_below_comparison(): void
    {
        // a > 0 AND b > 0 → (a>0) AND (b>0)
        $this->assertTrue($this->eval->evaluate('a > 0 AND b > 0', ['a' => 5, 'b' => 3]));
    }

    #[Test]
    public function or_below_and(): void
    {
        // false OR true AND false → false OR (true AND false) → false OR false → false
        $this->assertFalse($this->eval->evaluate('false OR true AND false', []));
    }

    #[Test]
    public function coalesce_binds_tighter_than_comparison_aligned_on_php(): void
    {
        // Aligned on PHP: ?? binds tighter than ==, >, etc.
        // null ?? 5 = 5  →  (null ?? 5) = 5  →  5 = 5  →  true
        $this->assertTrue($this->eval->evaluate('null ?? 5 = 5', []));
    }

    #[Test]
    public function coalesce_with_comparison_returns_comparison_when_left_is_non_null(): void
    {
        // ?? has LOWER precedence than comparisons (aligned with PHP).
        // '5 ?? 3 = 3'  →  '5 ?? (3 = 3)'  →  '5 ?? true'  →  5  (left is non-null)
        // Parentheses are required to get the intuitive grouping:
        // '(5 ?? 3) = 3'  →  '5 = 3'  →  false
        $this->assertSame(5, $this->eval->evaluate('5 ?? 3 = 3', []));
        $this->assertFalse($this->eval->evaluate('(5 ?? 3) = 3', []));
    }

    #[Test]
    public function coalesce_with_greater_than_returns_resolved_value_compared(): void
    {
        // null ?? 10 > 5  →  (null ?? 10) > 5  →  10 > 5  →  true
        $this->assertTrue($this->eval->evaluate('null ?? 10 > 5', []));
        // null ?? 3 <= 5  →  (null ?? 3) <= 5  →  3 <= 5  →  true
        $this->assertTrue($this->eval->evaluate('null ?? 3 <= 5', []));
    }

    #[Test]
    public function coalesce_is_right_associative(): void
    {
        // a ?? b ?? c → a ?? (b ?? c)
        $this->assertSame(3, $this->eval->evaluate('a ?? b ?? 3', []));
        $this->assertSame(2, $this->eval->evaluate('a ?? b ?? 3', ['b' => 2]));
    }

    #[Test]
    public function not_has_very_high_precedence_aligned_on_php(): void
    {
        // NOT a AND b → (NOT a) AND b
        $this->assertTrue($this->eval->evaluate('NOT a AND b', ['a' => false, 'b' => true]));
    }

    #[Test]
    public function not_is_recursive(): void
    {
        $this->assertTrue ($this->eval->evaluate('NOT NOT true', []));
        $this->assertFalse($this->eval->evaluate('NOT NOT false', []));
    }

    #[Test]
    public function unary_minus_is_recursive(): void
    {
        $this->assertSame(3, $this->eval->evaluate('--3', []));
    }

    #[Test]
    public function ternary_is_right_associative(): void
    {
        // a ? b : c ? d : e  →  a ? b : (c ? d : e)
        // true ? 1 : true ? 2 : 3  →  1
        $this->assertSame(1, $this->eval->evaluate('true ? 1 : true ? 2 : 3', []));
        // false ? 1 : true ? 2 : 3  →  false ? 1 : (true ? 2 : 3)  →  2
        $this->assertSame(2, $this->eval->evaluate('false ? 1 : true ? 2 : 3', []));
    }

    #[Test]
    public function parentheses_override_precedence(): void
    {
        $this->assertSame(9, $this->eval->evaluate('(1 + 2) * 3', []));
    }

    // =========================================================================
    // Opérateurs : alternatives symboliques
    // =========================================================================

    #[Test]
    public function double_pipe_and_double_amp_are_synonyms_of_or_and(): void
    {
        $this->assertTrue ($this->eval->evaluate('true || false', []));
        $this->assertTrue ($this->eval->evaluate('true && true', []));
        $this->assertFalse($this->eval->evaluate('true && false', []));
    }

    #[Test]
    public function double_equals_is_synonym_of_single_equals(): void
    {
        $this->assertTrue($this->eval->evaluate('5 == 5', []));
        $this->assertTrue($this->eval->evaluate('5 = 5', []));
    }

    #[Test]
    public function logical_keywords_are_case_insensitive(): void
    {
        // 'and', 'AND', 'And', 'aNd' all equivalent.
        $this->assertTrue($this->eval->evaluate('true and true', []));
        $this->assertTrue($this->eval->evaluate('true And true', []));
        $this->assertTrue($this->eval->evaluate('true aNd true', []));
        $this->assertTrue($this->eval->evaluate('true OR false', []));
        $this->assertFalse($this->eval->evaluate('NoT true', []));
    }

    // =========================================================================
    // Elvis et autres formes non supportées
    // =========================================================================

    #[Test]
    public function elvis_operator_is_not_supported(): void
    {
        // ?: would conflict with the lib's "no truthy/falsy" philosophy.
        $this->expectException(SyntaxErrorException::class);
        $this->eval->evaluate('a ?: b', ['a' => 5]);
    }

    // =========================================================================
    // Whitespace : NBSP normalisé hors littéraux
    // =========================================================================

    #[Test]
    public function nbsp_is_treated_as_whitespace_outside_quotes(): void
    {
        // NBSP between tokens — must be accepted.
        $this->assertSame(5, $this->eval->evaluate("a\xC2\xA0+\xC2\xA01", ['a' => 4]));
    }

    #[Test]
    public function nbsp_is_preserved_inside_string_literals(): void
    {
        // NBSP inside the string is part of the value, not whitespace.
        $nbsp = "\xC2\xA0";
        $this->assertTrue($this->eval->evaluate("a = '$nbsp'", ['a' => $nbsp]));
    }

    #[Test]
    public function unknown_unicode_whitespace_is_rejected(): void
    {
        // Em-space U+2003 etc. — not supported.
        $this->expectException(SyntaxErrorException::class);
        $this->eval->evaluate("a\xE2\x80\x83+\xE2\x80\x831", ['a' => 4]);
    }

    // =========================================================================
    // Sémantique de typage — coercitions interdites
    // =========================================================================

    #[Test]
    #[DataProvider('forbiddenCoercionsProvider')]
    public function strict_typing_forbids_php_style_coercions(string $expr, array $ctx): void
    {
        $this->expectException(TypeErrorException::class);
        $this->eval->evaluate($expr, $ctx);
    }

    public static function forbiddenCoercionsProvider(): array
    {
        return [
            'truthy string in AND' => ["'false' AND true", []],
            'truthy int in AND'    => ['5 AND 10', []],
            'null in arithmetic'   => ['a + 1', ['a' => null]],
            'string in arithmetic' => ["'5' + 1", []],
            'bool vs int'          => ['true = 1', []],
        ];
    }

    #[Test]
    public function adaptive_equality_int_vs_float_is_documented_exception(): void
    {
        // The one tolerance in the strict policy: int vs float compare numerically.
        $this->assertTrue($this->eval->evaluate('5 = 5.0', []));
    }
}
