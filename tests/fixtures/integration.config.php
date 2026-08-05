<?php

$storage = getenv('MXBACKUP_INTEGRATION_STORAGE');
if (!is_string($storage) || $storage === '') {
    throw new RuntimeException('MXBACKUP_INTEGRATION_STORAGE is required.');
}

return [
    'storage_path' => $storage,
    'profile' => 'dev',
    'mail' => ['enabled' => false],
    'profiles' => [
        'dev' => [
            'mode' => 'dev',
            'format' => 'tar.gz',
            'files' => [
                'include' => ['index.php'],
                'exclude' => [],
            ],
            'database' => [
                'include_tables' => [
                    '*_users',
                    '*_user_attributes',
                    '*_session',
                    '*_ms2_orders',
                    '*_ms2_order_addresses',
                    '*_ms2_customer_profiles',
                ],
                'exclude_tables' => [],
            ],
            'masking' => [
                'standard' => true,
                'rules' => [],
            ],
        ],
    ],
];
