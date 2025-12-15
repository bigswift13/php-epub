<?php

declare(strict_types=1);

namespace bigswift13\Epub;

class Util
{
    /**
     * Concatenate two directory paths, resolving relative paths (../ and ./).
     */
    public static function directoryConcat(string $base, string $relativeFile, bool $baseIsFile = false): string
    {
        // If base is a file, remove the filename to get the directory
        if ($baseIsFile) {
            $base = dirname($base);
            if ($base === '.') {
                $base = '';
            }
        }

        // Normalize separators
        $base = str_replace('\\', '/', $base);
        $relativeFile = str_replace('\\', '/', $relativeFile);

        $baseParts = $base === '' ? [] : explode('/', $base);
        $relativeParts = explode('/', $relativeFile);

        $parts = array_merge($baseParts, $relativeParts);
        $stack = [];

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                if (!empty($stack)) {
                    array_pop($stack);
                } else {
                    // Start of path with '..' implies going up from root, which we preserve
                    // strictly based on logic, but usually in epub context implies root.
                    // Keeping original logic behavior mostly, but safer.
                    $stack[] = '..';
                }
            } else {
                $stack[] = $part;
            }
        }

        return implode('/', $stack);
    }
}
