<?php

namespace MxBackup\Core\Files;

final class FileRuleMatcher
{
    private $include;
    private $exclude;

    public function __construct(array $include, array $exclude)
    {
        $this->include = $include ?: ['*'];
        $this->exclude = $exclude;
    }

    public function includes($relativePath, $isDirectory = false)
    {
        $path = ltrim(str_replace('\\', '/', $relativePath), '/');
        if ($isDirectory) {
            $path = rtrim($path, '/') . '/';
        }
        foreach ($this->exclude as $pattern) {
            if ($this->matches($path, $pattern)) {
                return false;
            }
        }
        foreach ($this->include as $pattern) {
            if ($this->matches($path, $pattern)) {
                return true;
            }
        }
        return false;
    }

    public function excludesDirectory($relativePath)
    {
        $path = rtrim(str_replace('\\', '/', $relativePath), '/') . '/';
        foreach ($this->exclude as $pattern) {
            $normalized = ltrim(str_replace('\\', '/', (string)$pattern), '/');
            if ($normalized === $path || (substr($normalized, -1) === '/' && strpos($path, $normalized) === 0)) {
                return true;
            }
            if (fnmatch($normalized, $path, FNM_PATHNAME)) {
                return true;
            }
        }
        return false;
    }

    private function matches($path, $pattern)
    {
        $pattern = ltrim(str_replace('\\', '/', trim((string)$pattern)), '/');
        if ($pattern === '*' || $pattern === '**') {
            return true;
        }
        if (substr($pattern, -1) === '/') {
            return strpos($path, $pattern) === 0;
        }
        return $path === $pattern || fnmatch($pattern, $path, FNM_PATHNAME);
    }
}
