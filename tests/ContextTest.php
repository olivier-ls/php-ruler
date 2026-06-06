<?php declare(strict_types=1);

namespace Ols\PhpRuler\Tests;

use Ols\PhpRuler\Exception\CircularContextException;
use Ols\PhpRuler\Exception\UnknownVariableException;
use Ols\PhpRuler\ExpressionEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Couvre context.md.
 *
 * The context is a PHP associative array passed to each evaluation. Paths are
 * expressed in dot-notation: 'cart.total' accesses $context['cart']['total'].
 *
 * The methods here delegate to ContextResolver (stateless). Tests target the
 * public surface on ExpressionEvaluator since that's how callers interact.
 */
final class ContextTest extends TestCase
{
    private ExpressionEvaluator $eval;

    protected function setUp(): void
    {
        $this->eval = new ExpressionEvaluator();
    }

    // =========================================================================
    // getContextValue() — strict resolution
    // =========================================================================

    #[Test]
    public function get_resolves_a_top_level_scalar(): void
    {
        $this->assertSame(150.0, $this->eval->getContextValue('total', ['total' => 150.0]));
    }

    #[Test]
    public function get_resolves_a_nested_path(): void
    {
        $ctx = ['cart' => ['total' => 150.0]];
        $this->assertSame(150.0, $this->eval->getContextValue('cart.total', $ctx));
    }

    #[Test]
    public function get_resolves_a_deeply_nested_path(): void
    {
        $ctx = ['customer' => ['address' => ['country' => 'FR']]];
        $this->assertSame('FR', $this->eval->getContextValue('customer.address.country', $ctx));
    }

    #[Test]
    public function get_returns_a_subtree_when_path_points_to_an_associative_array(): void
    {
        // Documented as intentional: getContextValue('cart') returns the whole subtree.
        $ctx = ['cart' => ['total' => 150.0, 'items' => 3]];
        $this->assertSame(['total' => 150.0, 'items' => 3], $this->eval->getContextValue('cart', $ctx));
    }

    #[Test]
    public function get_returns_indexed_list_as_is(): void
    {
        $ctx = ['tags' => ['php', 'js']];
        $this->assertSame(['php', 'js'], $this->eval->getContextValue('tags', $ctx));
    }

    #[Test]
    public function get_throws_when_root_segment_is_absent(): void
    {
        try {
            $this->eval->getContextValue('customer', ['cart' => ['total' => 100]]);
            $this->fail('Expected UnknownVariableException');
        } catch (UnknownVariableException $e) {
            // The doc specifies: when the root is absent, no "(failed at ...)" suffix.
            $this->assertSame('Unknown variable: "customer"', $e->getMessage());
        }
    }

    #[Test]
    public function get_throws_when_intermediate_segment_resolves_but_leaf_is_absent(): void
    {
        try {
            $this->eval->getContextValue('cart.shipping', ['cart' => ['total' => 100]]);
            $this->fail('Expected UnknownVariableException');
        } catch (UnknownVariableException $e) {
            // The doc specifies the "(failed at ...)" suffix in this case.
            $this->assertStringContainsString('Unknown variable: "cart.shipping"', $e->getMessage());
            $this->assertStringContainsString('failed at', $e->getMessage());
        }
    }

    #[Test]
    public function get_treats_null_value_as_present_not_absent(): void
    {
        // array_key_exists semantics, not isset: null is a legitimate value.
        $ctx = ['a' => null];
        $this->assertNull($this->eval->getContextValue('a', $ctx));
    }

    // =========================================================================
    // getContextValueOrDefault() — lenient resolution
    // =========================================================================

    #[Test]
    public function get_or_default_returns_default_on_absent_path(): void
    {
        $this->assertSame(0, $this->eval->getContextValueOrDefault('missing', [], 0));
    }

    #[Test]
    public function get_or_default_returns_null_when_no_default_provided(): void
    {
        $this->assertNull($this->eval->getContextValueOrDefault('missing', []));
    }

    #[Test]
    public function get_or_default_returns_value_when_path_is_present(): void
    {
        $ctx = ['cart' => ['total' => 100]];
        $this->assertSame(100, $this->eval->getContextValueOrDefault('cart.total', $ctx, 0));
    }

    #[Test]
    public function get_or_default_returns_null_value_when_present_not_the_default(): void
    {
        // Subtle but documented: a present null is NOT replaced by the default.
        $ctx = ['a' => null];
        $this->assertNull($this->eval->getContextValueOrDefault('a', $ctx, 'fallback'));
    }

    #[Test]
    public function get_or_default_does_not_throw_on_missing_nested_path(): void
    {
        $ctx = ['cart' => ['total' => 100]];
        $this->assertSame('fallback', $this->eval->getContextValueOrDefault('cart.shipping', $ctx, 'fallback'));
    }

