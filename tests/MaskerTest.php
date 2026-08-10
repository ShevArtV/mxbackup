<?php

namespace MxBackup\Tests;

use MxBackup\Core\Masking\Masker;
use MxBackup\Core\Masking\StandardRules;
use MxBackup\Core\Masking\RuleFactory;
use PHPUnit\Framework\TestCase;

final class MaskerTest extends TestCase
{
    public function testMasksModxProfileDeterministically()
    {
        $masker = new Masker(StandardRules::rules(), null, StandardRules::requiredColumns());
        $row = ['internalKey' => 7, 'fullname' => 'Иван', 'email' => 'ivan@example.com', 'phone' => '+79990000000', 'mobilephone' => '', 'address' => 'Secret', 'city' => 'Moscow', 'zip' => '123', 'comment' => 'private', 'extended' => '{"token":"secret"}'];
        $meta = array_fill_keys(array_keys($row), ['Null' => 'YES']);
        $first = $masker->maskRow('modx_user_attributes', $row, $meta);
        $second = $masker->maskRow('modx_user_attributes', $row, $meta);
        self::assertSame($first, $second);
        self::assertStringEndsWith('@example.test', $first['email']);
        self::assertNotSame('Иван', $first['fullname']);
        self::assertStringNotContainsString('secret', $first['extended']);
        self::assertNull($first['comment']);
    }

    public function testSessionsAreTruncated()
    {
        $masker = new Masker(StandardRules::rules());
        self::assertTrue($masker->shouldTruncate('modx_session'));
    }

    public function testCustomJsonPathChangesOnlySelectedPath()
    {
        $rules = (new RuleFactory())->createMany([[
            'target_type' => 'json_path', 'target' => 'modx_orders.properties.payment.token',
            'action' => 'hide', 'priority' => 10, 'active' => 1,
        ]]);
        $row = ['id' => 1, 'properties' => '{"payment":{"token":"secret","method":"card"}}'];
        $masked = (new Masker($rules))->maskRow('modx_orders', $row);
        self::assertSame(['payment' => ['token' => null, 'method' => 'card']], json_decode($masked['properties'], true));
    }

    public function testSecretSettingsAreClearedAndOrdinaryOnesSurvive()
    {
        $masker = new Masker(StandardRules::rules(), null, StandardRules::requiredColumns());
        $meta = ['key' => ['Null' => 'NO'], 'value' => ['Null' => 'NO']];

        $secret = $masker->maskRow('modx_system_settings', ['key' => 'mail_smtp_pass', 'value' => 'p@ssw0rd'], $meta);
        self::assertSame('', $secret['value']);

        $token = $masker->maskRow('modx_system_settings', ['key' => 'MS_STRIPE_SECRET_KEY', 'value' => 'sk_live_123'], $meta);
        self::assertSame('', $token['value'], 'имя настройки сравнивается без учёта регистра');

        $ordinary = $masker->maskRow('modx_system_settings', ['key' => 'site_name', 'value' => 'Мой сайт'], $meta);
        self::assertSame('Мой сайт', $ordinary['value']);

        $cacheKey = $masker->maskRow('modx_system_settings', ['key' => 'cache_resource_key', 'value' => 'resource'], $meta);
        self::assertSame('resource', $cacheKey['value'], 'общего шаблона *key* быть не должно');

        $context = $masker->maskRow('modx_context_setting', ['key' => 'gr_api_key', 'value' => 'abc'], $meta);
        self::assertSame('', $context['value']);
    }

    /**
     * Имена таблиц настроек в MODX разнобойные: `system_settings` и
     * `user_settings` во множественном числе, `context_setting` в
     * единственном. Сравнение точное (`fnmatch`), поэтому опечатка в одну
     * букву означает, что таблица не обрабатывалась вовсе.
     *
     * @dataProvider settingsTables
     */
    public function testEverySettingsTableIsCovered($table)
    {
        $masker = new Masker(StandardRules::rules(), null, StandardRules::requiredColumns());
        $meta = ['key' => ['Null' => 'NO'], 'value' => ['Null' => 'NO']];
        $row = $masker->maskRow($table, ['key' => 'stripe_secret_key', 'value' => 'sk_live_1'], $meta);
        self::assertSame('', $row['value'], $table . ' — переопределение настройки, секрет уедет в копию');
    }

