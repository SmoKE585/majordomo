<?php

namespace MajorDoMo\Core\Template;

/**
 * Safe evaluator for `[#if ...#]` conditions.
 *
 * Templates used to turn a condition into PHP code and run it through eval().
 * That broke on PHP 8 (`[#if RESULT AND LEVEL_PAD!=0#]` → undefined constant)
 * and made every condition a code-execution surface. This parser supports the
 * subset conditions actually use and never evaluates arbitrary code:
 *
 *   identifiers   ACTION, RESULT          → isset($data[$name]) ? value : null
 *   literals      'text', "text", 42, 1.5, true, false, null
 *   comparison    = == === != !== <> < > <= >=
 *   logic         && AND, || OR, ! NOT, parentheses
 *
 * Values keep PHP's own comparison and truthiness semantics, so behaviour for
 * existing templates is unchanged (verified by tests/template_condition_smoke.php).
 */
final class Condition
{
    private const COMPARISONS = array('===', '!==', '==', '!=', '<>', '<=', '>=', '=', '<', '>');

    /**
     * @param string $expression Condition text inside [#if ...#]
     * @param array<string, mixed> $data Template data
     * @throws \InvalidArgumentException When the expression uses unsupported syntax.
     */
    public static function evaluate(string $expression, array $data): bool
    {
        $tokens = self::tokenize($expression);
        $position = 0;
        $value = self::parseOr($tokens, $position, $data);
        if ($position !== count($tokens)) {
            throw new \InvalidArgumentException('Unexpected token "' . self::tokenText($tokens[$position]) . '"');
        }

        return (bool)$value;
    }

    /**
     * @return array<int, array{type: string, value: mixed}>
     */
    private static function tokenize(string $expression): array
    {
        $tokens = array();
        $length = strlen($expression);
        $offset = 0;

        while ($offset < $length) {
            $char = $expression[$offset];

            if (ctype_space($char)) {
                $offset++;
                continue;
            }

            if ($char === '(' || $char === ')') {
                $tokens[] = array('type' => $char === '(' ? 'lparen' : 'rparen', 'value' => $char);
                $offset++;
                continue;
            }

            if ($char === "'" || $char === '"') {
                $end = self::stringEnd($expression, $offset, $char);
                if ($end === null) {
                    throw new \InvalidArgumentException('Unterminated string literal');
                }
                $raw = substr($expression, $offset + 1, $end - $offset - 1);
                $tokens[] = array('type' => 'string', 'value' => self::decodeString($raw, $char));
                $offset = $end + 1;
                continue;
            }

            $matched = false;
            foreach (self::COMPARISONS as $operator) {
                $operatorLength = strlen($operator);
                if (substr($expression, $offset, $operatorLength) === $operator) {
                    $tokens[] = array('type' => 'comparison', 'value' => $operator);
                    $offset += $operatorLength;
                    $matched = true;
                    break;
                }
            }
            if ($matched) {
                continue;
            }

            if ($char === '&' || $char === '|') {
                $double = substr($expression, $offset, 2);
                if ($double === '&&' || $double === '||') {
                    $tokens[] = array('type' => 'logic', 'value' => $double);
                    $offset += 2;
                    continue;
                }
                throw new \InvalidArgumentException('Unexpected character "' . $char . '"');
            }

            if ($char === '!') {
                $tokens[] = array('type' => 'not', 'value' => '!');
                $offset++;
                continue;
            }

            if (preg_match('/\G\d+(?:\.\d+)?/', $expression, $match, 0, $offset)) {
                $number = $match[0];
                $tokens[] = array(
                    'type' => 'number',
                    'value' => str_contains($number, '.') ? (float)$number : (int)$number,
                );
                $offset += strlen($number);
                continue;
            }

            if (preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $expression, $match, 0, $offset)) {
                $word = $match[0];
                $upper = strtoupper($word);
                if ($upper === 'AND' || $upper === 'OR') {
                    $tokens[] = array('type' => 'logic', 'value' => $upper);
                } elseif ($upper === 'NOT') {
                    $tokens[] = array('type' => 'not', 'value' => '!');
                } else {
                    $tokens[] = array('type' => 'identifier', 'value' => $word);
                }
                $offset += strlen($word);
                continue;
            }

            throw new \InvalidArgumentException('Unexpected character "' . $char . '" at offset ' . $offset);
        }

