<?php declare(strict_types=1);
namespace Ols\PhpRuler\Lexer;

use Ols\PhpRuler\Exception\SyntaxErrorException;

final class Lexer
{
    /**
     * The `logical` group matches AND/OR/NOT/IN case-insensitively (the `/i`
     * flag applies to the whole pattern). This is intentional: the language
     * accepts any casing (`AND`, `and`, `And`, `aNd`...) as a stylistic
     * choice, matching the SQL-like feel of the surface syntax.
     *
     * Side-effect (known limitation, audit ref I11): identifiers whose full
     * path component is `and`, `or`, `not` or `in` (in ANY casing) are
     * tokenized as logical keywords and become unreachable as standalone
     * variables. For example:
     *
     *     $eval->evaluate('In', ['In' => 5]);
     *     // → SyntaxErrorException, "In" is lexed as T_IN, not T_IDENTIFIER
     *
     * Reserved names (case-insensitive): and, or, not, in, true, false, null.
     *
     * Compound dotted paths are only partially affected: `user.in` works
     * because the leading segment (`user`) is a non-reserved identifier, so
     * the whole path is matched by the `identifier` sub-pattern in a single
     * shot. A standalone reserved word at the start of a path, however,
     * cannot be used.
     *
     * This limitation is accepted rather than fixed because:
     *   - changing keyword matching to case-sensitive would break the
     *     documented SQL-like flexibility;
     *   - the collision is rare in practice (variables named exactly `and`,
     *     `or`, `not`, `in` are uncommon in real contexts).
     *
     * Consumers building variable contexts from untrusted external sources
     * (column names, imported keys, etc.) should sanitize their key names
     * against this list, or namespace them under a parent object.
     */
    private const PATTERN = '/
        (?P<float>      \d+(?:\.\d+)?[eE][+\-]?\d+ |\d+\.\d+  )  |
        (?P<integer>    \d+                          )  |
        (?P<string>     \'(?:[^\']|\'\')*\'|"(?:[^"]|"")*"  )  |
        (?P<boolean>    \b(?:true|false)\b           )  |
        (?P<null>       \bnull\b                     )  |
        (?P<logical>    \|\||&&|\b(?:AND|OR|NOT|IN)\b )  |
        (?P<operator>   >=|<=|!=|==|>|<|=             )  |
        (?P<arith>      [+\-*\/%]                    )  |
        (?P<lparen>     \(                           )  |
        (?P<rparen>     \)                           )  |
        (?P<lbracket>   \[                           )  |
        (?P<rbracket>   \]                           )  |
        (?P<comma>      ,                            )  |
        (?P<coalesce>   \?\?                          )  |
        (?P<question>   \?                            )  |
        (?P<colon>      :                            )  |
        (?P<identifier> [a-zA-Z_][a-zA-Z0-9_]* (?:\.[a-zA-Z_][a-zA-Z0-9_]*)* )  |
        (?P<unknown>    \S+                          )
    /xi';

    private const GROUPS = [
        'float','integer','string','boolean','null',
        'logical','operator','arith',
        'lparen','rparen','lbracket','rbracket','comma',
        'coalesce','question','colon',
        'identifier','unknown',
    ];

    /**
     * NBSP (U+00A0, UTF-8 bytes 0xC2 0xA0) is treated as a regular space.
     * It is the most common non-ASCII whitespace found in inputs copy-pasted
     * from rich-text sources (word processors, web forms, PDFs). Other
     * Unicode whitespaces are intentionally left unhandled — they are
     * essentially never seen in practice and accepting them would require
     * a wider Unicode policy.
     */
    private const NBSP_UTF8 = "\xC2\xA0";

    /** @return Token[] */
    public function tokenize(string $expression): array
    {
        $expression = $this->normalizeNbspOutsideStrings($expression);

        preg_match_all(self::PATTERN, $expression, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $tokens = [];

        foreach ($matches as $match) {
            foreach (self::GROUPS as $group) {
                if (isset($match[$group]) && $match[$group][1] !== -1) {
                    $raw = $match[$group][0];
                    $pos = $match[$group][1];

                    if ($group === 'unknown') {
                        throw new SyntaxErrorException("Unexpected character \"$raw\" at position $pos", $pos);
                    }

                    $tokens[] = $this->buildToken($group, $raw, $pos);
                    break;
                }
            }
        }

        $tokens[] = new Token(TokenType::T_EOF, null, strlen($expression));

        return $tokens;
    }

    /**
     * Replaces NBSP (U+00A0) with a regular ASCII space, but ONLY outside quoted
     * string literals.
     *
     * NBSP is treated as code-level whitespace by the lexer (most common non-ASCII
     * whitespace, typically introduced by copy-paste from word processors or web
     * forms). Replacing it lets the main PATTERN, which only matches ASCII
     * whitespace as an implicit token separator, skip it correctly.
     *
     * However, NBSP can also be a legitimate character INSIDE a string literal —
     * e.g. evaluate("a = '\xC2\xA0'", ['a' => "\xC2\xA0"]) must return true.
     * Stripping it from inside literals would silently change their value.
     *
     * The split strategy mirrors ExpressionEvaluator::canonicalizeForCache() and
     * AliasResolver::replaceOutsideStrings(). If you extend the quoted-literal
     * grammar (backtick strings, raw strings…), update all three call sites.
     */
    private function normalizeNbspOutsideStrings(string $expression): string
    {
        // Fast path: no NBSP at all, nothing to do. Avoids the preg_split cost
        // on the overwhelming majority of expressions.
        if (!str_contains($expression, self::NBSP_UTF8)) {
            return $expression;
        }

        $pattern = '/(?P<quoted>\'(?:[^\']|\'\')*\'|"(?:[^"]|"")*")/';
        $parts = preg_split(
            $pattern,
            $expression,
            flags: PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );

        $out = '';
        foreach ($parts as $part) {
            // A non-empty $part starting with ' or " is necessarily a quoted
            // literal segment returned by the capture group — unquoted segments
            // are split AT the quotes by preg_split and never start with one.
            if ($part !== '' && ($part[0] === "'" || $part[0] === '"')) {
                $out .= $part;
            } else {
                $out .= str_replace(self::NBSP_UTF8, ' ', $part);
            }
        }

        return $out;
    }

    private function buildToken(string $group, string $raw, int $pos): Token
    {
        return match ($group) {
            'float'      => $this->buildFloatToken($raw, $pos),
            'integer'    => $this->buildIntegerToken($raw, $pos),
            'string'     => new Token(TokenType::T_STRING,     $this->unquoteString($raw),    $pos),
            'boolean'    => new Token(TokenType::T_BOOLEAN,    strtolower($raw) === 'true', $pos),
            'null'       => new Token(TokenType::T_NULL,       null,                        $pos),
            'logical'    => new Token($this->logicalType($raw),   strtoupper($raw),         $pos),
            'operator'   => new Token($this->operatorType($raw),  $raw === '==' ? '=' : $raw,  $pos),
            'arith'      => new Token($this->arithType($raw),     $raw,                     $pos),
            'lparen'     => new Token(TokenType::T_LPAREN,    $raw,                         $pos),
            'rparen'     => new Token(TokenType::T_RPAREN,    $raw,                         $pos),
            'lbracket'   => new Token(TokenType::T_LBRACKET,  $raw,                         $pos),
            'rbracket'   => new Token(TokenType::T_RBRACKET,  $raw,                         $pos),
            'comma'      => new Token(TokenType::T_COMMA,     $raw,                         $pos),
            'coalesce'   => new Token(TokenType::T_COALESCE,  $raw,                         $pos),
            'question'   => new Token(TokenType::T_QUESTION,  $raw,                         $pos),
            'colon'      => new Token(TokenType::T_COLON,     $raw,                         $pos),
            'identifier' => new Token(TokenType::T_IDENTIFIER,$raw,                         $pos),
        };
    }

    /**
     * Builds a T_FLOAT token from a raw lexeme.
     *
     * Float literals that exceed PHP_FLOAT_MAX become INF when cast — symmetric
     * to the integer overflow check in buildIntegerToken(). Rejecting at
     * lex-time keeps INF from propagating into the AST and surfacing later
     * with a less actionable error.
     *
     * Extracted from buildToken's match arms (audit I2) — purely a readability
     * refactor, behaviour is unchanged.
     */
    private function buildFloatToken(string $raw, int $pos): Token
    {
        $value = (float) $raw;
        if (is_infinite($value)) {
            throw new SyntaxErrorException(
                "Float literal \"$raw\" exceeds PHP_FLOAT_MAX at position $pos",
                $pos
            );
        }
        return new Token(TokenType::T_FLOAT, $value, $pos);
    }

    /**
     * Builds a T_INTEGER (or T_INTEGER_OVERFLOW) token from a raw lexeme.
     *
     * The lexer never sees a leading sign (`-` and `+` are produced as
     * separate tokens and handled in Parser::parseUnary), so $raw is always
     * an unsigned decimal string here.
     *
     * Three cases:
     *   - magnitude in [0, PHP_INT_MAX]   → normal T_INTEGER (int value)
     *   - magnitude exactly PHP_INT_MAX + 1 → T_INTEGER_OVERFLOW token, value
     *     kept as raw string. This is the magnitude of |PHP_INT_MIN|, which
     *     is only valid when immediately preceded by a unary `-`. The parser
     *     is the only place where that context is known, so it is responsible
     *     for accepting (folding into PHP_INT_MIN) or rejecting this token.
     *   - magnitude > PHP_INT_MAX + 1    → hard overflow, rejected here.
     *
     * Lexicographic comparison via strcmp() guarantees identical behaviour on
     * 32- and 64-bit PHP. Both strings are equal length when strcmp() runs,
     * so byte-wise comparison matches numeric comparison.
     *
     * Extracted from buildToken's match arms (audit I2) — purely a readability
     * refactor, behaviour is unchanged.
     */
    private function buildIntegerToken(string $raw, int $pos): Token
    {
        $intMaxStr     = (string) PHP_INT_MAX;
        // PHP_INT_MAX + 1 as a decimal string. Hard-coded because PHP cannot
        // represent it as an int. Verified equal to the standard value of
        // 2^63 = 9223372036854775808 on every 64-bit PHP build (PHP_INT_SIZE
        // === 8); 32-bit builds are not supported by this library.
        $intMinAbsStr  = '9223372036854775808';
        $rawLen        = strlen($raw);
        $maxLen        = strlen($intMaxStr);

        if ($rawLen < $maxLen || ($rawLen === $maxLen && strcmp($raw, $intMaxStr) <= 0)) {
            return new Token(TokenType::T_INTEGER, (int) $raw, $pos);
        }

        if ($rawLen === $maxLen && strcmp($raw, $intMinAbsStr) === 0) {
            // Magnitude exactly equals |PHP_INT_MIN|. Defer the validity
            // check to the parser, which knows whether a unary `-` precedes.
            return new Token(TokenType::T_INTEGER_OVERFLOW, $raw, $pos);
        }

        throw new SyntaxErrorException(
            "Integer literal \"$raw\" exceeds PHP_INT_MAX at position $pos",
            $pos
        );
    }

    /**
     * Strips surrounding quotes and un-doubles the internal quote character.
     * 'L''Oréal'    →  L'Oréal
     * "say ""hi"" !" →  say "hi" !
     */
    private function unquoteString(string $raw): string
    {
        $quote = $raw[0]; // ' or "
        $inner = substr($raw, 1, -1);
        return str_replace($quote . $quote, $quote, $inner);
    }

    private function logicalType(string $raw): TokenType
    {
        return match (strtoupper($raw)) {
            'AND', '&&' => TokenType::T_AND,
            'OR',  '||' => TokenType::T_OR,
            'NOT'       => TokenType::T_NOT,
            'IN'        => TokenType::T_IN,
        };
    }

    private function operatorType(string $raw): TokenType
    {
        return match ($raw) {
            '=', '==' => TokenType::T_EQUAL,
            '!='      => TokenType::T_NOT_EQUAL,
            '>'  => TokenType::T_GT,
            '>=' => TokenType::T_GTE,
            '<'  => TokenType::T_LT,
            '<=' => TokenType::T_LTE,
        };
    }

    private function arithType(string $raw): TokenType
    {
        return match ($raw) {
            '+'  => TokenType::T_PLUS,
            '-'  => TokenType::T_MINUS,
            '*'  => TokenType::T_MULTIPLY,
            '/'  => TokenType::T_DIVIDE,
            '%'  => TokenType::T_MODULO,
        };
    }
}
