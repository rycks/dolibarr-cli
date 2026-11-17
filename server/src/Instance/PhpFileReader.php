<?php

namespace Dolicli\Instance;

/**
 * Read the top-level variable assignments and define() calls of a PHP file
 * (conf.php, filefunc.inc.php) with token_get_all(), never with include.
 *
 * conf.php often belongs to the web account: executing it as root would allow a
 * privilege escalation, and including several conf.php in one process collides on
 * their define(). Only a safe subset of expressions is evaluated: string and number
 * literals, true/false/null, __DIR__, __FILE__, dirname() and concatenation.
 * Anything else leaves the value unknown.
 */
final class PhpFileReader
{
    /** @var array<string, mixed> */
    private $variables = [];

    /** @var array<string, mixed> */
    private $constants = [];

    /** @var string */
    private $file;

    private function __construct(string $file)
    {
        $this->file = $file;
    }

    /**
     * @return self|null Null when the file cannot be read
     */
    public static function fromFile(string $file)
    {
        $code = @file_get_contents($file);
        if (!is_string($code)) {
            return null;
        }

        return self::fromCode($code, $file);
    }

    public static function fromCode(string $code, string $file): self
    {
        $reader = new self($file);
        $reader->parse($code);

        return $reader;
    }

    /**
     * Value of a top-level variable (without $), null when absent or not evaluable.
     *
     * @return mixed
     */
    public function variable(string $name)
    {
        return array_key_exists($name, $this->variables) ? $this->variables[$name] : null;
    }

    public function hasVariable(string $name): bool
    {
        return array_key_exists($name, $this->variables);
    }