    // =========================================================================
    // hasContextValue() — pure existence check
    // =========================================================================

    #[Test]
    public function has_returns_true_for_existing_leaf(): void
    {
        $this->assertTrue($this->eval->hasContextValue('cart.total', ['cart' => ['total' => 100]]));
    }

    #[Test]
    public function has_returns_true_for_existing_subtree(): void
    {
        $this->assertTrue($this->eval->hasContextValue('cart', ['cart' => ['total' => 100]]));
    }

    #[Test]
    public function has_returns_false_for_missing_leaf(): void
    {
        $this->assertFalse($this->eval->hasContextValue('cart.shipping', ['cart' => ['total' => 100]]));
    }

    #[Test]
    public function has_returns_false_for_missing_root(): void
    {
        $this->assertFalse($this->eval->hasContextValue('customer', ['cart' => ['total' => 100]]));
    }

    #[Test]
    public function has_returns_true_when_value_is_null(): void
    {
        // Consistent with array_key_exists semantics: present-null is still present.
        $this->assertTrue($this->eval->hasContextValue('a', ['a' => null]));
    }

    // =========================================================================
    // describeContext() — structured view
    // =========================================================================

    #[Test]
    public function describe_flattens_associative_arrays_into_dotted_paths(): void
    {
        $result = $this->eval->describeContext([
            'cart' => ['total' => 150.0, 'currency' => 'EUR'],
        ]);

        $this->assertEqualsCanonicalizing(
            [
                ['path' => 'cart.total',    'type' => 'number', 'value' => 150.0],
                ['path' => 'cart.currency', 'type' => 'string', 'value' => 'EUR'],
            ],
            $result
        );
    }

    #[Test]
    public function describe_keeps_indexed_lists_as_terminal_values(): void
    {
        $result = $this->eval->describeContext(['tags' => ['php', 'js']]);

        $this->assertSame(
            [['path' => 'tags', 'type' => 'list', 'itemType' => 'string', 'value' => ['php', 'js']]],
            $result
        );
    }

    #[Test]
    public function describe_types_scalars_correctly(): void
    {
        $result = $this->eval->describeContext([
            'i' => 42,
            'f' => 3.14,
            's' => 'hello',
            'b' => true,
            'n' => null,
        ]);

        $byPath = array_column($result, null, 'path');
        $this->assertSame('number',  $byPath['i']['type']);
        $this->assertSame('number',  $byPath['f']['type']); // int and float both -> 'number'
        $this->assertSame('string',  $byPath['s']['type']);
        $this->assertSame('boolean', $byPath['b']['type']);
        $this->assertSame('null',    $byPath['n']['type']);
    }

    #[Test]
    public function describe_uses_item_type_when_list_is_homogeneous(): void
    {
        $result = $this->eval->describeContext(['tags' => ['php', 'js', 'sql']]);
        $this->assertSame('string', $result[0]['itemType']);
    }

    #[Test]
    public function describe_uses_mixed_when_list_has_multiple_types(): void
    {
        $result = $this->eval->describeContext(['mix' => [1, 'two', true]]);
        $this->assertSame('mixed', $result[0]['itemType']);
    }

    #[Test]
    public function describe_uses_unknown_for_empty_list(): void
    {
        $result = $this->eval->describeContext(['empty' => []]);
        $this->assertSame('unknown', $result[0]['itemType']);
    }

    #[Test]
    public function describe_handles_mixed_scalar_and_list_at_top_level(): void
    {
        $result = $this->eval->describeContext([
            'cart'     => ['total' => 150.0, 'currency' => 'EUR'],
            'customer' => ['vip' => true],
            'tags'     => ['php', 'js'],
        ]);

        $byPath = array_column($result, null, 'path');

        $this->assertSame(150.0, $byPath['cart.total']['value']);
        $this->assertSame('EUR', $byPath['cart.currency']['value']);
        $this->assertSame(true,  $byPath['customer.vip']['value']);
        $this->assertSame(['php', 'js'], $byPath['tags']['value']);
    }

    #[Test]
    public function describe_does_not_descend_into_indexed_lists(): void
    {
        // The doc states explicitly: lists are terminal, no flattening to tags.0, tags.1.
        $result = $this->eval->describeContext(['tags' => ['php', 'js']]);

        $this->assertCount(1, $result);
        $this->assertSame('tags', $result[0]['path']);
    }

    #[Test]
    public function describe_handles_empty_context(): void
    {
        $this->assertSame([], $this->eval->describeContext([]));
    }

