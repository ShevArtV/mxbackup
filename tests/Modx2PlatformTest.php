<?php

// Фейковый modX объявлен в tests/Fake/modx.php и подключается из bootstrap.

namespace MxBackup\Tests {
    use MxBackup\Platform\Modx2\Modx2Platform;
    use PHPUnit\Framework\TestCase;

    final class Modx2PlatformTest extends TestCase
    {
        public function testUsesMxLoggerAsPrimaryBackend()
        {
            $modx = new \modX();
            $modx->mxlogger = new class {
                public $calls = [];
                public function log($tags, $level, $message, array $context, array $options)
                {
                    $this->calls[] = compact('tags', 'level', 'message', 'context', 'options');
                }
            };

            (new Modx2Platform($modx))->log('error', 'Backup failed', ['run_id' => 42]);

            self::assertCount(1, $modx->mxlogger->calls);
            self::assertSame(['mxbackup', 'backup'], $modx->mxlogger->calls[0]['tags']);
            self::assertSame('mxbackup_42', $modx->mxlogger->calls[0]['options']['process_uid']);
            self::assertSame([Modx2Platform::class], $modx->mxlogger->calls[0]['options']['skip_classes']);
            self::assertSame([], $modx->logs);
        }

        public function testFallsBackToStandardModxLogWhenMxLoggerIsUnavailable()
        {
            $modx = new \modX();
            $modx->mxlogger = null;
            $modx->service = null;

            (new Modx2Platform($modx))->log('warning', 'Fallback event', ['profile' => 'prod']);

            self::assertCount(1, $modx->logs);
            self::assertSame(\modX::LOG_LEVEL_WARN, $modx->logs[0]['level']);
            self::assertStringContainsString('[mxbackup] Fallback event', $modx->logs[0]['message']);
            self::assertStringContainsString('"profile":"prod"', $modx->logs[0]['message']);
        }

        public function testFallsBackWhenMxLoggerThrows()
        {
            $modx = new \modX();
            $modx->mxlogger = new class {
                public function log($tags, $level, $message, array $context, array $options)
                {
                    throw new \RuntimeException('logger storage failed');
                }
            };

            (new Modx2Platform($modx))->log('error', 'Original event');

            self::assertCount(1, $modx->logs);
            self::assertSame(\modX::LOG_LEVEL_ERROR, $modx->logs[0]['level']);
            self::assertStringContainsString('Original event', $modx->logs[0]['message']);
        }
    }
}
