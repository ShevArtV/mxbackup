<?php

namespace MxBackup\Tests;

use InvalidArgumentException;
use MxBackup\Core\Config\ProfileEditor;
use PHPUnit\Framework\TestCase;

final class ProfileEditorTest extends TestCase
{
    public function testTypedFieldsReplaceRawProfileJsonWithoutLosingTableSelection()
    {
        $editor = new ProfileEditor();
        $current = [
            'database' => ['include_tables' => ['modx_users'], 'exclude_tables' => []],
            'masking' => ['standard' => true, 'rules' => [['target' => 'modx_custom.email']]],
        ];
        $updated = $editor->update($current, [
            'mode' => 'dev', 'format' => 'zip',
            'file_include' => "*\nassets/uploads/",
            'file_exclude' => "core/cache/\n*.log\ncore/cache/",
            'standard_masking' => 0,
        ]);
        self::assertSame('zip', $updated['format']);
        self::assertSame(['*', 'assets/uploads/'], $updated['files']['include']);
        self::assertSame(['core/cache/', '*.log'], $updated['files']['exclude']);
        self::assertTrue($updated['masking']['standard'], 'Development masking cannot be disabled');
        self::assertSame(['modx_users'], $updated['database']['include_tables']);
        self::assertSame('modx_custom.email', $updated['masking']['rules'][0]['target']);
    }

    public function testAllExceptModeStoresOnlyExcludedTables()
    {
        $editor = new ProfileEditor();
        $config = $editor->applyTableSelection([], ['a','b','c'], ['a','c'], 'all_except');
        self::assertSame(['*'], $config['database']['include_tables']);
        self::assertSame(['b'], $config['database']['exclude_tables']);
        self::assertSame(['a','c'], $editor->selection($config, ['a','b','c']));
        self::assertSame('all_except', $editor->selectionMode($config));
    }

    public function testSelectedModeRejectsUnknownTablesAndCanSelectNone()
    {
        $editor = new ProfileEditor();
        try {
            $editor->applyTableSelection([], ['a'], ['missing'], 'selected');
            self::fail('Unknown table must be rejected');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('Неизвестные таблицы', $e->getMessage());
        }
        $empty = $editor->applyTableSelection([], ['a'], [], 'selected');
        self::assertSame([], $empty['database']['include_tables']);
        self::assertSame([], $editor->selection($empty, ['a']));
    }
}
