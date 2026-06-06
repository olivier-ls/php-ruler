<?php declare(strict_types=1);
namespace Ols\PhpRuler;

final class BuiltinFunctions
{
    /**
     * Returns all built-in functions as an array of ['name' => callable].
     *
     * @return array<string, callable>
     */
    public static function all(): array
    {
        return [
            // ---- Type casting --------------------------------------------
            // int() converts a value to an integer.
            //
            // Accepted inputs and their result:
            //   int                      → as-is
            //   float                    → truncated toward zero (NOT rounded)
            //   string (integer literal) → parsed as int, e.g. '42', '-7'
            //   anything else            → TypeErrorException
            //
            // IMPORTANT: floats are TRUNCATED TOWARD ZERO, not rounded.
            // This is PHP's native (int) cast behavior, which is neither
            // floor() nor round():
            //   int(3.7)   →  3   (not 4)
            //   int(3.5)   →  3   (not 4)
            //   int(-3.7)  → -3   (not -4, unlike floor)
            //   int(-3.5)  → -3   (not -4)
            //
            // If you need actual rounding, use round() instead.
            // If you need floor/ceil semantics, use floor() or ceil().
            // Integer strings are accepted (e.g. '42'), but float-looking
            // strings (e.g. '3.7') are rejected to avoid silent truncation
            // of values the caller may not realize were floats.
            'int' => function (mixed $val): int {
                if (is_int($val)) return $val;
                if (is_float($val)) return (int) $val;
                if (is_string($val) && preg_match('/^-?[0-9]+$/', $val)) {
                    // Reject magnitudes outside the int range rather than letting
                    // PHP's (int) cast silently clamp to PHP_INT_MAX / PHP_INT_MIN.
                    // That clamp would contradict the library's "no silent integer
                    // overflow" invariant — the lexer already rejects oversized
                    // integer *literals*, so int('<oversized>') must be just as strict.
                    $n          = (int) $val;
                    $sign       = $val[0] === '-' ? '-' : '';
                    $normalized = $sign . ltrim(ltrim($val, '+-'), '0');
                    if ($normalized === '' || $normalized === '-') {
                        $normalized = '0';
                    }
                    if ((string) $n !== $normalized) {
                        throw new Exception\TypeErrorException(
                            'int(): integer string "' . $val . '" is out of range ' .
                            '(exceeds PHP_INT_MAX / PHP_INT_MIN)'
                        );
                    }
                    return $n;
                }
                throw new Exception\TypeErrorException(
                    'int(): argument must be an int, a float, or an integer string, ' . gettype($val) . ' given'
                );
            },
            'float' => function (mixed $val): float {
                if (is_float($val)) return $val;
                if (is_int($val)) return (float) $val;
                if (is_string($val) && is_numeric($val)) return (float) $val;
                throw new Exception\TypeErrorException(
                    'float(): argument must be a float, an int, or a numeric string, ' . gettype($val) . ' given'
                );
            },
            // Accepted inputs and their result:
            //   bool                     → as-is
            //   int    0 or 1            → false / true
            //   float  0.0 or 1.0        → false / true
            //   string '0' or '1'        → false / true
            //   string 'false' or 'true' → false / true  (case-insensitive)
            //   anything else            → TypeErrorException
            //
            // Intentionally stricter than PHP's native (bool) cast:
            // values like 2, [], null, or arbitrary strings are rejected
            // to avoid silent wrong results in expression evaluation.
            'bool' => function (mixed $val): bool {
                if (is_bool($val)) return $val;

                if (is_int($val)) {
                    if ($val === 0) return false;
                    if ($val === 1) return true;
                }

                if (is_float($val)) {
                    if ($val === 0.0) return false;
                    if ($val === 1.0) return true;
                }

                if (is_string($val)) {
                    if ($val === '0') return false;
                    if ($val === '1') return true;
                    $lower = strtolower($val);
                    if ($lower === 'false') return false;
                    if ($lower === 'true')  return true;
                }

                throw new Exception\TypeErrorException(
                    'bool(): cannot convert ' . get_debug_type($val) . ' to bool. ' .
                    "Accepted values: true/false, 0/1 (int), 0.0/1.0 (float), '0'/'1', 'true'/'false' (string)."
                );
            },
            // Converts a value to its string representation.
            // Floats are formatted with up to 14 significant decimal places and trailing
            // zeros are stripped, so 1.0 → '1', 1.50 → '1.5', 0.000001 → '0.000001'.
            // This avoids PHP's native scientific notation (e.g. '1.0E-6') which is
            // unsuitable for display or string concatenation in most use cases.
            // The float formatting logic is shared with concat() — see
            // formatFloatForString() below for the magnitude/precision policy.
            'str' => function (mixed $val): string {
                if (is_string($val)) return $val;
                if (is_int($val))    return (string) $val;
                if (is_float($val))  return self::formatFloatForString($val, 'str');
                if (is_bool($val))   return $val ? 'true' : 'false';
                throw new Exception\TypeErrorException(
                    'str(): argument must be a string, int, float, or bool, ' . gettype($val) . ' given'
                );
            },

            // ---- Rounding ------------------------------------------------
            'round' => function (float|int $val, int $precision = 0): float {
                if ($precision < 0 || $precision > 14) {
                    throw new Exception\TypeErrorException(
                        'round(): precision must be between 0 and 14, ' . $precision . ' given'
                    );
                }
                return round((float) $val, $precision);
            },
            'floor' => fn(float|int $val): float => floor((float) $val),
            'ceil'  => fn(float|int $val): float  => ceil((float) $val),

            // ---- Absolute value ------------------------------------------
            'abs'   => fn(float|int $val): float|int             => abs($val),

            // ---- Finiteness check ----------------------------------------
            // Escape hatch for inspecting NaN / INF values from variables or
            // custom function returns. Without this, NaN/INF cannot be detected
            // from within the expression language since they trigger
            // TypeErrorException in every arithmetic operator and comparison.
            //   is_finite(NAN)  → false
            //   is_finite(INF)  → false
            //   is_finite(-INF) → false
            //   is_finite(any other number) → true
            //   is_finite(non-number) → TypeErrorException
            'is_finite' => function (mixed $val): bool {
                if (!is_int($val) && !is_float($val)) {
                    throw new Exception\TypeErrorException(
                        'is_finite(): argument must be a number, ' . gettype($val) . ' given'
                    );
                }
                return is_finite((float) $val);
            },

            // ---- Min / Max -----------------------------------------------
            // Intentionally limited to 2 arguments — use clamp() to bound a value
            'min' => function (float|int $a, float|int $b, mixed ...$extra): float|int {
                if (!empty($extra)) {
                    throw new Exception\TypeErrorException(
                        'min() expects exactly 2 arguments. To find the minimum of a list, use min_of(list).'
                    );
                }
                return min($a, $b);
            },
            'max' => function (float|int $a, float|int $b, mixed ...$extra): float|int {
                if (!empty($extra)) {
                    throw new Exception\TypeErrorException(
                        'max() expects exactly 2 arguments. To find the maximum of a list, use max_of(list).'
                    );
                }
                return max($a, $b);
            },

            // ---- Power / Square root -------------------------------------
            // Return type is intentionally float|int (audit B6).
            //
            // PHP's native pow() returns an int when both operands are ints
            // AND the result fits in PHP_INT_MAX (e.g. pow(2, 62) → int). Casting
            // such a result to float would silently lose precision past the
            // float mantissa's 52 bits — for example, pow(3, 39) is exact as int
            // but rounds to a different value once forced through float. We
            // therefore return the raw pow() result.
            //
            // The finite-result guard below (audit B7) ensures that overflow into
            // INF never escapes pow() silently. This is a deliberate departure
            // from the general "INF transits to the next operator" policy
            // applied to variable resolution and function returns elsewhere:
            // pow() is the most common source of float overflow in business
            // expressions (10^N with user-supplied N), and the calling operator
            // would surface the failure with a less actionable error
            // ("operator '+' value is INF") than pow() itself does. Catching
            // here gives a clearer message. Decision is deliberate and FINAL.
            'pow'   => function (float|int $base, float|int $exp): float|int {
                // A negative base with a non-integer exponent produces NAN in PHP
                // (equivalent to a complex number), which would propagate silently.
                if ($base < 0 && is_float($exp) && fmod($exp, 1.0) !== 0.0) {
                    throw new Exception\TypeErrorException(
                        'pow(): base must not be negative when exponent is non-integer (' .
                        $base . ', ' . $exp . ' given) — result would be NaN'
                    );
                }
                // A zero base with a negative exponent is a division by zero (0^-n = 1/0^n).
                if (($base === 0 || $base === 0.0) && $exp < 0) {
                    throw new Exception\TypeErrorException(
                        'pow(): base cannot be zero with a negative exponent — result would be infinite'
                    );
                }
                $result = pow($base, $exp);
                // Float overflow guard — mirrors assertFinite() in Evaluator,
                // applied here because BuiltinFunctions is decoupled from the
                // evaluator's helpers. See the function-level doc above for the
                // rationale on catching INF inside pow() specifically.
                if (is_float($result)) {
                    if (is_nan($result)) {
                        // Defensive: the two preconditions above should rule out NaN,
                        // but pow() has historically had edge cases (e.g. pow(-1, INF)).
                        throw new Exception\TypeErrorException(
                            'pow(): result is NaN (not-a-number) for base=' . $base . ', exp=' . $exp
                        );
                    }
                    if (is_infinite($result)) {
                        throw new Exception\TypeErrorException(
                            'pow(): result overflowed to ' . ($result > 0 ? 'INF' : '-INF') .
                            ' for base=' . $base . ', exp=' . $exp
                        );
                    }
                }
                return $result;
            },
            'sqrt'  => function(float|int $val): float {
                if ($val < 0) {
                    throw new Exception\TypeErrorException(
                        'sqrt(): argument must be positive or zero, ' . $val . ' given'
                    );
                }
                return sqrt($val);
            },

            // ---- Clamp ---------------------------------------------------
            'clamp' => function (float|int $val, float|int $min, float|int $max): float|int {
                if ($min > $max) {
                    throw new Exception\TypeErrorException(
                        "clamp(): min ($min) cannot be greater than max ($max)"
                    );
                }
                return max($min, min($max, $val));
            },

            // ---- Length --------------------------------------------------
            // On a string : number of UTF-8 characters
            // On an array : number of elements
            'length' => function (mixed $val): int {
                if (is_array($val))  return count($val);
                if (is_string($val)) return \mb_strlen($val);
                throw new Exception\TypeErrorException(
                    'length() expects a string or an array, ' . gettype($val) . ' given'
                );
            },
            // count() is the array-specific counterpart of length().
            // length() already handles both strings and arrays, but count() makes
            // the intent explicit when working with lists — consistent with PHP habits.
            'count' => function (mixed $val): int {
                if (is_array($val)) return count($val);
                throw new Exception\TypeErrorException(
                    'count() expects an array, ' . gettype($val) . ' given. Use length() for strings.'
                );
            },

            // ---- String --------------------------------------------------
            'upper'      => fn(string $val): string => \mb_strtoupper($val),
            'lower'      => fn(string $val): string => \mb_strtolower($val),
            'trim'       => fn(string $val): string => trim($val),

            'contains'   => fn(string $haystack, string $needle): bool => str_contains($haystack, $needle),
            'startsWith' => fn(string $haystack, string $needle): bool => str_starts_with($haystack, $needle),
            'endsWith'   => fn(string $haystack, string $needle): bool => str_ends_with($haystack, $needle),

            // substr(str, start) or substr(str, start, length)
            'substr' => fn(string $val, int $start, ?int $length = null): string => \mb_substr($val, $start, $length),

            // concat(a, b, ...) — int/float are cast to string implicitly.
            // Float formatting (including NaN/INF rejection and the magnitude
            // guards documented in formatFloatForString) is shared with str()
            // via the helper — see audit B8: previously, concat() had its own
            // inline formatter without the >= 1e15 / < 1e-10 guards, so
            // concat("v=", 1e-15) silently returned "v=0".
            'concat' => function (mixed ...$parts): string {
                foreach ($parts as $i => $part) {
                    if (!is_string($part) && !is_int($part) && !is_float($part)) {
                        throw new Exception\TypeErrorException(
                            'concat(): argument ' . ($i + 1) . ' must be a string or number, ' . gettype($part) . ' given'
                        );
                    }
                }
                return implode('', array_map(function (mixed $p): string {
                    if (is_float($p)) {
                        return self::formatFloatForString($p, 'concat');
                    }
                    if (is_int($p)) {
                        return (string) $p;
                    }
                    return $p; // string, guaranteed by the type guard above
                }, $parts));
            },

            // replace(str, search, replace)
            'replace' => fn(string $subject, string $search, string $replace): string
                => str_replace($search, $replace, $subject),

            // coalesce(a, b, c, ...) — returns the first non-null argument
            // Functional complement to the ?? operator for N-ary use cases
            'coalesce' => function (mixed ...$args): mixed {
                foreach ($args as $arg) {
                    if ($arg !== null) return $arg;
                }
                return null;
            },

            // ---- Aggregates on lists -------------------------------------
            'sum' => function (array $list): int|float {
                if (empty($list)) return 0;
                foreach ($list as $i => $item) {
                    if (!is_int($item) && !is_float($item)) {
                        throw new Exception\TypeErrorException(
                            'sum(): element ' . $i . ' must be a number, ' . gettype($item) . ' given'
                        );
                    }
                }
                return array_sum($list);
            },

            'avg' => function (array $list): float {
                if (empty($list)) {
                    throw new Exception\TypeErrorException('avg(): list must not be empty');
                }
                foreach ($list as $i => $item) {
                    if (!is_int($item) && !is_float($item)) {
                        throw new Exception\TypeErrorException(
                            'avg(): element ' . $i . ' must be a number, ' . gettype($item) . ' given'
                        );
                    }
                }
                return array_sum($list) / count($list);
            },

            'min_of' => function (array $list): int|float {
                if (empty($list)) {
                    throw new Exception\TypeErrorException('min_of(): list must not be empty');
                }
                foreach ($list as $i => $item) {
                    if (!is_int($item) && !is_float($item)) {
                        throw new Exception\TypeErrorException(
                            'min_of(): element ' . $i . ' must be a number, ' . gettype($item) . ' given'
                        );
                    }
                }
                return min($list);
            },

            'max_of' => function (array $list): int|float {
                if (empty($list)) {
                    throw new Exception\TypeErrorException('max_of(): list must not be empty');
                }
                foreach ($list as $i => $item) {
                    if (!is_int($item) && !is_float($item)) {
                        throw new Exception\TypeErrorException(
                            'max_of(): element ' . $i . ' must be a number, ' . gettype($item) . ' given'
                        );
                    }
                }
                return max($list);
            },

            // ---- Date ----------------------------------------------------
            // Supported formats : Y-m-d | Y-m-d H:i | Y-m-d H:i:s
            'today'  => fn(): string => (new \DateTime())->format('Y-m-d'),
            'now'    => fn(): string => (new \DateTime())->format('Y-m-d H:i:s'),

            'year'   => fn(string $date): int => (int) self::parseDate($date)->format('Y'),
            'month'  => fn(string $date): int => (int) self::parseDate($date)->format('m'),
            'day'    => fn(string $date): int => (int) self::parseDate($date)->format('d'),
            'hour'   => fn(string $date): int => (int) self::parseDate($date)->format('H'),
            'minute' => fn(string $date): int => (int) self::parseDate($date)->format('i'),

            // dateDiff(date1, date2) : difference in whole days (negative if date1 < date2)
            'dateDiff' => function (string $date1, string $date2): int {
                $d1   = self::parseDate($date1)->setTime(0, 0, 0);
                $d2   = self::parseDate($date2)->setTime(0, 0, 0);
                $days = (int) $d1->diff($d2)->days;
                return $d1 < $d2 ? -$days : $days;
            },

            // dateAdd(date, n, unit) : unit = day | month | year | hour | minute
            // n can be negative to subtract.
            //
            // Month/year overflow: this function relies on PHP's native DateInterval,
            // which normalizes invalid dates by overflowing into the next month.
            // It does NOT snap to the last day of the target month.
            //
            // Examples:
            //   dateAdd('2026-01-31',  1, 'month') => '2026-03-03'  (not '2026-02-28')
            //   dateAdd('2024-02-29',  1, 'year')  => '2025-03-01'  (not '2025-02-28')
            //   dateAdd('2026-03-31', -1, 'month') => '2026-03-03'  (not '2026-02-28')
            //
            // If "snap to end of month" semantics are required for a given use case,
            // they must be handled at the expression level by the caller
            // (e.g. by combining dateAdd with an endOfMonth-style helper).
            'dateAdd' => function (string $date, int $n, string $unit): string {
                $allowed = ['day', 'month', 'year', 'hour', 'minute'];
                if (!in_array($unit, $allowed, true)) {
                    throw new Exception\TypeErrorException(
                        'dateAdd(): invalid unit "' . $unit . '". Accepted values: ' . implode(', ', $allowed)
                    );
                }
                $d    = self::parseDate($date);
                $spec = match ($unit) {
                    'year'   => 'P' . abs($n) . 'Y',
                    'month'  => 'P' . abs($n) . 'M',
                    'day'    => 'P' . abs($n) . 'D',
                    'hour'   => 'PT' . abs($n) . 'H',
                    'minute' => 'PT' . abs($n) . 'M',
                };
                $interval = new \DateInterval($spec);
                $n >= 0 ? $d->add($interval) : $d->sub($interval);
                // Preserve the input format including ISO 8601 'T' separator.
                // Count ':' to distinguish H:i from H:i:s; check for 'T' to
                // round-trip ISO 8601 inputs (e.g. '2026-01-15T14:30:00').
                $hasT   = str_contains($date, 'T');
                $sep    = $hasT ? '\T' : ' ';
                $format = match (substr_count($date, ':')) {
                    2       => 'Y-m-d' . $sep . 'H:i:s',
                    1       => 'Y-m-d' . $sep . 'H:i',
                    default => 'Y-m-d',
                };
                return $d->format($format);
            },
        ];
    }

