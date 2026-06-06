<?php declare(strict_types=1);
namespace Ols\PhpRuler\Parser;

use Ols\PhpRuler\Exception\SyntaxErrorException;
use Ols\PhpRuler\Lexer\Token;
use Ols\PhpRuler\Lexer\TokenType;
use Ols\PhpRuler\Parser\{Node, BinaryNode, UnaryNode, LiteralNode, VariableNode, InNode, FunctionNode, TernaryNode};

final class Parser
{
    private array $tokens = [];
    private int   $pos    = 0;

    public function parse(array $tokens): Node
    {
        $this->tokens = $tokens;
        $this->pos    = 0;

        $node = $this->parseTernary();

        if (!$this->isEof()) {
            $current = $this->current();
            throw new SyntaxErrorException(
                "Unexpected token \"{$current->value}\" at position {$current->position}",
                $current->position
            );
        }

        return $node;
    }

    private function parseOr(): Node
    {
        $left = $this->parseAnd();
        while ($this->currentIs(TokenType::T_OR)) {
            $this->consume();
            $left = new BinaryNode('OR', $left, $this->parseAnd());
        }
        return $left;
    }

    private function parseTernary(): Node
    {
        $condition = $this->parseOr();
        if (!$this->currentIs(TokenType::T_QUESTION)) {
            return $condition;
        }
        $this->consume();
        $then = $this->parseTernary(); // right-associative: a ? b ? c : d : e → a ? (b ? c : d) : e
        $this->expect(TokenType::T_COLON, ':');
        $else = $this->parseTernary();
        return new TernaryNode($condition, $then, $else);
    }

    private function parseAnd(): Node
    {
        $left = $this->parseCoalesce();
        while ($this->currentIs(TokenType::T_AND)) {
            $this->consume();
            $left = new BinaryNode('AND', $left, $this->parseCoalesce());
        }
        return $left;
    }

    /**
     * Coalesce (??) — precedence intentionally LOWER than comparison.
     *
     * Placement in this library's chain (low → high):
     *   ternary  <  OR  <  AND  <  ??  <  comparison  <  +/-  <  ...
     *
     * Alignment with PHP is PARTIAL and the difference is deliberate:
     *
     *   - vs comparison: ALIGNED. In both PHP and here, comparison binds
     *     tighter than `??`, so `a ?? b == c` parses as `a ?? (b == c)`.
     *   - vs NOT:        ALIGNED. `NOT` is far higher (see parseNot), so
     *     `NOT a ?? b` parses as `(NOT a) ?? b` in both.
     *   - vs AND / OR:   DIVERGES. PHP places `??` BELOW `&&` and `||`
     *     (so PHP reads `a ?? b && c` as `a ?? (b && c)`). This library
     *     places `??` ABOVE AND/OR, so `a ?? b AND c` reads as
     *     `(a ?? b) AND c`. Concretely:
     *         PHP:      true ?? false AND false  →  true   (true ?? (false && false))
     *         php-ruler: true ?? false AND false  →  false  ((true ?? false) AND false)
     *
     * Why diverge on AND/OR? The full PHP precedence table actually has TWO
     * tiers of logical operators (`&&`/`||` high, `and`/`or` at the very
     * bottom below assignment and ternary). This library merges the word and
     * symbol forms into a single AND/OR tier for a SQL-like surface, so it
     * cannot reproduce PHP's exact `??` position relative to both tiers at
     * once. We keep `??` tight (above AND/OR) so that `flag ?? default` reads
     * as a self-contained unit inside a larger boolean rule. Callers who want
     * PHP's grouping must parenthesise: `a ?? (b AND c)`.
     *
     * Examples (identical to native PHP — the comparison/NOT cases):
     *   null ?? 5 == 5   →   null ?? (5 == 5)   →   null ?? true   →   true
     *   null ?? 5 == 6   →   null ?? (5 == 6)   →   null ?? false  →   false
     *   1    ?? 2 >  5   →   1    ?? (2 >  5)   →   1    ?? false  →   1
     *   5    ?? 3 == 3   →   5    ?? (3 == 3)   →   5    ?? true   →   5
     *
     * Note also that `NOT` is placed MUCH higher than `??` in the precedence
     * chain (see parseNot), matching PHP's `!` operator. Therefore
     * `NOT a ?? b` parses as `(NOT a) ?? b`, never `NOT (a ?? b)`.
     *
     * Two distinct audit recommendations have been REJECTED on this operator:
     *
     *   1. Promoting `??` ABOVE the comparisons so that `a ?? b == c` would
     *      group as `(a ?? b) == c`. Rejected — that grouping reads more
     *      naturally but contradicts PHP, breaking predictability for any
     *      PHP developer reading an expression. Users wanting that grouping
     *      must parenthesise: `(a ?? b) == c`.
     *
     *   2. Placing `NOT` BELOW `??` (so `NOT a ?? b` would group as
     *      `NOT (a ?? b)`). Rejected for the same reason: PHP's `!` has
     *      precedence ~12, `??` has precedence ~4. They are not close.
     *
     * Both decisions are deliberate and FINAL. The AND/OR divergence above is
     * likewise deliberate — revisit it only together with the broader
     * "single AND/OR tier" surface-syntax policy of this library.
     */
    private function parseCoalesce(): Node
    {
        $left = $this->parseComparison();
        if ($this->currentIs(TokenType::T_COALESCE)) {
            $this->consume();
            // Right-associative: a ?? b ?? c  →  a ?? (b ?? c)
            return new BinaryNode('??', $left, $this->parseCoalesce());
        }
        return $left;
    }

