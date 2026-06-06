<?php declare(strict_types=1);
namespace Ols\PhpRuler;

/**
 * Translates between technical dot-notation paths and human-readable aliases.
 *
 * Alias constraints:
 *   - Must not contain single quotes (') or double quotes (")
 *     Reason: the translator splits expressions on quoted string literals using these
 *     characters as delimiters. An alias containing a quote would corrupt that split
 *     and cause silent mistranslations.
 *   - Must be unique across all registered aliases (one alias → one path)
 *   - Matching is case-sensitive: an alias registered as "Cart Total" must appear
 *     with the exact same casing in expressions passed to humanToExpression() and
 *     expressionToHuman(). "cart total" or "CART TOTAL" will not be resolved.
 *
 * If you need aliases with quotes, you would need to replace the string-splitting
 * approach in replaceOutsideStrings() with a character-by-character parser.
 */
final class AliasResolver
{
    /** @var array<string, string> path => alias  e.g. 'customer.group' => 'customer group' */
    private array $aliases = [];

    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    /**
     * Reserved language keywords — case-insensitive.
     * An alias matching one of these would be tokenized as an operator/literal
     * by the lexer after humanToExpression(), silently breaking the translation.
     */
    private const RESERVED_KEYWORDS = ['and', 'or', 'not', 'in', 'true', 'false', 'null'];

    /**
     * Registers an alias for a path.
     *
     * Uniqueness rules (asymmetric by design):
     *   - One alias maps to exactly one path. Reusing an existing alias for a
     *     different path throws InvalidArgumentException.
     *   - One path may be re-registered with a new alias. In that case the
     *     previous alias is silently dropped (last-write-wins):
     *
     *         $r->add('cart.total', 'cart amount');
     *         $r->add('cart.total', 'cart total');   // 'cart amount' is gone
     *
     *     This is intentional: paths are the canonical identifier, and the
     *     library is expected to be configured once at bootstrap. If you need
     *     to detect accidental redefinitions, inspect all() before calling add().
     *
     * @throws \InvalidArgumentException if $alias violates a syntactic constraint
     *         (quotes, whitespace, forbidden character, reserved keyword) or is
     *         already registered for a different path.
     */
    public function add(string $path, string $alias): self
    {
        if (str_contains($alias, "'") || str_contains($alias, '"')) {
            throw new \InvalidArgumentException(
                "Alias \"$alias\" must not contain single or double quotes. " .
                "Quotes are used as string delimiters in expressions and would corrupt alias translation."
            );
        }

        // Empty / whitespace-only — would produce zero-width matches in the
        // replacement regex and silently break expressionToHuman().
        if (trim($alias) === '') {
            throw new \InvalidArgumentException(
                'Alias must not be empty or whitespace-only.'
            );
        }

        // Leading/trailing whitespace — surrounding spaces interact badly with
        // the word-boundary regex used in replaceOutsideStrings(). Reject rather
        // than silently trim, to stay consistent with the lib's strict-mode
        // philosophy: surface the typo at registration time.
        if ($alias !== trim($alias)) {
            $suggestion = trim($alias);
            throw new \InvalidArgumentException(
                "Alias \"$alias\" must not start or end with whitespace. Did you mean \"$suggestion\"?"
            );
        }

        // Character whitelist — an alias must look like a human variable name,
        // not a piece of expression syntax. Allowed: ASCII letters, digits,
        // underscore, internal whitespace, and Unicode letters (accents, etc.).
        // Explicitly forbidden: dots (would collide with dot-notation paths and
        // interact badly with the word-boundary regex used in replacement),
        // dashes (ambiguous with the subtraction operator at lex time), and any
        // operator / punctuation / regex metacharacter (would produce a malformed
        // expression after humanToExpression() and fail in the lexer downstream).
        if (preg_match('/[^a-zA-Z0-9_\s\x{0080}-\x{FFFF}]/u', $alias)) {
            throw new \InvalidArgumentException(
                "Alias \"$alias\" must contain only letters, digits, underscores, " .
                "internal whitespace, and Unicode word characters. " .
                "Operators, punctuation, dots and dashes are not allowed."
            );
        }

        // Language keyword collision — case-insensitive, since the lexer matches
        // AND/and/And/aNd identically. Allowing 'and' as an alias would cause
        // humanToExpression() to inject the AND operator in place of a variable.
        if (in_array(strtolower($alias), self::RESERVED_KEYWORDS, true)) {
            throw new \InvalidArgumentException(
                "Alias \"$alias\" conflicts with a reserved language keyword (and/or/not/in/true/false/null). " .
                "Such an alias would be tokenized as an operator after translation."
            );
        }

        $flipped = array_flip($this->aliases);
        if (isset($flipped[$alias]) && $flipped[$alias] !== $path) {
            throw new \InvalidArgumentException(
                "Alias \"$alias\" is already used by path \"{$flipped[$alias]}\""
            );
        }
        $this->aliases[$path] = $alias;
        return $this;
    }