    /**
     * Returns the list of built-in function names.
     *
     * @return string[]
     */
    public static function names(): array
    {
        return array_keys(self::all());
    }

    /**
     * Formats a float for inclusion in a string output (str(), concat()).
     *
     * Rejects values that cannot be represented faithfully without scientific
     * notation, and adapts decimal precision to the magnitude so that
     * 1234.5678 stays "1234.5678" instead of "1234.56780000000003".
     *
     * Audit B8: the formatting logic was originally duplicated between str()
     * and concat() — and concat() lacked the >= 1e15 / < 1e-10 guards, which
     * meant concat("v=", 1e-15) silently returned "v=0" (the small value
     * collapsed to zero through number_format with a fixed decimal cap).
     * Centralising the formatter ensures both call sites have identical
     * guards and identical output.
     *
     * @param string $fnName  Source function name, used in error messages
     *                        ('str' or 'concat') so the caller knows which
     *                        builtin rejected the value.
     * @throws Exception\TypeErrorException
     */
    private static function formatFloatForString(float $val, string $fnName): string
    {
        if (is_nan($val)) {
            throw new Exception\TypeErrorException(
                $fnName . '(): cannot convert NaN to string'
            );
        }
        if (is_infinite($val)) {
            throw new Exception\TypeErrorException(
                $fnName . '(): cannot convert ' . ($val > 0 ? 'INF' : '-INF') . ' to string'
            );
        }
        if (abs($val) >= 1e15) {
            throw new Exception\TypeErrorException(
                $fnName . '(): float value ' . $val . ' is too large to be represented as a readable string. ' .
                'Use round() or a custom format before converting.'
            );
        }
        if ($val === 0.0) return '0';
        // Reject very small floats symmetrically with the >= 1e15 upper bound.
        // Below 1e-10, number_format with our decimal cap would silently round
        // the value to "0" or to a wrong magnitude, which is a worse outcome
        // than refusing the conversion. Business-domain expressions (prices,
        // ratios, quantities) never legitimately produce values that small —
        // if they do, it almost always indicates numerical underflow from a
        // prior calculation, and the caller should investigate.
        if (abs($val) < 1e-10) {
            throw new Exception\TypeErrorException(
                $fnName . '(): float value ' . $val . ' is too small to be represented without scientific notation. ' .
                'Use round() or a custom format before converting.'
            );
        }
        // Adapt decimal precision to the magnitude of the value so that
        // 1234.5678 returns '1234.5678' and not '1234.56780000000003'.
        // We target PHP_FLOAT_DIG (15) significant digits total.
        // For abs < 1.0, the >= 1e-10 check above guarantees that 14 decimals
        // are enough to preserve at least one significant digit, so no value
        // can silently round to "0" here.
        $abs = abs($val);
        if ($abs >= 1.0) {
            $intDigits = (int)(floor(log10($abs)) + 1);
            $decimals  = max(0, 14 - $intDigits);
        } else {
            $decimals = 14; // small floats: keep 14 decimal places
        }
        $str = number_format($val, $decimals, '.', '');
        // Strip trailing zeros only when a decimal point is present —
        // rtrim on an integer string like '10000000000000' would corrupt it.
        if ($decimals > 0) {
            $str = rtrim(rtrim($str, '0'), '.');
        }
        return $str;
    }

