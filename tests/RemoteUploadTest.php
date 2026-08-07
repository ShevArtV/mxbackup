<?php

namespace MxBackup\Tests;

use MxBackup\Core\BackupRunner;
use MxBackup\Core\Config\ConfigResolver;
use MxBackup\Core\Config\Defaults;
use MxBackup\Tests\Fake\FakeDatabase;
use MxBackup\Tests\Fake\FakePlatform;
use MxBackup\Tests\Fake\FakeRemoteStorage;
use PHPUnit\Framework\TestCase;

/**
 * Выгрузка архива в удалённое хранилище как часть прогона.
 *
 * Проверяется главное свойство конструкции: копия важнее выгрузки. Недоступное
 * хранилище не должно ни ронять прогон, ни приводить к удалению локальных копий
 * — иначе неудачная сеть стоила бы сайту резервных копий.
 */
final class RemoteUploadTest extends TestCase
{
    /** @var string */
    private $base;

    /** @var string */
    private $site;

    /** @var string */
    private $storage;

    protected function setUp(): void
    {
        if (!class_exists('ZipArchive')) {
            self::markTestSkipped('ext-zip missing');
        }
        $this->base = sys_get_temp_dir() . '/mxb-remote-' . bin2hex(random_bytes(5));
        $this->site = $this->base . '/site';
        $this->storage = $this->base . '/backups';
        mkdir($this->site, 0700, true);
        mkdir($this->storage, 0700, true);
        file_put_contents($this->site . '/index.php', '<?php echo "ok";');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storage . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @unlink($this->site . '/index.php');
        @rmdir($this->storage);
        @rmdir($this->site);
        @rmdir($this->base);
    }

    private function runBackup(FakeRemoteStorage $remote, array $remoteConfig = [])
    {
        $platform = new FakePlatform($this->site, new FakeDatabase(['modx_site_content' => [['id' => 1]]]));
        $config = (new ConfigResolver())->resolve(Defaults::values(), [], [], [
            'storage_path' => $this->storage,
            'format' => 'zip',
            'profile' => 'prod',
            'profiles' => [
                'prod' => ['remote' => array_merge([
                    'driver' => 's3',
                    'keep_local' => 1,
                    // Бакет и регион обязательны по валидатору, хотя сам драйвер
                    // здесь подменён: конфигурация проверяется целиком, до прогона.
                    's3' => ['bucket' => 'test-bucket', 'region' => 'eu-central-1'],
                ], $remoteConfig)],
            ],
        ], []);

        $runner = new class ($platform, $remote) extends BackupRunner {
            /** @var FakeRemoteStorage */
            private $remote;

            public function __construct($platform, FakeRemoteStorage $remote)
            {
                parent::__construct($platform);
                $this->remote = $remote;
            }

            protected function createRemoteStorage(array $profile)
            {
                $driver = isset($profile['remote']['driver']) ? $profile['remote']['driver'] : '';

                return $driver === '' ? null : $this->remote;
            }
        };

        return [$runner->run($config, false), $platform];
    }

    private function archives()
    {
        return array_values(array_filter(glob($this->storage . '/mxbackup-*.zip') ?: [], 'is_file'));
    }

    public function testArchiveIsUploadedAndReported()
    {
        $remote = new FakeRemoteStorage();
        [$result] = $this->runBackup($remote);

        self::assertTrue($result->isSuccess());
        self::assertCount(1, $remote->objects);

        $report = $result->getReport()['stats']['remote'];
        self::assertSame('ok', $report['status']);
        self::assertSame('fake://storage/', $report['storage']);

        // Сумма архива уходит меткой объекта: по ней потом видно, что скачали
        // именно то, что паковали, не разворачивая архив.
        $name = array_key_first($remote->objects);
        self::assertSame(
            $result->getReport()['stats']['archive_checksum'],
            $remote->objects[$name]['metadata']['sha256']
        );
    }

    /**
     * Недоступное хранилище — предупреждение, а не провал: копия снята и лежит
     * на диске, то есть работа сделана.
     */
    public function testUploadFailureDoesNotFailTheRun()
    {
        $remote = new FakeRemoteStorage();
        $remote->failUpload = true;
        [$result, $platform] = $this->runBackup($remote);

        self::assertTrue($result->isSuccess());
        self::assertSame('warning', $result->getReport()['status']);
        self::assertSame('error', $result->getReport()['stats']['remote']['status']);
        self::assertContains('warning', array_column($platform->logs, 'level'));
    }

    /**
     * И, что важнее, при неудаче выгрузки локальные копии не режутся по
     * keep_local: удалять с диска то, что никуда не уехало, нельзя.
     */
    public function testLocalCopiesSurviveFailedUpload()
    {
        $older = $this->storage . '/mxbackup-prod-20260101-000000.zip';
        file_put_contents($older, 'старая копия');
        touch($older, time() - 86400);

        $remote = new FakeRemoteStorage();
        $remote->failUpload = true;
        $this->runBackup($remote, ['keep_local' => 1]);

        self::assertFileExists($older);
        self::assertCount(2, $this->archives());
    }

    /**
     * При удавшейся выгрузке локальная глубина считается по keep_local, а не по
     * общему retention: копии уже в облаке, и диск ими забивать незачем.
     */
    public function testKeepLocalTrimsDiskAfterSuccessfulUpload()
    {
        foreach ([3, 2, 1] as $index) {
            $path = $this->storage . '/mxbackup-prod-2026010' . $index . '-000000.zip';
            file_put_contents($path, 'копия ' . $index);
            touch($path, time() - ($index * 3600));
        }

        $remote = new FakeRemoteStorage();
        $this->runBackup($remote, ['keep_local' => 1]);

        // Осталась только что созданная копия — самая свежая.
        self::assertCount(1, $this->archives());
    }

    /**
     * «Не оставлять на диске ничего» — не настройка, а ошибка: архив всё равно
     * создаётся локально, и удалять его сразу значит остаться без копии, когда
     * облако недоступно. Валидатор отвергает такое до начала работы, а не после
     * получаса упаковки.
     */
    public function testZeroKeepLocalIsRejectedBeforeAnythingRuns()
    {
        $remote = new FakeRemoteStorage();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('remote.keep_local: минимум 1');

        $this->runBackup($remote, ['keep_local' => 0]);
    }

    public function testRemoteRetentionRunsAfterUpload()
    {
        $remote = new FakeRemoteStorage();
        $remote->seed('mxbackup-prod-20250101-000000.zip', time() - (400 * 86400));
        $remote->seed('mxbackup-prod-20250102-000000.zip', time() - (399 * 86400));

        [$result] = $this->runBackup($remote, ['retention' => ['days' => 30, 'count' => 1]]);

        // Свежий архив только что выгружен и защищён count = 1, оба старых ушли.
        self::assertCount(2, $result->getReport()['stats']['remote']['deleted']);
        self::assertCount(1, $remote->objects);
    }

    /**
     * Выключенная выгрузка — исходное поведение пакета: ничего никуда не едет,
     * в отчёте секции нет вовсе.
     */
    public function testDisabledRemoteChangesNothing()
    {
        $remote = new FakeRemoteStorage();
        [$result] = $this->runBackup($remote, ['driver' => '']);

        self::assertSame([], $remote->objects);
        self::assertArrayNotHasKey('remote', $result->getReport()['stats']);
    }
}
