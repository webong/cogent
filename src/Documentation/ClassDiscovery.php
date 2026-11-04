<?php

declare(strict_types=1);

namespace Webong\Cogent\Documentation;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ClassDiscovery
{
    /**
     * @param  list<string>  $paths
     * @return list<class-string>
     */
    public function in(array $paths, callable $accepts): array
    {
        $classes = [];

        foreach ($paths as $path) {
            if (! is_dir($path)) {
                continue;
            }

            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));

            /** @var SplFileInfo $file */
            foreach ($files as $file) {
                $class = $file->getExtension() === 'php' ? $this->classIn($file->getPathname()) : null;

                if ($class !== null && class_exists($class) && $accepts($class)) {
                    $classes[] = $class;
                }
            }
        }

        sort($classes);

        return array_values(array_unique($classes));
    }

    private function classIn(string $file): ?string
    {
        $tokens = token_get_all((string) file_get_contents($file));
        $namespace = '';

        foreach ($tokens as $index => $token) {
            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = $this->nextName($tokens, $index);
            }

            if ($token[0] === T_CLASS && ! $this->isClassConstant($tokens, $index)) {
                $name = $this->nextName($tokens, $index);

                return $name === '' ? null : mb_ltrim($namespace.'\\'.$name, '\\');
            }
        }

        return null;
    }

    /** @param array<int, mixed> $tokens */
    private function nextName(array $tokens, int $index): string
    {
        for ($next = $index + 1, $count = count($tokens); $next < $count; $next++) {
            $token = $tokens[$next];

            if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED], true)) {
                return $token[1];
            }

            if ($token === ';' || $token === '{' || $token === '(') {
                return '';
            }
        }

        return '';
    }

    /** @param array<int, mixed> $tokens */
    private function isClassConstant(array $tokens, int $index): bool
    {
        for ($previous = $index - 1; $previous >= 0; $previous--) {
            $token = $tokens[$previous];

            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }

            return (is_array($token) && $token[0] === T_DOUBLE_COLON) || (is_array($token) && $token[0] === T_NEW);
        }

        return false;
    }
}
