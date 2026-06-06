<?php declare(strict_types=1);

namespace Ols\PhpRuler\Tests;

use Ols\PhpRuler\AliasResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Couvre alias-resolver.md.
 *
 * AliasResolver is a standalone component: pure textual substitution between
 * a "human" representation and a "technical" dot-notation representation.
 * It does not parse, evaluate, or depend on ExpressionEvaluator.
 */
final class AliasResolverTest extends TestCase
{
    // =========================================================================
    // add() — validation
    // =========================================================================

    #[Test]
    public function add_returns_self_for_chaining(): void
    {
        $resolver = new AliasResolver();
        $this->assertSame($resolver, $resolver->add('cart.total', 'cart amount'));
    }

    #[Test]
    #[DataProvider('forbiddenCharactersProvider')]
    public function add_rejects_aliases_with_forbidden_characters(string $alias): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('letters, digits, underscores');

        (new AliasResolver())->add('some.path', $alias);
    }

    public static function forbiddenCharactersProvider(): array
    {
        return [
            // Operators
            'plus'         => ['foo+bar'],
            'minus / dash' => ['foo-bar'],
            'star'         => ['foo*bar'],
            'slash'        => ['foo/bar'],
            'logical and'  => ['foo&&bar'],
            'logical or'   => ['foo||bar'],
            'comparison'   => ['foo>bar'],
            'equality'     => ['foo==bar'],
            'percent'      => ['foo%bar'],
            // Punctuation
            'open paren'   => ['foo(bar)'],
            'close paren'  => ['foo)'],
            'comma'        => ['foo,bar'],
            'colon'        => ['foo:bar'],
            'question'     => ['foo?bar'],
            'ternary form' => ['foo?bar:baz'],
            'semicolon'    => ['foo;bar'],
            'brackets'     => ['foo[0]'],
            'braces'       => ['foo{bar}'],
            'pipe'         => ['foo|bar'],
            'at sign'      => ['@foo'],
            'hash'         => ['#foo'],
            'exclamation'  => ['!foo'],
            // Regex metacharacters
            'caret'        => ['^start'],
            'dollar'       => ['end$'],
            'wildcard dot' => ['.*'],
            'char class'   => ['[abc]'],
            'backslash'    => ['foo\\bar'],
            // Dot — explicitly forbidden, would clash with dot-notation
            'dot middle'   => ['cart.total'],
            'leading dot'  => ['.foo'],
            'trailing dot' => ['foo.'],
        ];
    }

    #[Test]
    #[DataProvider('allowedAliasesProvider')]
    public function add_accepts_aliases_with_allowed_characters(string $alias): void
    {
        $resolver = new AliasResolver();
        $resolver->add('some.path', $alias);
        $this->assertSame([$alias], array_values($resolver->all()));
    }

    public static function allowedAliasesProvider(): array
    {
        return [
            'plain ascii'      => ['foo'],
            'with digits'      => ['cart3'],
            'leading digit'    => ['3cart'],
            'underscore'       => ['cart_total'],
            'multi-word'       => ['cart total'],
            'three words'      => ['customer group name'],
            'tab as internal'  => ["cart\ttotal"],
            'french accents'   => ['utilisateur connecté'],
            'unicode latin'    => ['café'],
            'cyrillic'         => ['пользователь'],
            'cjk'              => ['顧客グループ'],
        ];
    }

    #[Test]
    public function add_rejects_alias_with_single_quote(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('quotes');

        (new AliasResolver())->add('cart.total', "cart's total");
    }

    #[Test]
    public function add_rejects_alias_with_double_quote(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('quotes');

        (new AliasResolver())->add('cart.total', 'cart "total"');
    }

    #[Test]
    public function add_rejects_empty_alias(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('empty');

        (new AliasResolver())->add('cart.total', '');
    }

    #[Test]
    public function add_rejects_whitespace_only_alias(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('empty');

        (new AliasResolver())->add('cart.total', "   \t   ");
    }

    #[Test]
    public function add_rejects_alias_with_leading_whitespace_and_suggests_trim(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"cart amount"'); // suggestion trimmed

        (new AliasResolver())->add('cart.total', '  cart amount');
    }

    #[Test]
    public function add_rejects_alias_with_trailing_whitespace_and_suggests_trim(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"cart amount"');

        (new AliasResolver())->add('cart.total', 'cart amount  ');
    }

    #[Test]
    #[DataProvider('reservedKeywordsProvider')]
    public function add_rejects_reserved_keywords_case_insensitively(string $alias): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reserved language keyword');

        (new AliasResolver())->add('some.path', $alias);
    }

    public static function reservedKeywordsProvider(): array
    {
        // The reserved set: and, or, not, in, true, false, null
        // Each tested in multiple casings to assert case-insensitive matching.
        return [
            'and lower' => ['and'],
            'AND upper' => ['AND'],
            'And mixed' => ['And'],
            'aNd weird' => ['aNd'],
            'or'        => ['or'],
            'OR'        => ['OR'],
            'not'       => ['not'],
            'NOT'       => ['NOT'],
            'in'        => ['in'],
            'IN'        => ['IN'],
            'true'      => ['true'],
            'TRUE'      => ['TRUE'],
            'false'     => ['false'],
            'null'      => ['null'],
            'NULL'      => ['NULL'],
        ];
    }

    // =========================================================================
    // add() — asymmetric uniqueness rules
    // =========================================================================

    #[Test]
    public function reusing_an_alias_for_a_different_path_throws(): void
    {
        $resolver = new AliasResolver();
        $resolver->add('cart.total', 'cart amount');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already used by path "cart.total"');

        $resolver->add('order.total', 'cart amount');
    }

    #[Test]
    public function re_registering_a_path_with_a_new_alias_drops_the_previous_one(): void
    {
        // Last-write-wins, intentional per the doc.
        $resolver = new AliasResolver();
        $resolver->add('cart.total', 'cart amount');
        $resolver->add('cart.total', 'cart total');

        $this->assertSame(['cart.total' => 'cart total'], $resolver->all());

        // The dropped alias should now be reusable for another path.
        $resolver->add('order.total', 'cart amount');
        $this->assertSame(
            ['cart.total' => 'cart total', 'order.total' => 'cart amount'],
            $resolver->all()
        );
    }

    #[Test]
    public function re_registering_the_same_path_with_the_same_alias_is_a_noop(): void
    {
        $resolver = new AliasResolver();
        $resolver->add('cart.total', 'cart amount');
        $resolver->add('cart.total', 'cart amount');

        $this->assertSame(['cart.total' => 'cart amount'], $resolver->all());
    }

    // =========================================================================
    // remove() / clear() / all()
    // =========================================================================

    #[Test]
    public function remove_drops_the_alias_for_the_given_path(): void
    {
        $resolver = new AliasResolver();
        $resolver->add('cart.total', 'cart amount');
        $resolver->add('customer.group', 'customer group');

        $resolver->remove('cart.total');
        $this->assertSame(['customer.group' => 'customer group'], $resolver->all());
    }

    #[Test]
    public function remove_is_silent_when_the_path_has_no_alias(): void
    {
        // Doc: "Silencieux si le chemin n'a pas d'alias enregistré."
        $resolver = new AliasResolver();
        $resolver->remove('nonexistent.path');
        $this->assertSame([], $resolver->all());
    }

    #[Test]
    public function remove_returns_self_for_chaining(): void
    {
        $resolver = new AliasResolver();
        $this->assertSame($resolver, $resolver->remove('whatever'));
    }

    #[Test]
    public function clear_empties_all_aliases(): void
    {
        $resolver = new AliasResolver();
        $resolver->add('a.b', 'one')->add('c.d', 'two');
        $resolver->clear();
        $this->assertSame([], $resolver->all());
    }

    #[Test]
    public function clear_returns_self_for_chaining(): void
    {
        $resolver = new AliasResolver();
        $this->assertSame($resolver, $resolver->clear());
    }

    #[Test]
    public function all_returns_path_to_alias_mapping(): void
    {
        $resolver = new AliasResolver();
        $resolver->add('customer.group', 'customer group')
                 ->add('cart.total',     'cart amount');

        $this->assertSame(
            ['customer.group' => 'customer group', 'cart.total' => 'cart amount'],
            $resolver->all()
        );
    }

    // =========================================================================
    // humanToExpression() — basic translation
    // =========================================================================

    #[Test]
    public function human_to_expression_replaces_a_single_alias(): void
    {
        $resolver = new AliasResolver();
        $resolver->add('cart.total', 'cart amount');

        $this->assertSame(
            'cart.total > 100',
            $resolver->humanToExpression('cart amount > 100')
        );
    }

    #[Test]
    public function human_to_expression_replaces_multiple_aliases(): void
    {
        $resolver = new AliasResolver();
        $resolver->add('customer.group', 'customer group')
                 ->add('cart.total',     'cart amount');

        $this->assertSame(
            "customer.group = 'vip' AND cart.total > 100",
            $resolver->humanToExpression("customer group = 'vip' AND cart amount > 100")
        );
    }

    #[Test]
    public function human_to_expression_returns_input_unchanged_when_no_aliases_registered(): void
    {
        $resolver = new AliasResolver();
        $this->assertSame('cart.total > 100', $resolver->humanToExpression('cart.total > 100'));
    }

    #[Test]
    public function human_to_expression_returns_input_unchanged_when_no_alias_matches(): void
    {
        $resolver = new AliasResolver();
        $resolver->add('cart.total', 'cart amount');
        $this->assertSame('customer.group > 0', $resolver->humanToExpression('customer.group > 0'));
    }

    // =========================================================================
    // expressionToHuman() — inverse translation
    // =========================================================================

    #[Test]
    public function expression_to_human_replaces_paths_with_aliases(): void
    {
        $resolver = new AliasResolver();
        $resolver->add('customer.group', 'customer group')
                 ->add('cart.total',     'cart amount');

        $this->assertSame(
            "customer group = 'vip' AND cart amount > 100",
            $resolver->expressionToHuman("customer.group = 'vip' AND cart.total > 100")
        );
    }

    #[Test]
    public function round_trip_human_to_expression_to_human_is_identity_when_all_aliases_known(): void
    {
        $resolver = new AliasResolver();
        $resolver->add('customer.group', 'customer group')
                 ->add('cart.total',     'cart amount');

        $human = "customer group = 'vip' AND cart amount > 100";
        $this->assertSame(
            $human,
            $resolver->expressionToHuman($resolver->humanToExpression($human))
        );
    }

    // =========================================================================
    // Substitution guarantees: literal preservation
    // =========================================================================

    #[Test]
    public function does_not_replace_aliases_inside_single_quoted_literals(): void
    {
        $resolver = new AliasResolver();
        $resolver->add('cart.total', 'cart amount');

        $this->assertSame(
            "customer.group = 'cart amount'",
            $resolver->humanToExpression("customer.group = 'cart amount'")
        );
    }

    #[Test]
    public function does_not_replace_aliases_inside_double_quoted_literals(): void
    {
        $resolver = new AliasResolver();
        $resolver->add('cart.total', 'cart amount');

        $this->assertSame(
            'customer.group = "cart amount"',
            $resolver->humanToExpression('customer.group = "cart amount"')
        );
    }

    #[Test]
    public function does_not_replace_paths_inside_quoted_literals(): void
    {
        $resolver = new AliasResolver();
        $resolver->add('cart.total', 'cart amount');

        $this->assertSame(
            "customer.group = 'cart.total'",
            $resolver->expressionToHuman("customer.group = 'cart.total'")
        );
    }

    #[Test]
    public function recognizes_doubled_quote_escapes_inside_literals(): void
    {
        // L'Oréal is written 'L''Oréal' in the expression grammar.
        // The literal must be preserved as a whole — no alias substitution inside.
        $resolver = new AliasResolver();
        $resolver->add('brand', 'oréal');

        $this->assertSame(
            "brand = 'L''Oréal'",
            $resolver->humanToExpression("brand = 'L''Oréal'")
        );
    }

    // =========================================================================
    // Substitution guarantees: word boundaries
    // =========================================================================

    #[Test]
    public function expression_to_human_does_not_match_a_path_inside_a_longer_path(): void
    {
        // 'total' must not be substituted inside 'subtotal'.
        $resolver = new AliasResolver();
        $resolver->add('cart.total', 'total');

        $this->assertSame(
            'total = subtotal',
            $resolver->expressionToHuman('cart.total = subtotal')
        );
    }

    #[Test]
    public function human_to_expression_does_not_treat_alias_as_a_function_name(): void
    {
        // An alias represents a variable, not a callable.
        // Registering 'total' for path 'sum' must NOT rewrite 'total(x)' as 'sum(x)'.
        $resolver = new AliasResolver();
        $resolver->add('sum', 'total');

        $this->assertSame(
            'total(items) > 100',
            $resolver->humanToExpression('total(items) > 100')
        );
    }

    #[Test]
    public function alias_followed_by_space_then_paren_is_still_translated(): void
    {
        // The exclusion of '(' applies only when it directly follows the alias.
        // 'total (1 + 2)' contains an alias followed by a parenthesized expression.
        $resolver = new AliasResolver();
        $resolver->add('cart.total', 'total');

        $this->assertSame(
            'cart.total (1 + 2)',
            $resolver->humanToExpression('total (1 + 2)')
        );
    }

    #[Test]
    public function alias_used_as_a_standalone_variable_is_still_translated(): void
    {
        $resolver = new AliasResolver();
        $resolver->add('sum', 'total');

        $this->assertSame('sum > 100', $resolver->humanToExpression('total > 100'));
    }

    // =========================================================================
    // Substitution guarantees: longest match first
    // =========================================================================

    #[Test]
    public function longest_alias_is_matched_first_when_aliases_overlap(): void
    {
        // Without longest-match-first, 'customer group' would consume the prefix
        // of 'customer group name' before the longer alias gets a chance.
        $resolver = new AliasResolver();
        $resolver->add('a.b.c', 'customer group name')
                 ->add('a.b',   'customer group');

        $this->assertSame(
            'a.b.c = "x" AND a.b = "y"',
            $resolver->humanToExpression('customer group name = "x" AND customer group = "y"')
        );
    }

    #[Test]
    public function longest_alias_is_matched_first_regardless_of_registration_order(): void
    {
        $resolver = new AliasResolver();
        // Register short first, then long: result must still be longest-first.
        $resolver->add('a.b',   'customer group')
                 ->add('a.b.c', 'customer group name');

        $this->assertSame(
            'a.b.c = "x"',
            $resolver->humanToExpression('customer group name = "x"')
        );
    }

    // =========================================================================
    // Substitution guarantees: case sensitivity
    // =========================================================================

    #[Test]
    public function alias_matching_is_case_sensitive(): void
    {
        $resolver = new AliasResolver();
        $resolver->add('cart.total', 'Cart Total');

        // Exact match — translated
        $this->assertSame('cart.total > 100', $resolver->humanToExpression('Cart Total > 100'));
        // Different casing — preserved as-is
        $this->assertSame('cart total > 100', $resolver->humanToExpression('cart total > 100'));
        $this->assertSame('CART TOTAL > 100', $resolver->humanToExpression('CART TOTAL > 100'));
    }

    // =========================================================================
    // Substitution guarantees: Unicode-aware boundaries (audit B4 / B16)
    // =========================================================================

    #[Test]
    public function unicode_word_boundary_does_not_corrupt_accented_identifiers(): void
    {
        // Regression for audit B4: an alias 'menu' was rewritten inside the word 'menü'
        // because the boundary regex treated the byte 'ü' (UTF-8: C3 BC) as a word break.
        // The current implementation includes \x{0080}-\x{FFFF} in the boundary class,
        // so 'menü' must be preserved as a whole.
        $resolver = new AliasResolver();
        $resolver->add('food.menu', 'menu');

        // 'menü' must remain intact (no partial substitution).
        $this->assertSame(
            'menü > 0',
            $resolver->humanToExpression('menü > 0')
        );
        // And the alias itself must still resolve in a clean context.
        $this->assertSame(
            'food.menu > 0',
            $resolver->humanToExpression('menu > 0')
        );
    }

    #[Test]
    public function unicode_aliases_are_substituted_with_proper_boundaries(): void
    {
        $resolver = new AliasResolver();
        $resolver->add('customer.firstName', 'prénom');

        $this->assertSame(
            "customer.firstName = 'Pierre'",
            $resolver->humanToExpression("prénom = 'Pierre'")
        );
    }

    // =========================================================================
    // Error handling: invalid UTF-8
    // =========================================================================

    #[Test]
    public function human_to_expression_rejects_invalid_utf8_input(): void
    {
        $resolver = new AliasResolver();
        $resolver->add('cart.total', 'cart amount');

        // Lone continuation byte 0x80 is not valid UTF-8.
        $invalid = "cart amount > \x80 + 1";

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid UTF-8');

        $resolver->humanToExpression($invalid);
    }

    #[Test]
    public function expression_to_human_rejects_invalid_utf8_input(): void
    {
        $resolver = new AliasResolver();
        $resolver->add('cart.total', 'cart amount');

        $invalid = "cart.total > \xC3\x28 + 1"; // overlong / malformed sequence

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid UTF-8');

        $resolver->expressionToHuman($invalid);
    }

    // =========================================================================
    // Edge cases & robustness
    // =========================================================================

    #[Test]
    public function empty_expression_returns_empty_string(): void
    {
        $resolver = new AliasResolver();
        $resolver->add('cart.total', 'cart amount');

        $this->assertSame('', $resolver->humanToExpression(''));
        $this->assertSame('', $resolver->expressionToHuman(''));
    }

    #[Test]
    public function expression_without_quoted_segments_is_processed_normally(): void
    {
        $resolver = new AliasResolver();
        $resolver->add('cart.total', 'cart amount');
        $this->assertSame('cart.total > 100', $resolver->humanToExpression('cart amount > 100'));
    }

    #[Test]
    public function pathological_quote_layout_is_not_treated_as_a_string_literal(): void
    {
        // The isQuoted() guard rejects patterns like "abc"def" which open and close
        // with quotes but have an unescaped quote in the middle. Such a segment is
        // NOT treated as a literal — substitution applies normally.
        $resolver = new AliasResolver();
        $resolver->add('cart.total', 'amount');

        // Note: feeding this to the lexer downstream would fail; but the resolver
        // does not parse. It just must not silently skip the substitution.
        $input = '"abc" amount "def"';
        // The 'amount' between the two quoted segments must be replaced.
        $this->assertStringContainsString('cart.total', $resolver->humanToExpression($input));
    }
}
