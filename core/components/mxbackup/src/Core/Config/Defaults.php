<?php

namespace MxBackup\Core\Config;

final class Defaults
{
    /**
     * Секция удалённого хранилища — выключена.
     *
     * Пустой драйвер означает «архив остаётся на машине», то есть поведение,
     * которое было у пакета всегда. Обновление версии не должно включать
     * выгрузку куда бы то ни было само по себе.
     *
     * Ключи и секрет здесь пустые не для красоты: на инстансе AWS их вообще не
     * должно быть — доступ даёт роль машины, а пустые поля включают цепочку
     * поиска (профиль → окружение → роль).
     *
     * @return array<string, mixed>
     */
    public static function remoteTemplate()
    {
        return [
            'driver' => '',
            // Сколько копий держать на диске, когда выгрузка удалась.
            // Меньше одной нельзя: последняя остаётся всегда.
            'keep_local' => 2,
            // Глубина хранения в облаке. Ноль в обоих полях — не удалять ничего:
            // у бакета может быть своё правило истечения, и две ротации,
            // не знающие друг о друге, — худший вариант.
            'retention' => ['days' => 0, 'count' => 0],
            's3' => [
                'bucket' => '',
                'region' => '',
                'prefix' => '',
                // Непустой endpoint переключает на path-style: так работают
                // MinIO, Selectel, Yandex Object Storage и прочие совместимые.
                'endpoint' => '',
                'storage_class' => '',
                'access_key' => '',
                'secret_key' => '',
                'session_token' => '',
            ],
        ];
    }

    public static function values()
    {
        return [
            'storage_path' => '',
            'config_path' => '',
            'default_profile' => 'prod',
            'format' => 'tar.gz',
            'mail' => [
                'enabled' => false,
                'to' => '',
                'max_attachment_mb' => 10,
            ],
            'retention' => [
                'days' => 30,
                'count' => 10,
            ],
            'lock_ttl_minutes' => 720,
            'batch_size' => 500,
            'allow_web_storage' => false,
            'profiles' => [
                'prod' => [
                    'name' => 'prod',
                    'mode' => 'prod',
                    'format' => 'tar.gz',
                    'remote' => self::remoteTemplate(),
                    'encryption' => ['enabled' => false, 'password' => ''],
                    'files' => [
                        'include' => ['*'],
                        'exclude' => ['core/cache/', 'core/packages/', 'assets/cache/'],
                    ],
                    'database' => [
                        'include_tables' => ['*'],
                        'exclude_tables' => [],
                    ],
                    'masking' => ['standard' => false, 'rules' => []],
                ],
                'dev' => [
                    'name' => 'dev',
                    'mode' => 'dev',
                    'format' => 'tar.gz',
                    'remote' => self::remoteTemplate(),
                    'encryption' => ['enabled' => false, 'password' => ''],
                    'files' => [
                        'include' => ['*'],
                        'exclude' => [
                            'core/cache/',
                            'core/packages/',
                            'core/config/',
                            'assets/cache/',
                            'assets/uploads/private/',
                        ],
                    ],
                    'database' => [
                        'include_tables' => ['*'],
                        'exclude_tables' => [],
                    ],
                    'masking' => ['standard' => true, 'rules' => []],
                ],
            ],
        ];
    }
}