    private function parseComparison(): Node
    {
        $left = $this->parseAddSub();

        $comparisonTypes = [
            TokenType::T_EQUAL, TokenType::T_NOT_EQUAL,
            TokenType::T_GT,    TokenType::T_GTE,
            TokenType::T_LT,    TokenType::T_LTE,
        ];

        if ($this->currentIsOneOf($comparisonTypes)) {
            $operator = $this->current()->value;
            $this->consume();
            return new BinaryNode($operator, $left, $this->parseAddSub());
        }

        if ($this->currentIs(TokenType::T_IN)) {
            $this->consume();
            $listNode = $this->currentIs(TokenType::T_LBRACKET)
                ? new LiteralNode($this->parseInList(false))
                : $this->parseInVariable('IN');
            return new InNode($left, $listNode);
        }

        if ($this->currentIs(TokenType::T_NOT) && $this->peek()->type === TokenType::T_IN) {
            $this->consume(); // NOT
            $this->consume(); // IN
            $listNode = $this->currentIs(TokenType::T_LBRACKET)
                ? new LiteralNode($this->parseInList(false))
                : $this->parseInVariable('NOT IN');
            return new UnaryNode('NOT', new InNode($left, $listNode));
        }

        return $left;
    }

    private function parseAddSub(): Node
    {
        $left = $this->parseMulDiv();
        while ($this->currentIsOneOf([TokenType::T_PLUS, TokenType::T_MINUS])) {
            $operator = $this->current()->value;
            $this->consume();
            $left = new BinaryNode($operator, $left, $this->parseMulDiv());
        }
        return $left;
    }

    private function parseMulDiv(): Node
    {
        $left = $this->parseNot();
        while ($this->currentIsOneOf([TokenType::T_MULTIPLY, TokenType::T_DIVIDE, TokenType::T_MODULO])) {
            $operator = $this->current()->value;
            $this->consume();
            $left = new BinaryNode($operator, $left, $this->parseNot());
        }
        return $left;
    }

    /**
     * Logical NOT — high precedence, just BELOW unary minus/plus and ABOVE
     * the multiplicative operators.
     *
     * Aligned with PHP's native `!` operator, which has precedence ~12 in
     * PHP's table (just below `**` and unary `-`/`+`), well above
     * comparisons, AND, OR, and `??`. As a consequence:
     *
     *   NOT a ?? b        →   (NOT a) ?? b              (not NOT (a ?? b))
     *   NOT a == b        →   (NOT a) == b              (not NOT (a == b))
     *   NOT a AND b       →   (NOT a) AND b
     *   NOT a + b         →   (NOT a) + b               (mirrors PHP: !$a + $b)
     *   NOT NOT a         →   NOT (NOT a)               (recursive)
     *   -NOT a            →   syntax error (NOT not allowed as operand of unary -)
     *
     * The recursive self-call lets `NOT NOT a` work the same way `--x` does
     * in parseUnary.
     *
     * Audit history: an earlier audit (B1) suggested placing NOT BETWEEN
     * `??` and `parseComparison`, which would have made `NOT a ?? b` parse
     * as `NOT (a ?? b)`. That suggestion was REJECTED — it under-specified
     * the fix and would have broken alignment with PHP's `!`. The position
     * below was chosen instead, which restores PHP-native semantics for
     * BOTH `NOT vs ??` AND `?? vs comparisons` simultaneously.
     *
     * Decision is deliberate and FINAL. See parseCoalesce for the matching
     * note on the `??` side.
     */
    private function parseNot(): Node
    {
        if ($this->currentIs(TokenType::T_NOT)) {
            $this->consume();
            return new UnaryNode('NOT', $this->parseNot());
        }
        return $this->parseUnary();
    }

