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