    // =========================================================================
    // describeContext() — circular reference detection
    // =========================================================================

    #[Test]
    public function describe_throws_on_circular_reference(): void
    {
        $ctx = ['data' => 'x'];
        $ctx['self'] = &$ctx;

        $this->expectException(CircularContextException::class);
        $this->expectExceptionMessage('Context nesting exceeds 64 levels');

        $this->eval->describeContext($ctx);
    }

    #[Test]
    public function describe_accepts_deep_but_non_circular_context(): void
    {
        // Build a context just below the 64-level limit. Should NOT throw.
        $ctx = ['leaf' => 'deepest'];
        for ($i = 0; $i < 60; $i++) {
            $ctx = ['layer' => $ctx];
        }
        $this->assertNotEmpty($this->eval->describeContext($ctx));
    }

    // =========================================================================
    // Notation pointée — limitations documentées
    // =========================================================================

    #[Test]
    public function dot_is_always_a_separator_so_literal_dot_keys_are_unreachable(): void
    {
        // Documented limitation: keys with literal '.' cannot be reached via dot-notation.
        $ctx = ['foo.bar' => 'value'];

        $this->expectException(UnknownVariableException::class);
        $this->eval->getContextValue('foo.bar', $ctx);
    }

    #[Test]
    public function literal_dot_keys_become_reachable_when_wrapped_under_a_parent(): void
    {
        $ctx = ['data' => ['foo.bar' => 'value']];
        $sub = $this->eval->getContextValue('data', $ctx);
        $this->assertSame(['foo.bar' => 'value'], $sub);
    }

    // =========================================================================
    // Reserved keyword at the root is unreachable from expressions
    // =========================================================================

    #[Test]
    public function reserved_keyword_at_root_is_reachable_via_context_api_but_not_expressions(): void
    {
        // The ContextResolver does not know about the lexer — it just looks up keys.
        // So getContextValue('in', $ctx) works, but evaluate('in', $ctx) would fail in the lexer.
        $ctx = ['in' => 5];
        $this->assertSame(5, $this->eval->getContextValue('in', $ctx));
    }

    // =========================================================================
    // Context type filter — objects, closures, resources are rejected at resolve-time
    // (the validation lives in Evaluator, but it is closely related to context access)
    // =========================================================================

    #[Test]
    #[DataProvider('unsupportedContextTypesProvider')]
    public function evaluate_rejects_unsupported_value_types_with_a_path_message(mixed $value, string $messageFragment): void
    {
        try {
            $this->eval->evaluate('a', ['a' => $value]);
            $this->fail('Expected TypeErrorException');
        } catch (\Ols\PhpRuler\Exception\TypeErrorException $e) {
            $this->assertStringContainsString('Variable "a" resolved to', $e->getMessage());
            $this->assertStringContainsString($messageFragment, $e->getMessage());
        }
    }

    public static function unsupportedContextTypesProvider(): array
    {
        return [
            'stdClass object' => [new \stdClass(), 'stdClass'],
            'object with __toString' => [
                new class {
                    public function __toString(): string { return 'oh hi'; }
                },
                'class@anonymous',
            ],
            'closure' => [fn() => 5, 'Closure'],
        ];
    }

    #[Test]
    public function evaluate_rejects_resource_in_context(): void
    {
        $handle = fopen('php://memory', 'r');
        try {
            $this->eval->evaluate('h', ['h' => $handle]);
            $this->fail('Expected TypeErrorException');
        } catch (\Ols\PhpRuler\Exception\TypeErrorException $e) {
            $this->assertStringContainsString('Variable "h" resolved to', $e->getMessage());
            $this->assertStringContainsString('resource', $e->getMessage());
        } finally {
            fclose($handle);
        }
    }

    #[Test]
    public function evaluate_rejects_object_hidden_inside_an_indexed_list_with_indexed_path(): void
    {
        // Regression for audit I6: the type-filter walks lists recursively and
        // surfaces the failing index in the path.
        try {
            $this->eval->evaluate('tags', ['tags' => ['ok', new \stdClass(), 'also ok']]);
            $this->fail('Expected TypeErrorException');
        } catch (\Ols\PhpRuler\Exception\TypeErrorException $e) {
            $this->assertStringContainsString('tags[1]', $e->getMessage());
        }
    }

    #[Test]
    public function context_with_unused_invalid_value_is_not_validated_eagerly(): void
    {
        // Documented limitation: validation happens at variable RESOLUTION, not at
        // context entry. An object stored in the context but never referenced by
        // the expression goes unnoticed.
        $ctx = ['unused' => new \stdClass(), 'a' => 5];
        $this->assertSame(5, $this->eval->evaluate('a', $ctx));
    }
}