    /**
     * Unary minus/plus — higher precedence than * / %.
     * Recursive call allows --x → -(-x) (mathematically correct).
     * +x is a no-op, kept for symmetry.
     *
     * Special case for PHP_INT_MIN: the lexer cannot reject an unsigned
     * magnitude equal to PHP_INT_MAX + 1 outright, because that magnitude
     * is exactly |PHP_INT_MIN| and may legitimately follow a unary `-`.
     * The lexer therefore emits T_INTEGER_OVERFLOW for that single value,
     * deferring the decision to here. After consuming a `-`:
     *   - T_INTEGER_OVERFLOW → fold into LiteralNode(PHP_INT_MIN), valid.
     *   - T_INTEGER          → normal fold into LiteralNode(-int), valid.
     * Without a leading `-`, T_INTEGER_OVERFLOW is rejected in parsePrimary().
     *
     * Floats are not folded: PHP_FLOAT_MAX is symmetric around zero so
     * UnaryNode('-', LiteralNode(float)) cannot overflow.
     */
    private function parseUnary(): Node
    {
        if ($this->currentIs(TokenType::T_MINUS)) {
            $this->consume();

            if ($this->currentIs(TokenType::T_INTEGER_OVERFLOW)) {
                // Only legal use of T_INTEGER_OVERFLOW: `-<PHP_INT_MAX+1>` ≡ PHP_INT_MIN.
                $this->consume();
                return new LiteralNode(PHP_INT_MIN);
            }

            return new UnaryNode('-', $this->parseUnary());
        }

        if ($this->currentIs(TokenType::T_PLUS)) {
            $this->consume();
            return $this->parseUnary(); // +x ≡ x
        }

        return $this->parsePrimary();
    }

    private function parsePrimary(): Node
    {
        $token = $this->current();

        if ($this->currentIsOneOf([TokenType::T_INTEGER, TokenType::T_FLOAT, TokenType::T_STRING, TokenType::T_BOOLEAN])) {
            $this->consume();
            return new LiteralNode($token->value);
        }

        if ($this->currentIs(TokenType::T_INTEGER_OVERFLOW)) {
            // T_INTEGER_OVERFLOW only carries the magnitude |PHP_INT_MIN|
            // (= PHP_INT_MAX + 1). It is valid exclusively as the operand of
            // a unary `-` (handled in parseUnary). Reaching parsePrimary
            // means there was no leading `-`, so the literal is genuinely
            // out of range.
            throw new SyntaxErrorException(
                "Integer literal \"{$token->value}\" exceeds PHP_INT_MAX at position {$token->position}",
                $token->position
            );
        }

        if ($this->currentIs(TokenType::T_NULL)) {
            $this->consume();
            return new LiteralNode(null);
        }

        if ($this->currentIs(TokenType::T_IDENTIFIER)) {
            $this->consume();
            if ($this->currentIs(TokenType::T_LPAREN)) {
                $this->consume();
                $args = $this->parseFunctionArgs();
                $this->expect(TokenType::T_RPAREN, ')');
                return new FunctionNode($token->value, $args);
            }
            return new VariableNode($token->value);
        }

        if ($this->currentIs(TokenType::T_LPAREN)) {
            $this->consume();
            $node = $this->parseTernary();
            $this->expect(TokenType::T_RPAREN, ')');
            return $node;
        }

        if ($this->currentIs(TokenType::T_LBRACKET)) {
            return new LiteralNode($this->parseInList());
        }

        throw new SyntaxErrorException(
            "Unexpected token \"{$token->value}\" at position {$token->position}",
            $token->position
        );
    }

    /**
     * Parses the right-hand side of IN / NOT IN when it is not a bracket list.
     * Only a variable or function call is valid here — a scalar literal like
     * `x IN 5` is always a mistake and is rejected immediately at parse time.
     *
     * @throws SyntaxErrorException
     */
    private function parseInVariable(string $operator): Node
    {
        $pos  = $this->current()->position;
        $node = $this->parsePrimary();

        if ($node instanceof LiteralNode) {
            $type = get_debug_type($node->value);
            throw new SyntaxErrorException(
                "The right operand of $operator must be a variable or a list [...], scalar $type given at position $pos",
                $pos
            );
        }

        return $node;
    }

