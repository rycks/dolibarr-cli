<?php

declare(strict_types=1);

/**
 * Concatenation of the dolicli server sources into one PHP file.
 */
final class ServerBuilder
{
    public const VERSION_LINE = "const VERSION = 'dev';";

    public const HASH_LINE = "const BODY_SHA256 = '';";

    public const HEADER = <<<'PHP'
        <?php
        /*
         * dolicli - Dolibarr administration from the command line (single-file body).
         *
         * Copyright (c) 2025-2026 Eric Seigne <eric.seigne@cap-rel.fr>
         * SPDX-License-Identifier: AGPL-3.0-or-later
         *
         * Built from the sources of server/ by build/build-server.php: do not edit.
         * Run it through the launcher (dolicli), or send it on the standard input of
         * PHP with the arguments in $DOLICLI_ARGV (ephemeral mode).
         */

        PHP;

    /** @var array<string, array{file: string, namespace: string, deps: list<string>, code: string}> */
    private array $classes = [];

    public function __construct(private readonly string $serverDir)
    {
    }

    public function classCount(): int
    {
        return count($this->classes);
    }

    public function build(string $version): string
    {
        $this->classes = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->serverDir.'/src', FilesystemIterator::SKIP_DOTS));
        $files = [];
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && 'php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        foreach ($files as $file) {
            $this->addFile($file);
        }

        $body = self::HEADER;
        foreach ($this->sorted() as $class) {
            $body .= $this->classes[$class]['code']."\n";
        }
        $main = (string) file_get_contents($this->serverDir.'/main.php');
        $body .= self::stripOpenTag($main);

        if (1 !== mb_substr_count($body, self::VERSION_LINE) || 1 !== mb_substr_count($body, self::HASH_LINE)) {
            throw new RuntimeException('Version constants not found exactly once in the sources');
        }
        $body = str_replace(self::VERSION_LINE, 'const VERSION = '.var_export($version, true).';', $body);
        $hash = hash('sha256', $body);

        return str_replace(self::HASH_LINE, "const BODY_SHA256 = '{$hash}';", $body);
    }

    /**
     * SHA256 of a built body, as injected in it (computed with the hash blanked).
     */
    public static function bodyHash(string $body): string
    {
        return hash('sha256', (string) preg_replace("/const BODY_SHA256 = '[0-9a-f]{64}';/", self::HASH_LINE, $body, 1));
    }

    /**
     * Constructions forbidden in the body (contract 4.2).
     *
     * @return list<string>
     */
    public static function check(string $body): array
    {
        $problems = [];
        $tokens = token_get_all($body);
        $openTags = 0;
        $line = 1;
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if ( ! is_array($token)) {
                continue;
            }
            $line = $token[2];
            switch ($token[0]) {
                case T_OPEN_TAG:
                case T_OPEN_TAG_WITH_ECHO:
                    $openTags++;
                    break;
                case T_CLOSE_TAG:
                    $problems[] = "line {$line}: closing tag";
                    break;
                case T_INLINE_HTML:
                    $problems[] = "line {$line}: text outside PHP";
                    break;
                case T_DIR:
                case T_FILE:
                    $problems[] = "line {$line}: {$token[1]} (undefined in the ephemeral mode)";
                    break;
                case T_REQUIRE:
                case T_REQUIRE_ONCE:
                case T_INCLUDE:
                case T_INCLUDE_ONCE:
                    $next = self::nextMeaningful($tokens, $i);
                    if (is_array($next) && T_CONSTANT_ENCAPSED_STRING === $next[0]) {
                        $problems[] = "line {$line}: {$token[1]} of a literal path {$next[1]} (the body must not depend on another file)";
                    }
                    break;
                case T_DECLARE:
                    $problems[] = "line {$line}: declare() (the body has no strict_types)";
                    break;
            }
        }
        if (1 !== $openTags) {
            $problems[] = "{$openTags} PHP opening tags, expected 1";
        }

        return $problems;
    }

    /**
     * @param array<int, mixed> $tokens
     * @return mixed
     */
    private static function nextMeaningful(array $tokens, int $index): mixed
    {
        $count = count($tokens);
        for ($j = $index + 1; $j < $count; $j++) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if ('(' === $tokens[$j]) {
                continue;
            }

            return $tokens[$j];
        }

        return null;
    }

    private static function stripOpenTag(string $code): string
    {
        $code = ltrim($code);
        if ( ! str_starts_with($code, '<?php')) {
            throw new RuntimeException('Source without <?php opening tag');
        }

        return ltrim(mb_substr($code, 5), "\n");
    }

    private function addFile(string $file): void
    {
        $code = (string) file_get_contents($file);
        if (str_contains($code, '?>')) {
            $tokens = token_get_all($code);
            foreach ($tokens as $token) {
                if (is_array($token) && T_CLOSE_TAG === $token[0]) {
                    throw new RuntimeException("{$file}: closing tag ?> is forbidden");
                }
            }
        }
        $tokens = token_get_all($code);
        $namespace = '';
        $uses = [];
        $declared = null;
        $deps = [];
        $count = count($tokens);
        $namespaceEnd = -1;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if ( ! is_array($token)) {
                continue;
            }
            if (T_NAMESPACE === $token[0]) {
                $name = '';
                for ($j = $i + 1; $j < $count && ';' !== $tokens[$j] && '{' !== $tokens[$j]; $j++) {
                    if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                        $name .= $tokens[$j][1];
                    }
                }
                if ('{' === ($tokens[$j] ?? null)) {
                    throw new RuntimeException("{$file}: write 'namespace X;', the build adds the braces");
                }
                $namespace = $name;
                $namespaceEnd = $j;
                continue;
            }
            if (T_USE === $token[0] && null === $declared) {
                $name = '';
                $alias = '';
                $asSeen = false;
                for ($j = $i + 1; $j < $count && ';' !== $tokens[$j]; $j++) {
                    if ( ! is_array($tokens[$j])) {
                        continue;
                    }
                    if (T_AS === $tokens[$j][0]) {
                        $asSeen = true;
                    } elseif (in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR], true)) {
                        if ($asSeen) {
                            $alias = $tokens[$j][1];
                        } else {
                            $name .= $tokens[$j][1];
                        }
                    }
                }
                $name = ltrim($name, '\\');
                $short = '' !== $alias ? $alias : mb_substr($name, (int) mb_strrpos('\\'.$name, '\\'));
                $uses[mb_strtolower($short)] = $name;
                $i = $j;
                continue;
            }
            if (in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT], true) && null === $declared) {
                $previous = $this->previousMeaningful($tokens, $i);
                if (is_array($previous) && T_DOUBLE_COLON === $previous[0]) {
                    continue;
                }
                $nameToken = $this->nextToken($tokens, $i, [T_STRING]);
                if (null === $nameToken) {
                    continue;
                }
                $declared = ('' !== $namespace ? $namespace.'\\' : '').$nameToken[1];
                // extends / implements up to the opening brace
                $mode = '';
                for ($j = $i + 1; $j < $count && '{' !== $tokens[$j]; $j++) {
                    if ( ! is_array($tokens[$j])) {
                        continue;
                    }
                    if (T_EXTENDS === $tokens[$j][0] || T_IMPLEMENTS === $tokens[$j][0]) {
                        $mode = 'dep';
                    } elseif ('dep' === $mode && in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                        $deps[] = $this->resolve($tokens[$j][1], $namespace, $uses);
                    }
                }
            }
        }

        if (null === $declared) {
            throw new RuntimeException("{$file}: no class, interface or trait declared");
        }
        if ('' === $namespace || $namespaceEnd < 0) {
            throw new RuntimeException("{$file}: no namespace");
        }

        // Rebuild the code: everything after "namespace X;" goes inside "namespace X { ... }".
        $after = '';
        for ($i = $namespaceEnd + 1; $i < $count; $i++) {
            $after .= is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
        }
        $code = 'namespace '.$namespace." {\n".rtrim($after)."\n}\n";

        if (isset($this->classes[$declared])) {
            throw new RuntimeException("{$declared} declared twice");
        }
        $this->classes[$declared] = ['file' => $file, 'namespace' => $namespace, 'deps' => $deps, 'code' => $code];
    }

    /**
     * @param array<string, string> $uses
     */
    private function resolve(string $name, string $namespace, array $uses): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }
        $parts = explode('\\', $name);
        $first = mb_strtolower($parts[0]);
        if (isset($uses[$first])) {
            $parts[0] = $uses[$first];

            return implode('\\', $parts);
        }

        return $namespace.'\\'.$name;
    }

    /**
     * @param array<int, mixed> $tokens
     * @param list<int> $types
     * @return array{0: int, 1: string, 2: int}|null
     */
    private function nextToken(array $tokens, int $index, array $types): ?array
    {
        $count = count($tokens);
        for ($j = $index + 1; $j < $count; $j++) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], $types, true)) {
                return $tokens[$j];
            }
            if ( ! is_array($tokens[$j]) || T_WHITESPACE !== $tokens[$j][0]) {
                return null;
            }
        }

        return null;
    }

    /**
     * @param array<int, mixed> $tokens
     */
    private function previousMeaningful(array $tokens, int $index): mixed
    {
        for ($j = $index - 1; $j >= 0; $j--) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $tokens[$j];
        }

        return null;
    }

    /**
     * Classes ordered so that every parent and interface comes before its users.
     *
     * @return list<string>
     */
    private function sorted(): array
    {
        $sorted = [];
        $state = [];
        $visit = function (string $class, array $path) use (&$visit, &$sorted, &$state): void {
            if ('done' === ($state[$class] ?? '')) {
                return;
            }
            if ('visiting' === ($state[$class] ?? '')) {
                throw new RuntimeException('Inheritance cycle: '.implode(' -> ', [...$path, $class]));
            }
            $state[$class] = 'visiting';
            foreach ($this->classes[$class]['deps'] as $dep) {
                if (isset($this->classes[$dep])) {
                    $visit($dep, [...$path, $class]);
                } elseif (str_starts_with($dep, 'Dolicli\\')) {
                    throw new RuntimeException("{$class} depends on {$dep}, which is not in server/src");
                }
            }
            $state[$class] = 'done';
            $sorted[] = $class;
        };
        foreach (array_keys($this->classes) as $class) {
            $visit($class, []);
        }

        return $sorted;
    }
}