    /**
     * Parses a string into a DateTime object.
     * Supported formats: Y-m-d | Y-m-d H:i | Y-m-d H:i:s
     *
     * @throws Exception\TypeErrorException
     */
    private static function parseDate(string $value): \DateTime
    {
        $formats = [
            // Space-separated datetime (legacy default)
            'Y-m-d H:i:s|' => 'Y-m-d H:i:s',
            'Y-m-d H:i|'   => 'Y-m-d H:i',
            // ISO 8601 with 'T' separator (common in JSON / REST APIs)
            'Y-m-d\TH:i:s|' => 'Y-m-d\TH:i:s',
            'Y-m-d\TH:i|'   => 'Y-m-d\TH:i',
            // Date only
            'Y-m-d|'        => 'Y-m-d',
        ];

        foreach ($formats as $parseFormat => $checkFormat) {
            $dt = \DateTime::createFromFormat($parseFormat, $value);
            if ($dt !== false && $dt->format($checkFormat) === $value) {
                return $dt;
            }
        }

        throw new Exception\TypeErrorException(
            'Invalid date format: "' . $value . '". Accepted formats: Y-m-d, Y-m-d H:i, Y-m-d H:i:s, Y-m-d\TH:i, Y-m-d\TH:i:s'
        );
    }
}
