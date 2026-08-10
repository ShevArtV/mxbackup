<?php

namespace MxBackup\Core\Masking;

final class StandardRules
{
    /**
     * Имена настроек, за которыми прячется доступ к чужой системе.
     *
     * Сравнение идёт по имени ключа, а не по имени колонки: у настроек MODX
     * значение всегда лежит в `value`, и отличить пароль SMTP от размера
     * миниатюры можно только по соседней колонке `key`. Список намеренно узкий
     * — общий `*key*` задел бы безобидные настройки вроде `cache_...`.
     *
     * Шаблоны с `!` вычитают настройки, которые лишь названы похоже:
     * `password_min_length`, `password_generated_length`,
     * `allow_manager_login_forgot_password`, `*.token_ttl` — это политика и
     * тайминги, а не доступы, и в копии они должны остаться рабочими.
     */
    public static function secretSettingPatterns()
    {
        return [
            '*password*',
            '*passwd*',
            '*_pass',
            '*secret*',
            '*token*',
            '*api_key*',
            '*apikey*',
            '*private_key*',
            '*license_key*',
            '*access_key*',
            '*auth_key*',
            '*_credentials',

            '!*_length',
            '!*_ttl',
            '!*_lifetime',
            '!*_expires',
            '!*_min_*',
            '!*_max_*',
            '!allow_*',
            '!*_enabled',
        ];
    }

    public static function requiredColumns()
    {
        return [
            '*_users' => [
                'anchors_any' => ['username', 'password'],
                'exclude' => ['*_active_users'],
                'required' => ['id', 'username', 'password'],
            ],
            '*_user_attributes' => [
                'anchors_any' => ['internalKey'],
                'required' => ['internalKey', 'fullname', 'email', 'phone', 'mobilephone', 'address', 'city', 'zip', 'comment', 'extended'],
            ],
            '*_session' => [
                'anchors_any' => ['data'],
                'required' => ['id', 'data'],
            ],
            '*_ms2_orders' => [
                'anchors_any' => ['session_id', 'order_comment'],
                'required' => ['id', 'session_id', 'order_comment', 'properties'],
            ],
            '*_ms2_order_addresses' => [
                'anchors_any' => ['receiver', 'email'],
                'required' => ['id', 'receiver', 'phone', 'email', 'city', 'street', 'comment', 'properties'],
            ],
            '*_ms2_customer_profiles' => [
                'anchors_any' => ['account', 'spent'],
                'required' => ['id', 'account', 'spent', 'referrer_code'],
            ],
        ];
    }

    public static function rules()
    {
        $rules = [
            new Rule('*_session', '*', 'truncate', null, -1000),

            new Rule('*_users', 'username', 'mask', null, -900),
            new Rule('*_users', 'password', 'hide', null, -900),
            new Rule('*_users', 'cachepwd', 'hide', null, -900),
            new Rule('*_users', 'salt', 'hide', null, -900),
            new Rule('*_users', 'session_stale', 'hide', null, -900),

            new Rule('*_user_attributes', 'fullname', 'mask', null, -900),
            new Rule('*_user_attributes', 'email', 'mask', null, -900),
            new Rule('*_user_attributes', 'phone', 'mask', null, -900),
            new Rule('*_user_attributes', 'mobilephone', 'mask', null, -900),
            new Rule('*_user_attributes', 'fax', 'mask', null, -900),
            new Rule('*_user_attributes', 'address', 'mask', null, -900),
            new Rule('*_user_attributes', 'city', 'mask', null, -900),
            new Rule('*_user_attributes', 'state', 'mask', null, -900),
            new Rule('*_user_attributes', 'zip', 'mask', null, -900),
            new Rule('*_user_attributes', 'comment', 'hide', null, -900),
            new Rule('*_user_attributes', 'website', 'hide', null, -900),
            new Rule('*_user_attributes', 'photo', 'hide', null, -900),
            new Rule('*_user_attributes', 'dob', 'replace', 0, -900),
            new Rule('*_user_attributes', 'extended', 'mask', null, -900),

            new Rule('*_ms2_orders', 'session_id', 'hide', null, -900),
            new Rule('*_ms2_orders', 'order_comment', 'hide', null, -900),
            new Rule('*_ms2_orders', 'properties', 'mask', null, -900),

            new Rule('*_ms2_order_addresses', 'receiver', 'mask', null, -900),
            new Rule('*_ms2_order_addresses', 'phone', 'mask', null, -900),
            new Rule('*_ms2_order_addresses', 'email', 'mask', null, -900),
            new Rule('*_ms2_order_addresses', 'country', 'mask', null, -900),
            new Rule('*_ms2_order_addresses', 'index', 'mask', null, -900),
            new Rule('*_ms2_order_addresses', 'region', 'mask', null, -900),
            new Rule('*_ms2_order_addresses', 'city', 'mask', null, -900),
            new Rule('*_ms2_order_addresses', 'metro', 'mask', null, -900),
            new Rule('*_ms2_order_addresses', 'street', 'mask', null, -900),
            new Rule('*_ms2_order_addresses', 'building', 'mask', null, -900),
            new Rule('*_ms2_order_addresses', 'entrance', 'mask', null, -900),
            new Rule('*_ms2_order_addresses', 'floor', 'mask', null, -900),
            new Rule('*_ms2_order_addresses', 'room', 'mask', null, -900),
            new Rule('*_ms2_order_addresses', 'comment', 'hide', null, -900),
            new Rule('*_ms2_order_addresses', 'text_address', 'mask', null, -900),
            new Rule('*_ms2_order_addresses', 'properties', 'mask', null, -900),

            new Rule('*_ms2_customer_profiles', 'account', 'replace', 0, -900),
            new Rule('*_ms2_customer_profiles', 'spent', 'replace', 0, -900),
            new Rule('*_ms2_customer_profiles', 'referrer_code', 'hide', null, -900),
        ];

        foreach (['email', 'phone', 'mobilephone', 'fullname', 'address', 'comment', 'ip'] as $column) {
            $rules[] = new Rule('*', $column, $column === 'comment' ? 'hide' : 'mask', null, -1000);
        }
        foreach (['password', 'cachepwd', 'salt', 'token', 'secret', 'api_key', 'apikey', 'authorization'] as $column) {
            $rules[] = new Rule('*', $column, 'hide', null, -1000);
        }

        /*
         * Настройки MODX: ключи платёжных шлюзов, пароль SMTP, токены рассылок и
         * аналитики лежат в колонке `value` вперемешку с безобидными настройками.
         * Правило по колонке их не различает, поэтому здесь работает условие по
         * соседней колонке `key` — стирается только значение секретной настройки,
         * остальная конфигурация в копии остаётся, и сайт из неё поднимается.
         */
        // Те же настройки, переопределённые в контексте или у пользователя,
        // лежат в отдельных таблицах с той же парой колонок — иначе секрет
        // уехал бы в копии через контекстное переопределение.
        foreach (['*_system_settings', '*_context_setting', '*_user_setting'] as $table) {
            $rules[] = new Rule(
                $table,
                'value',
                'hide',
                null,
                -1000,
                null,
                ['key' => self::secretSettingPatterns()]
            );
        }

        return $rules;
    }
}
