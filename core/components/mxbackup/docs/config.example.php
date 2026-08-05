<?php

return [
    'storage_path' => '/home/site/backups',
    'default_profile' => 'prod',
    'profiles' => [
        'prod' => [
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