    /**
     * Parses a bracketed literal list `[v1, v2, ...]`.
     *
     * Used in three places:
     *  - right-hand side of IN / NOT IN  (empty list forbidden, $allowEmpty = false)
     *  - standalone list literal in parsePrimary (empty allowed)
     *  - list literal passed as function argument, e.g. `sum([])` (empty allowed)
     *
     * The empty-list restriction is a semantic constraint of the IN operator only,
     * so it is the caller's responsibility to opt into it.
     */
    private function parseInList(bool $allowEmpty = true): array
    {
        $this->expect(TokenType::T_LBRACKET, '[');
        $list = [];

        if ($this->currentIs(TokenType::T_RBRACKET)) {
            if (!$allowEmpty) {
                throw new SyntaxErrorException(
                    'Empty list [] is not allowed in IN expression at position ' . $this->current()->position,
                    $this->current()->position
                );
            }
            $this->consume(); // consume ']'
            return [];
        }

        while (!$this->currentIs(TokenType::T_RBRACKET)) {
            $token = $this->current();

            // Negative numbers: -3, -1.5, etc.
            if ($this->currentIs(TokenType::T_MINUS)) {
                $minusPos = $token->position;
                $this->consume();
                $numToken = $this->current();

                // Symmetric to parseUnary: `-<PHP_INT_MAX+1>` ≡ PHP_INT_MIN.
                if ($this->currentIs(TokenType::T_INTEGER_OVERFLOW)) {
                    $list[] = PHP_INT_MIN;
                    $this->consume();
                } else {
                    if (!$this->currentIsOneOf([TokenType::T_INTEGER, TokenType::T_FLOAT])) {
                        throw new SyntaxErrorException(
                            "Expected number after '-' in IN list at position {$minusPos}",
                            $minusPos
                        );
                    }
                    $list[] = -$numToken->value;
                    $this->consume();
                }
            } else {
                if ($this->currentIs(TokenType::T_INTEGER_OVERFLOW)) {
                    // Unsigned literal in list context — genuinely out of range.
                    throw new SyntaxErrorException(
                        "Integer literal \"{$token->value}\" exceeds PHP_INT_MAX at position {$token->position}",
                        $token->position
                    );
                }
                // Sub-list: allows [1, 2] as an element of [[1, 2], 3]
                // Used with the array-IN semantics (whole-subject match pre-pass in Evaluator::applyIn).
                if ($this->currentIs(TokenType::T_LBRACKET)) {
                    $list[] = $this->parseInList(true);
                } elseif ($this->currentIsOneOf([TokenType::T_STRING, TokenType::T_INTEGER, TokenType::T_FLOAT, TokenType::T_BOOLEAN, TokenType::T_NULL])) {
                    $list[] = $token->value; // null token has value = null, which is correct
                    $this->consume();
                } else {
                    throw new SyntaxErrorException("Expected value in IN list at position {$token->position}", $token->position);
                }
            }
            if ($this->currentIs(TokenType::T_COMMA)) {
                $this->consume();
                if ($this->currentIs(TokenType::T_RBRACKET)) {
                    throw new SyntaxErrorException(
                        "Trailing comma not allowed in list at position {$this->current()->position}",
                        $this->current()->position
                    );
                }
            }
        }
        $this->expect(TokenType::T_RBRACKET, ']');
        return $list;
    }

    private function parseFunctionArgs(): array
    {
        $args = [];
        if ($this->currentIs(TokenType::T_RPAREN)) {
            return $args;
        }
        $args[] = $this->parseTernary();
        while ($this->currentIs(TokenType::T_COMMA)) {
            $this->consume();
            $args[] = $this->parseTernary();
        }
        return $args;
    }

    private function consume(): Token  { return $this->tokens[$this->pos++]; }
    private function current(): Token  { return $this->tokens[$this->pos]; }
    private function peek(): Token     { return $this->tokens[$this->pos + 1] ?? new Token(TokenType::T_EOF, null, -1); }
    private function isEof(): bool     { return $this->tokens[$this->pos]->type === TokenType::T_EOF; }

    private function currentIs(TokenType $type): bool
    {
        return $this->tokens[$this->pos]->type === $type;
    }

    private function currentIsOneOf(array $types): bool
    {
        return in_array($this->tokens[$this->pos]->type, $types, true);
    }

    private function expect(TokenType $type, string $label): Token
    {
        if (!$this->currentIs($type)) {
            $current = $this->current();
            throw new SyntaxErrorException(
                "\"$label\" expected at position {$current->position}, \"{$current->value}\" found",
                $current->position
            );
        }
        return $this->consume();
    }
}