    /**
     * @return mixed
     */
    public function constant(string $name)
    {
        return array_key_exists($name, $this->constants) ? $this->constants[$name] : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function variables(): array
    {
        return $this->variables;
    }

    private function parse(string $code)
    {
        $tokens = [];
        foreach (token_get_all($code) as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML], true)) {
                continue;
            }
            $tokens[] = $token;
        }

        $depth = 0;
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if ('{' === $token || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
                continue;
            }
            if ('}' === $token) {
                $depth = max(0, $depth - 1);
                continue;
            }
            if (!is_array($token)) {
                continue;
            }

            // $name = expression ; (top level only: assignments in branches are conditional)
            if (0 === $depth && T_VARIABLE === $token[0] && isset($tokens[$i + 1]) && '=' === $tokens[$i + 1] && $this->statementStart($tokens, $i)) {
                $end = $this->statementEnd($tokens, $i + 2);
                $value = $this->evaluate(array_slice($tokens, $i + 2, $end - $i - 2), $ok);
                $name = substr($token[1], 1);
                if ($ok) {
                    $this->variables[$name] = $value;
                } else {
                    unset($this->variables[$name]);
                }
                $i = $end;
                continue;
            }

            // define('NAME', expression); at any depth (filefunc.inc.php guards it with
            // if (!defined(...))), the first definition wins like at run time.
            if (T_STRING === $token[0] && 'define' === strtolower($token[1]) && isset($tokens[$i + 1]) && '(' === $tokens[$i + 1]) {
                $close = $this->matchingParen($tokens, $i + 1);
                $args = $this->splitArguments(array_slice($tokens, $i + 2, $close - $i - 2));
                if (count($args) >= 2) {
                    $name = $this->evaluate($args[0], $okName);
                    $value = $this->evaluate($args[1], $okValue);
                    if ($okName && $okValue && is_string($name) && !array_key_exists($name, $this->constants)) {
                        $this->constants[$name] = $value;
                    }
                }
                $i = $close;
            }
        }
    }

    /**
     * @param array<int, mixed> $tokens
     */
    private function statementStart(array $tokens, int $index): bool
    {
        if (0 === $index) {
            return true;
        }
        $previous = $tokens[$index - 1];

        return ';' === $previous || '{' === $previous || '}' === $previous || ':' === $previous;
    }

    /**
     * @param array<int, mixed> $tokens
     */
    private function statementEnd(array $tokens, int $from): int
    {
        $level = 0;
        $count = count($tokens);
        for ($i = $from; $i < $count; $i++) {
            if ('(' === $tokens[$i] || '[' === $tokens[$i]) {
                $level++;
            } elseif (')' === $tokens[$i] || ']' === $tokens[$i]) {
                $level--;
            } elseif (';' === $tokens[$i] && $level <= 0) {
                return $i;
            }
        }

        return $count - 1;
    }

    /**
     * @param array<int, mixed> $tokens
     */
    private function matchingParen(array $tokens, int $open): int
    {
        $level = 0;
        $count = count($tokens);
        for ($i = $open; $i < $count; $i++) {
            if ('(' === $tokens[$i]) {
                $level++;
            } elseif (')' === $tokens[$i]) {
                $level--;
                if (0 === $level) {
                    return $i;
                }
            }
        }

        return $count - 1;
    }

    /**
     * @param array<int, mixed> $tokens
     * @return array<int, array<int, mixed>>
     */
    private function splitArguments(array $tokens): array
    {
        $args = [];
        $current = [];
        $level = 0;
        foreach ($tokens as $token) {
            if ('(' === $token) {
                $level++;
            } elseif (')' === $token) {
                $level--;
            }
            if (',' === $token && 0 === $level) {
                $args[] = $current;
                $current = [];
                continue;
            }
            $current[] = $token;
        }
        if ([] !== $current) {
            $args[] = $current;
        }

        return $args;
    }

    /**
     * Evaluate a concatenation of terms; $ok is false when anything is not supported.
     *
     * @param array<int, mixed> $tokens
     * @param mixed $ok
     * @param-out bool $ok
     * @return mixed
     */
    private function evaluate(array $tokens, &$ok)
    {
        $ok = true;
        $position = 0;
        $value = $this->term($tokens, $position, $ok);
        while ($ok && $position < count($tokens)) {
            if ('.' !== $tokens[$position]) {
                $ok = false;

                return null;
            }
            $position++;
            $right = $this->term($tokens, $position, $ok);
            if (!$ok || is_array($value) || is_array($right)) {
                $ok = false;

                return null;
            }
            $value = (string) $value . (string) $right;
        }

        return $ok ? $value : null;
    }

    /**
     * @param array<int, mixed> $tokens
     * @return mixed
     */
    private function term(array $tokens, int &$position, bool &$ok)
    {
        if (!isset($tokens[$position])) {
            $ok = false;

            return null;
        }
        $token = $tokens[$position];

        if ('(' === $token) {
            $close = $this->matchingParen($tokens, $position);
            $value = $this->evaluate(array_slice($tokens, $position + 1, $close - $position - 1), $innerOk);
            $position = $close + 1;
            $ok = $ok && $innerOk;

            return $value;
        }
        if ('-' === $token && isset($tokens[$position + 1]) && is_array($tokens[$position + 1]) && in_array($tokens[$position + 1][0], [T_LNUMBER, T_DNUMBER], true)) {
            $position++;
            $value = $this->term($tokens, $position, $ok);

            return -$value;
        }
        if (!is_array($token)) {
            $ok = false;

            return null;
        }

        $position++;
        switch ($token[0]) {
            case T_CONSTANT_ENCAPSED_STRING:
                return $this->stringLiteral($token[1]);
            case T_LNUMBER:
                return (int) $token[1];
            case T_DNUMBER:
                return (float) $token[1];
            case T_DIR:
                return dirname($this->file);
            case T_FILE:
                return $this->file;
            case T_STRING:
                $word = strtolower($token[1]);
                if ('true' === $word || 'false' === $word || 'null' === $word) {
                    return 'true' === $word ? true : ('false' === $word ? false : null);
                }
                if ('dirname' === $word && isset($tokens[$position]) && '(' === $tokens[$position]) {
                    $close = $this->matchingParen($tokens, $position);
                    $args = $this->splitArguments(array_slice($tokens, $position + 1, $close - $position - 1));
                    $position = $close + 1;
                    if ([] === $args) {
                        $ok = false;

                        return null;
                    }
                    $path = $this->evaluate($args[0], $okPath);
                    $levels = 1;
                    if (isset($args[1])) {
                        $levels = (int) $this->evaluate($args[1], $okLevels);
                        $okPath = $okPath && $okLevels;
                    }
                    if (!$okPath || !is_string($path) || $levels < 1) {
                        $ok = false;

                        return null;
                    }
                    for ($n = 0; $n < $levels; $n++) {
                        $path = dirname($path);
                    }

                    return $path;
                }
                $ok = false;

                return null;
            default:
                $ok = false;

                return null;
        }
    }

    private function stringLiteral(string $literal): string
    {
        $quote = $literal[0];
        $body = substr($literal, 1, -1);
        if ("'" === $quote) {
            return str_replace(["\\\\", "\\'"], ["\\", "'"], $body);
        }

        return stripcslashes($body);
    }
}