        return $tokens;
    }

    private static function stringEnd(string $expression, int $offset, string $quote): ?int
    {
        $length = strlen($expression);
        for ($index = $offset + 1; $index < $length; $index++) {
            if ($expression[$index] === '\\') {
                $index++;
                continue;
            }
            if ($expression[$index] === $quote) {
                return $index;
            }
        }

        return null;
    }

    private static function decodeString(string $raw, string $quote): string
    {
        if ($quote === "'") {
            // PHP single quotes: only \' and \\ are special.
            return str_replace(array("\\'", '\\\\'), array("'", '\\'), $raw);
        }

        // PHP double quotes: standard escape sequences, no interpolation support
        // (templates never use variables inside condition strings).
        return stripcslashes($raw);
    }

    /**
     * @param array<int, array{type: string, value: mixed}> $tokens
     * @param array<string, mixed> $data
     */
    private static function parseOr(array $tokens, int &$position, array $data)
    {
        $value = self::parseAnd($tokens, $position, $data);
        while (self::isLogic($tokens[$position] ?? null, array('||', 'OR'))) {
            $position++;
            $right = self::parseAnd($tokens, $position, $data);
            $value = (bool)$value || (bool)$right;
        }

        return $value;
    }

    /**
     * @param array<int, array{type: string, value: mixed}> $tokens
     * @param array<string, mixed> $data
     */
    private static function parseAnd(array $tokens, int &$position, array $data)
    {
        $value = self::parseUnary($tokens, $position, $data);
        while (self::isLogic($tokens[$position] ?? null, array('&&', 'AND'))) {
            $position++;
            $right = self::parseUnary($tokens, $position, $data);
            $value = (bool)$value && (bool)$right;
        }

        return $value;
    }

    /**
     * @param array<int, array{type: string, value: mixed}> $tokens
     * @param array<string, mixed> $data
     */
    private static function parseUnary(array $tokens, int &$position, array $data)
    {
        $token = $tokens[$position] ?? null;
        if ($token !== null && $token['type'] === 'not') {
            $position++;
            return !self::parseUnary($tokens, $position, $data);
        }

        return self::parseComparison($tokens, $position, $data);
    }

    /**
     * @param array<int, array{type: string, value: mixed}> $tokens
     * @param array<string, mixed> $data
     */
    private static function parseComparison(array $tokens, int &$position, array $data)
    {
        $left = self::parsePrimary($tokens, $position, $data);

        $token = $tokens[$position] ?? null;
        if ($token === null || $token['type'] !== 'comparison') {
            return $left;
        }

        $operator = (string)$token['value'];
        $position++;
        $right = self::parsePrimary($tokens, $position, $data);

        return self::compare($left, $operator, $right);
    }

    /**
     * @param array<int, array{type: string, value: mixed}> $tokens
     * @param array<string, mixed> $data
     */
    private static function parsePrimary(array $tokens, int &$position, array $data)
    {
        $token = $tokens[$position] ?? null;
        if ($token === null) {
            throw new \InvalidArgumentException('Unexpected end of condition');
        }

        if ($token['type'] === 'lparen') {
            $position++;
            $value = self::parseOr($tokens, $position, $data);
            $closing = $tokens[$position] ?? null;
            if ($closing === null || $closing['type'] !== 'rparen') {
                throw new \InvalidArgumentException('Missing closing parenthesis');
            }
            $position++;
            return $value;
        }

        if ($token['type'] === 'rparen') {
            throw new \InvalidArgumentException('Unexpected closing parenthesis');
        }

        $position++;

        if ($token['type'] === 'identifier') {
            $upper = strtoupper((string)$token['value']);
            if ($upper === 'TRUE') {
                return true;
            }
            if ($upper === 'FALSE') {
                return false;
            }
            if ($upper === 'NULL') {
                return null;
            }
            $name = (string)$token['value'];
            return isset($data[$name]) ? $data[$name] : null;
        }

        if ($token['type'] === 'number' || $token['type'] === 'string') {
            return $token['value'];
        }

        throw new \InvalidArgumentException('Unexpected token "' . self::tokenText($token) . '"');
    }

    /**
     * PHP comparison semantics, exactly as the eval()-based version relied on.
     *
     * @param mixed $left
     * @param mixed $right
     */
    private static function compare($left, string $operator, $right): bool
    {
        switch ($operator) {
            case '=':
            case '==':
                return $left == $right;
            case '===':
                return $left === $right;
            case '!=':
            case '<>':
                return $left != $right;
            case '!==':
                return $left !== $right;
            case '<':
                return $left < $right;
            case '>':
                return $left > $right;
            case '<=':
                return $left <= $right;
            case '>=':
                return $left >= $right;
        }

        throw new \InvalidArgumentException('Unsupported comparison "' . $operator . '"');
    }

    /**
     * @param array{type: string, value: mixed}|null $token
     * @param string[] $operators
     */
    private static function isLogic(?array $token, array $operators): bool
    {
        return $token !== null
            && $token['type'] === 'logic'
            && in_array((string)$token['value'], $operators, true);
    }

    /**
     * @param array{type: string, value: mixed} $token
     */
    private static function tokenText(array $token): string
    {
        return is_scalar($token['value']) ? (string)$token['value'] : $token['type'];
    }
}
