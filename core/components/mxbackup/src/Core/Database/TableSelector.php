<?php

namespace MxBackup\Core\Database;

final class TableSelector
{
    public function select(array $tables, array $include, array $exclude)
    {
        $include = $include ?: ['*'];
        $selected = [];
        foreach ($tables as $table) {
            if (!$this->matchesAny($table, $include) || $this->matchesAny($table, $exclude)) {
                continue;
            }
            $selected[] = $table;
        }
        sort($selected, SORT_STRING);
        return $selected;
    }

    private function matchesAny($table, array $patterns)
    {
        foreach ($patterns as $pattern) {
            if (fnmatch((string)$pattern, $table)) {
                return true;
            }
        }
        return false;
    }
}
