<?php

namespace MxBackup\Core\Config;

final class Defaults
{
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
