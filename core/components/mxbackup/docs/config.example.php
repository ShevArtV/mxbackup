<?php

return [
    'storage_path' => '/home/site/backups',
    'default_profile' => 'prod',
    'profiles' => [
        'prod' => [
            'mode' => 'prod',
            'format' => 'tar.gz',
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
            'mode' => 'dev',
            'format' => 'zip',
            'encryption' => [
                'enabled' => true,
                'password' => 'replace-with-a-long-random-password',
            ],
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
