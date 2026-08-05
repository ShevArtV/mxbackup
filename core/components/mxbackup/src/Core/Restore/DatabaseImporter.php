<?php

namespace MxBackup\Core\Restore;

use MxBackup\Core\Contract\DatabaseAdapterInterface;
use RuntimeException;

final class DatabaseImporter
{
    private $database;

    public function __construct(DatabaseAdapterInterface $database)
    {
        $this->database = $database;
    }

    public function import($sqlPath)
    {
        $handle = fopen((string)$sqlPath, 'rb');
        if (!$handle) {
            throw new RuntimeException('Не удалось открыть database.sql.');
        }
        $buffer = '';
        $statements = 0;
        try {
            while (($chunk = fread($handle, 1048576)) !== false && $chunk !== '') {
                $buffer .= $chunk;
                foreach ($this->splitCompleteStatements($buffer) as $statement) {
                    if (!$this->isIgnorable($statement)) {
                        $this->assertAllowedStatement($statement);
                        $this->database->executeRestoreStatement($statement);
                        $statements++;
                    }
                }
            }
            if (!feof($handle)) {
                throw new RuntimeException('Ошибка чтения database.sql.');
            }
            if (trim($this->stripLeadingComments($buffer)) !== '') {
                throw new RuntimeException('database.sql обрывается до завершения SQL-выражения.');
            }
        } finally {
            fclose($handle);
        }
        return ['statements' => $statements, 'bytes' => filesize($sqlPath) ?: 0];
    }

    private function splitCompleteStatements(&$buffer)
    {
        $result = [];
        $length = strlen($buffer);
        $quote = null;
        $lineComment = false;
        $blockComment = false;
        $escaped = false;
        $start = 0;
        for ($i = 0; $i < $length; $i++) {
            $char = $buffer[$i];
            $next = $i + 1 < $length ? $buffer[$i + 1] : '';
            if ($lineComment) {
                if ($char === "\n") $lineComment = false;
                continue;
            }
            if ($blockComment) {
                if ($char === '*' && $next === '/') { $blockComment = false; $i++; }
                continue;
            }
            if ($quote !== null) {
                if ($escaped) { $escaped = false; continue; }
                if ($char === '\\' && $quote !== '`') { $escaped = true; continue; }
                if ($char === $quote) {
                    if ($next === $quote && $quote !== '`') { $i++; continue; }
                    $quote = null;
                }
                continue;
            }
            if (($char === '-' && $next === '-' && ($i + 2 >= $length || ctype_space($buffer[$i + 2]))) || $char === '#') {
                $lineComment = true;
                if ($char === '-') $i++;
                continue;
            }
            if ($char === '/' && $next === '*') { $blockComment = true; $i++; continue; }
            if ($char === "'" || $char === '"' || $char === '`') { $quote = $char; continue; }
            if ($char === ';') {
                $result[] = substr($buffer, $start, $i - $start + 1);
                $start = $i + 1;
            }
        }
        $buffer = substr($buffer, $start);
        return $result;
    }

    private function isIgnorable($statement)
    {
        return trim($this->stripLeadingComments($statement), " \t\r\n;") === '';
    }

    private function stripLeadingComments($sql)
    {
        do {
            $before = $sql;
            $sql = preg_replace('/^\s*(?:--[^\r\n]*(?:\r?\n|$)|#[^\r\n]*(?:\r?\n|$)|\/\*.*?\*\/)/s', '', $sql);
        } while ($sql !== $before);
        return $sql;
    }

    private function assertAllowedStatement($statement)
    {
        $sql = ltrim($this->stripLeadingComments($statement));
        $identifier = '`[a-zA-Z0-9_$]+`';
        $allowed = [
            '/^SET\s+NAMES\s+utf8mb4\s*;/i',
            '/^SET\s+FOREIGN_KEY_CHECKS\s*=\s*[01]\s*;/i',
            '/^DROP\s+TABLE\s+IF\s+EXISTS\s+' . $identifier . '\s*;/i',
            '/^CREATE\s+TABLE\s+' . $identifier . '\s*\(/i',
            '/^INSERT\s+INTO\s+' . $identifier . '\s*\(/i',
        ];
        foreach ($allowed as $pattern) {
            if (preg_match($pattern, $sql)) return;
        }
        throw new RuntimeException('database.sql содержит выражение, которое mxBackup не создаёт: '
            . substr(preg_replace('/\s+/', ' ', $sql), 0, 80));
    }
}