    public function remove(string $path): self
    {
        unset($this->aliases[$path]);
        return $this;
    }

    public function clear(): self
    {
        $this->aliases = [];
        return $this;
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->aliases;
    }

    // -------------------------------------------------------------------------
    // Translation
    // -------------------------------------------------------------------------

    /**
     * Translates a human-readable expression into a technical expression.
     * "customer group = 'vip' AND cart amount > 100"
     *   → "customer.group = 'vip' AND cart.total > 100"
     */
    public function humanToExpression(string $human): string
    {
        return $this->replaceOutsideStrings(
            $human,
            array_flip($this->aliases) // alias => path
        );
    }

    /**
     * Translates a technical expression into a human-readable expression.
     * "customer.group = 'vip' AND cart.total > 100"
     *   → "customer group = 'vip' AND cart amount > 100"
     */
    public function expressionToHuman(string $expression): string
    {
        return $this->replaceOutsideStrings(
            $expression,
            $this->aliases // path => alias
        );
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * Applies replacements only outside quoted portions (single or double quotes).
     * Prevents translating literal values: customer.group = 'cart.total'
     *
     * Uses word-boundary assertions to avoid partial matches:
     *   alias 'total' must not match inside 'subtotal'.
     *   alias 'or'    must not match the OR logical operator inside 'order'.
     *
     * Longest aliases are tested first (sort by descending length) so that
     * 'customer group name' is matched before 'customer group' when both exist.
     *
     * UTF-8 / Unicode handling (audit B4 + B16):
     *   The boundary classes include the same Unicode range as the alias
     *   character whitelist (\x{0080}-\x{FFFF}) and both regexes carry the /u
     *   flag. Without this, registering an alias 'menu' and evaluating an
     *   expression containing 'menü' would corrupt the latter: the lookbehind
     *   only excluded ASCII letters/digits/dot, so the byte 'ü' (UTF-8: C3 A9)
     *   was treated as a word-boundary terminator and 'menu' was rewritten in
     *   place — leaving 'path' + 'ü' garbage. Symmetric corruption applied to
     *   French aliases like 'nom' clashing with 'prénom'.
     *
     *   This decision is deliberate and FINAL: the AliasResolver explicitly
     *   accepts Unicode aliases (cf. character whitelist in add()), so its
     *   matching must use the same alphabet. Do not narrow these classes
     *   without first narrowing add()'s whitelist accordingly.
     */
    private function replaceOutsideStrings(string $expression, array $replacements): string
    {
        if (empty($replacements)) {
            return $expression;
        }

        // Split the expression into alternating segments: outside-quotes and inside-quotes.
        // /u activates UTF-8 mode: invalid byte sequences fail the regex rather
        // than producing silent mis-splits. See the function-level doc for the
        // rationale on Unicode-aware alias matching.
        $pattern = '/(?P<quoted>\'(?:[^\']|\'\')*\'|"(?:[^"]|"")*")/u';
        $parts   = preg_split($pattern, $expression, flags: PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            // preg_split returns false on malformed UTF-8 under /u mode (or on
            // catastrophic backtracking, which is impossible with this pattern).
            // Surface the failure rather than fall back to a non-/u split that
            // could silently mis-handle multi-byte sequences.
            throw new \InvalidArgumentException(
                'AliasResolver: expression contains invalid UTF-8 and cannot be processed.'
            );
        }

        // Sort by descending length to guarantee longest-match-first, mimicking strtr() behaviour.
        // e.g. 'customer group name' must be tried before 'customer group'.
        // NB: strlen() is byte-length, not codepoint count — both orderings work here
        // because for any two strings where one is a prefix of the other, the longer
        // in bytes is also the longer in codepoints.
        $sortedReplacements = $replacements;
        uksort($sortedReplacements, fn($a, $b) => strlen($b) - strlen($a));

        // Build a single alternation pattern from all keys.
        // Word boundary: the character immediately before/after the match must not be
        // [a-zA-Z0-9_.\x{0080}-\x{FFFF}]. The Unicode range matches the alphabet
        // accepted by add() and prevents the 'menu' vs 'menü' corruption (audit B4).
        // The dot is included so that 'total' does not match inside 'cart.total' (expressionToHuman).
        // The opening parenthesis is also excluded on the right-hand side: an alias represents
        // a variable, not a callable. Without this, registering 'total' as alias for path 'sum'
        // would rewrite the function call 'sum(x)' as 'total(x)' and break the expression.
        $escapedKeys = array_map('preg_quote', array_keys($sortedReplacements), array_fill(0, count($sortedReplacements), '/'));
        $replacePattern = '/(?<![a-zA-Z0-9_.\x{0080}-\x{FFFF}])('
            . implode('|', $escapedKeys)
            . ')(?![a-zA-Z0-9_.(\x{0080}-\x{FFFF}])/u';

        $result = '';
        foreach ($parts as $part) {
            if ($this->isQuoted($part)) {
                // Quoted segment → leave untouched
                $result .= $part;
            } else {
                // Unquoted segment — replace with word-boundary awareness
                $replaced = preg_replace_callback(
                    $replacePattern,
                    static function (array $matches) use ($sortedReplacements): string {
                        // Strict case-sensitive lookup — aliases are managed by developers
                        // and must match exactly as registered. No silent fallback.
                        return $sortedReplacements[$matches[1]] ?? $matches[1];
                    },
                    $part
                );
                if ($replaced === null) {
                    // Same rationale as the preg_split guard above: with /u active,
                    // null signals an invalid UTF-8 sequence inside the unquoted
                    // segment. We refuse to fall back to the raw $part because that
                    // would silently skip aliases that should have matched.
                    throw new \InvalidArgumentException(
                        'AliasResolver: expression contains invalid UTF-8 and cannot be processed.'
                    );
                }
                $result .= $replaced;
            }
        }

        return $result;
    }

    /**
     * Returns true only if $part is a well-formed quoted string:
     *   - at least 2 characters
     *   - opens and closes with the same delimiter (' or ")
     *   - the content between delimiters contains no bare (unescaped) delimiter
     *     (escaped form is the doubled delimiter: '' or "")
     *
     * This guards against pathological inputs like `"abc"def"` where a naive
     * starts/ends check would return a false positive.
     */
    private function isQuoted(string $part): bool
    {
        if (strlen($part) < 2) {
            return false;
        }

        $delimiter = $part[0];
        if ($delimiter !== "'" && $delimiter !== '"') {
            return false;
        }
        if ($part[-1] !== $delimiter) {
            return false;
        }

        // Check the content between the outer delimiters.
        // The only valid occurrence of the delimiter inside is the escaped form (doubled).
        $content = substr($part, 1, -1);
        $escaped = $delimiter . $delimiter;

        // Remove all escaped (doubled) delimiters, then check no bare one remains.
        $stripped = str_replace($escaped, '', $content);

        return !str_contains($stripped, $delimiter);
    }
}