    public function settingsTables()
    {
        return [
            ['modx_system_settings'],
            ['modx_context_setting'],
            ['modx_user_settings'],
            ['modx_user_group_settings'],
        ];
    }

    /**
     * @dataProvider settingsNamedLikeSecretsButHarmless
     */
    public function testSettingsNamedLikeSecretsKeepTheirValues($key, $value)
    {
        $masker = new Masker(StandardRules::rules(), null, StandardRules::requiredColumns());
        $meta = ['key' => ['Null' => 'NO'], 'value' => ['Null' => 'NO']];
        $row = $masker->maskRow('modx_system_settings', ['key' => $key, 'value' => $value], $meta);
        self::assertSame($value, $row['value'], $key . ' — политика или тайминг, а не доступ');
    }

    public function settingsNamedLikeSecretsButHarmless()
    {
        return [
            ['password_min_length', '8'],
            ['password_generated_length', '12'],
            ['allow_manager_login_forgot_password', '1'],
            ['mxapi.token_ttl', '3600'],
            ['session_cookie_lifetime', '604800'],
        ];
    }

    public function testConditionOfExclusionsOnlyMatchesEverythingElse()
    {
        $rules = (new RuleFactory())->createMany([[
            'target_type' => 'column', 'target' => 'modx_notes.body',
            'action' => 'hide', 'when' => ['author' => ['!system']],
        ]]);
        $masker = new Masker($rules);
        $kept = $masker->maskRow('modx_notes', ['author' => 'system', 'body' => 'служебное']);
        self::assertSame('служебное', $kept['body']);
        $cleared = $masker->maskRow('modx_notes', ['author' => 'ivan', 'body' => 'личное']);
        self::assertSame('', $cleared['body']);
    }

    public function testConditionalRuleIsSkippedWhenConditionColumnIsAbsent()
    {
        $rules = (new RuleFactory())->createMany([[
            'target_type' => 'column', 'target' => 'modx_settings.value',
            'action' => 'hide', 'when' => ['key' => ['*secret*']],
        ]]);
        $masked = (new Masker($rules))->maskRow('modx_settings', ['value' => 'нетронуто']);
        self::assertSame('нетронуто', $masked['value']);
    }

    public function testDryRunMarksConditionalColumnsSeparately()
    {
        $masker = new Masker(StandardRules::rules(), null, StandardRules::requiredColumns());
        $plan = $masker->planTable('modx_system_settings', ['key' => [], 'value' => []]);
        self::assertSame('hide', $plan['columns']['value']);
        self::assertArrayHasKey('value', $plan['conditional_columns']);
        self::assertContains('*secret*', $plan['conditional_columns']['value']['key']);
    }

    public function testSchemaGuardIgnoresUnrelatedTableWithUsersSuffix()
    {
        $masker = new Masker(StandardRules::rules(), null, StandardRules::requiredColumns());
        $masker->validateTable('modx_active_users', ['id' => [], 'internalKey' => [], 'username' => [], 'last_action' => []]);
        self::assertTrue(true);
    }

    public function testSchemaGuardFailsForRecognizedSensitiveTable()
    {
        $masker = new Masker(StandardRules::rules(), null, StandardRules::requiredColumns());
        $this->expectException(\RuntimeException::class);
        $masker->validateTable('modx_users', ['id' => [], 'username' => []]);
    }

    public function testDryRunPlanShowsMaskedColumnsAndTruncatedTables()
    {
        $masker = new Masker(StandardRules::rules(), null, StandardRules::requiredColumns());
        $profileMeta = array_fill_keys(
            ['internalKey','fullname','email','phone','mobilephone','address','city','zip','comment','extended'],
            ['Null' => 'YES']
        );
        $profilePlan = $masker->planTable('modx_user_attributes', $profileMeta);
        self::assertFalse($profilePlan['truncated']);
        self::assertSame('mask', $profilePlan['columns']['email']);
        self::assertSame('hide', $profilePlan['columns']['comment']);

        $sessionPlan = $masker->planTable('modx_session', ['id'=>[], 'data'=>[]]);
        self::assertTrue($sessionPlan['truncated']);
        self::assertSame([], $sessionPlan['columns']);
    }
}
