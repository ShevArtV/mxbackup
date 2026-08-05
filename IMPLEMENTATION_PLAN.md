# mxBackup: архитектура реализации

> Дата: 2026-08-02
>
> Цель: MODX-пакет с CLI-инструментом и админкой для создания production- и
> development-бэкапов сайта. Первая линия — MODX 2, затем порт на MODX 3.
>
> Актуализация 2026-08-05: профили и правила хранятся в переносимых PHP-файлах;
> БД используется только для истории запусков.

## Цель пакета

`mxBackup` должен создавать архив сайта в двух основных режимах:

- `prod` — полный аварийный бэкап для восстановления сайта после сбоя.
- `dev` — бэкап для передачи разработчикам: структура сайта сохраняется, но
  персональные данные пользователей обезличиваются, а чувствительные коммерческие
  данные скрываются или удаляются.

Пакет должен работать из CLI, cron и админки MODX. Настройки должны задаваться
через manager UI, системные настройки MODX, конфигурационный PHP-файл и параметры
CLI.

## Архитектура

Сначала реализуется MODX 2-пакет, но ядро сразу проектируется переносимым:

- `core/components/mxbackup/src/Core/` — общая логика: конфиги, профили,
  файловый backup, database dump, masking, archive, delivery, reports.
- `core/components/mxbackup/src/Platform/Modx2/` — bootstrap MODX 2, доступ к БД,
  системным настройкам, процессорам, логам и manager-интерфейсу.
- `core/components/mxbackup/src/Platform/Modx3/` — будущий адаптер MODX 3.

Правило для переносимости: классы из `Core` не должны зависеть от `modX`, xPDO
классов конкретной версии, manager-контроллеров или процессоров. Все обращения к
MODX идут через platform-интерфейсы.

## Приоритет настроек

Для одной и той же настройки используется единый порядок разрешения:

1. CLI-параметр.
2. Site config file.
3. Файловый профиль из каталога `mxbackup.config_dir`.
4. Системная настройка MODX.
5. Default config пакета.

Пример CLI-запуска:

```bash
php core/components/mxbackup/cli/mxbackup.php backup \
  --profile=dev \
  --storage-path=/home/site/backups \
  --config=/home/site/core/config/mxbackup.php
```

## Системные настройки

Что: добавить базовые системные настройки пакета.

Куда: `modxbuilder/mxbackup/build/data/transport.settings.php`.

Зачем: простые глобальные дефолты должны задаваться штатно через MODX без ручной
правки файлов.

Начальный список:

- `mxbackup.storage_path` — путь сохранения архивов по умолчанию.
- `mxbackup.config_dir` — каталог PHP-файлов профилей; по умолчанию
  `{core_path}config/mxbackup/profiles/`.
- `mxbackup.config_path` — путь до проектного PHP-конфига.
- `mxbackup.default_profile` — профиль по умолчанию (`prod` или `dev`).
- `mxbackup.mail_enabled` — включена ли отправка email.
- `mxbackup.mail_to` — получатели отчётов и небольших архивов.
- `mxbackup.mail_max_attachment_mb` — максимальный размер attachment.
- `mxbackup.retention_days` — сколько дней хранить архивы.
- `mxbackup.retention_count` — сколько последних архивов хранить.
- `mxbackup.lock_ttl_minutes` — TTL блокировки повторного запуска.

## Конфигурационный файл

Что: описать формат PHP-конфига.

Куда: `core/components/mxbackup/docs/config.example.php`.

Зачем: дать проекту расширяемый способ описывать профили, исключения, правила
обезличивания и скрытия данных.

Пример структуры:

```php
<?php

return [
    'profiles' => [
        'prod' => [
            'files' => [
                'include' => ['assets/', 'core/', 'connectors/', 'manager/', 'index.php'],
                'exclude' => ['core/cache/', 'core/packages/', 'assets/cache/'],
            ],
            'database' => [
                'include_tables' => ['*'],
                'exclude_tables' => [],
            ],
            'masking' => [],
            'mail' => [
                'enabled' => false,
            ],
        ],
        'dev' => [
            'files' => [
                'include' => ['assets/', 'core/components/', 'core/elements/'],
                'exclude' => ['core/cache/', 'core/config/', 'assets/uploads/private/'],
            ],
            'database' => [
                'include_tables' => ['*'],
                'exclude_tables' => ['modx_session'],
            ],
            'masking' => [
                'standard' => true,
                'tables' => [],
            ],
            'hide' => [
                'tables' => [],
                'columns' => [],
            ],
        ],
    ],
];
```

## Файловые профили и таблица истории

Профили и правила include/exclude/mask/hide хранятся по одному PHP-файлу на
профиль в каталоге `mxbackup.config_dir`. CMP и CLI используют один источник
истины. Файлы записываются через временный файл и атомарный rename, поэтому их
можно переносить и версионировать вместе с проектом.

При обновлении ранняя схема `mxbackup_profile`/`mxbackup_rule` сначала мигрируется
в PHP-файлы. Старые таблицы удаляются только после успешного чтения и сохранения
всех профилей и правил.

### `mxbackup_run`

Что: хранить историю запусков.

Куда: `core/components/mxbackup/model/schema/mxbackup.mysql.schema.xml`.

Зачем: видеть из админки, когда запускался backup, чем закончился и где лежит архив.

Поля:

- `id`
- `profile`
- `mode`
- `status`: `running`, `success`, `warning`, `error`
- `storage_path`
- `archive_path`
- `archive_size`
- `manifest_json`
- `report_json`
- `email_sent`
- `error`
- `startedon`
- `completedon`

## CLI

Что: добавить entrypoint CLI.

Куда: `core/components/mxbackup/cli/mxbackup.php`.

Зачем: запускать backup из cron, ssh и автоматизации без manager UI.

Команды MVP:

```bash
php core/components/mxbackup/cli/mxbackup.php backup --profile=prod
php core/components/mxbackup/cli/mxbackup.php backup --profile=dev
php core/components/mxbackup/cli/mxbackup.php dry-run --profile=dev
php core/components/mxbackup/cli/mxbackup.php validate-config
php core/components/mxbackup/cli/mxbackup.php list-profiles
php core/components/mxbackup/cli/mxbackup.php cleanup
```

Ключевые параметры:

- `--profile=prod|dev|name`
- `--storage-path=/path/to/backups`
- `--config=/path/to/mxbackup.php`
- `--mail-to=email@example.com`
- `--no-mail`
- `--format=tar.gz|zip`
- `--dry-run`
- `--verbose`

CLI-параметры имеют максимальный приоритет над настройками MODX и профиля.

## Админка MODX 2

Что: добавить manager UI.

Куда:

- `core/components/mxbackup/controllers/mgr/`
- `core/components/mxbackup/processors/mgr/`
- `assets/components/mxbackup/js/mgr/`
- `assets/components/mxbackup/css/mgr/`

Зачем: пользователь должен настраивать backup без SSH и ручного редактирования
конфигов.

Разделы UI:

- `Profiles` — список профилей, создание, редактирование, включение/выключение.
- `Files` — include/exclude файлов и директорий.
- `Database` — include/exclude таблиц.
- `Masking` — правила обезличивания персональных данных.
- `Hidden data` — правила скрытия коммерчески чувствительных данных.
- `Storage` — путь сохранения, retention, формат архива.
- `Mail` — получатели, лимит attachment, режим отправки.
- `Runs / History` — история запусков, статус, путь, размер, ошибка, manifest.

Минимальные manager-процессоры:

- `mgr/profile/getlist`
- `mgr/profile/create`
- `mgr/profile/update`
- `mgr/profile/remove`
- `mgr/rule/getlist`
- `mgr/rule/create`
- `mgr/rule/update`
- `mgr/rule/remove`
- `mgr/run/create`
- `mgr/run/getlist`
- `mgr/run/get`
- `mgr/config/validate`
- `mgr/config/dryrun`

## Файловый backup

Что: реализовать сбор файлов по правилам include/exclude.

Куда:

- `src/Core/Files/FileCollector.php`
- `src/Core/Files/FileRuleMatcher.php`
- `src/Core/Archive/ArchiveWriter.php`

Зачем: создать архив файлов сайта с предсказуемым составом и dry-run отчётом.

Требования:

- поддержка include/exclude директорий и файлов;
- исключение cache/runtime директорий по умолчанию;
- защита от записи архива внутрь самого архиваируемого дерева без исключения;
- streaming-архивация без загрузки всех файлов в память;
- manifest с перечнем включённых/исключённых правил.

## Database backup

Что: реализовать streaming SQL dump.

Куда:

- `src/Core/Database/DatabaseDumper.php`
- `src/Core/Database/TableSelector.php`
- `src/Platform/Modx2/DatabaseAdapter.php`

Зачем: выгружать БД с учётом include/exclude таблиц и применять dev-masking без
избыточной нагрузки на память.

Требования:

- дамп структуры таблиц;
- дамп данных пачками;
- include/exclude таблиц;
- возможность truncate таблиц в dev-профиле;
- корректная обработка `modx_` prefix;
- отчёт о количестве строк и применённых правилах.

## Обезличивание и скрытие данных

Что: реализовать два разных типа трансформации.

Куда:

- `src/Core/Masking/Masker.php`
- `src/Core/Masking/Rule.php`
- `src/Core/Masking/StandardRules.php`
- `src/Core/Masking/JsonPathMasker.php`

Зачем: dev backup должен быть пригоден для разработки, но не должен передавать
персональные и чувствительные коммерческие данные.

`mask` сохраняет форму данных:

- email -> `user{id}@example.test`
- phone -> стабильный тестовый номер;
- fullname -> `User {id}`;
- address -> тестовый адрес;
- IP -> `127.0.0.1` или private test range;
- comments -> пустая строка или синтетический текст.

`hide` удаляет или заменяет значения, которые разработчику не нужны:

- закупочные цены;
- маржа;
- supplier/internal data;
- API-токены;
- внутренние комментарии;
- коммерческие условия;
- приватные delivery/payment metadata.

Стандартные правила MVP:

- `modUser`
- `modUserProfile`
- `modSession` / `modx_session`
- miniShop2 orders;
- miniShop2 order addresses;
- miniShop2 customers, если пакет установлен;
- типовые поля `email`, `phone`, `mobilephone`, `fullname`, `address`, `city`,
  `zip`, `comment`, `ip`, `properties`.

## Storage и retention

Что: добавить локальное хранение backup-ов и очистку старых архивов.

Куда:

- `src/Core/Storage/LocalStorage.php`
- `src/Core/Storage/PathValidator.php`
- `src/Core/Storage/RetentionPolicy.php`

Зачем: backup-и должны сохраняться в управляемом месте, а старые архивы не должны
бесконечно занимать диск.

Путь сохранения задаётся:

- системной настройкой `mxbackup.storage_path`;
- настройкой профиля через UI;
- PHP config file;
- CLI-параметром `--storage-path`.

Требования к `PathValidator`:

- не писать в web-доступную папку без явного override;
- не писать в `core/cache`;
- не писать прямо в корень сайта;
- проверять существование и права директории;
- выдавать понятную ошибку до начала backup-а.

## Email delivery

Что: добавить отправку backup-а или отчёта на email.

Куда:

- `src/Core/Delivery/MailDelivery.php`
- `src/Core/Delivery/DeliveryResult.php`

Зачем: отправлять архив по почте, если размер позволяет, и не ломать backup, если
архив слишком большой.

Правила:

- если архив меньше `mxbackup.mail_max_attachment_mb`, отправить attachment;
- если архив больше лимита, отправить только отчёт с локальным путём, размером и
  причиной;
- email-ошибка не должна удалять локальный backup;
- статус email фиксируется в `mxbackup_run`.

## Manifest и отчёты

Что: добавлять manifest в каждый архив и сохранять отчёт запуска.

Куда:

- `src/Core/Report/ManifestBuilder.php`
- `src/Core/Report/RunReport.php`

Зачем: после создания архива должно быть понятно, что именно было создано и какие
правила применялись.

`mxbackup-manifest.json` внутри архива:

- версия mxBackup;
- версия MODX;
- версия PHP;
- профиль;
- дата запуска;
- путь сайта;
- включённые секции;
- размер файлового архива;
- размер SQL dump;
- checksum;
- статус masking;
- список warning-ов.

## MVP

Первая версия должна включать:

- MODX 2 transport-пакет;
- CLI entrypoint;
- manager UI для профилей, правил, storage, mail и истории;
- `prod` и `dev` профили;
- файловый backup;
- SQL dump;
- include/exclude файлов, директорий и таблиц;
- стандартное обезличивание MODX users и miniShop2 orders;
- custom masking/hide rules;
- путь сохранения через системную настройку, профиль, config file и CLI;
- email-отправку с лимитом attachment;
- локальное хранение и retention;
- dry-run;
- validate-config;
- manifest;
- базовые unit-тесты ядра.

Не включать в MVP:

- S3/FTP/WebDAV;
- restore-команду;
- шифрование архивов;
- расписание из manager UI;
- очередь долгих backup-ов;
- сложный wizard восстановления.

Эти пункты лучше оставить на версии после первого стабильного CLI + UI.

## Этапы реализации

1. Что: создать каркас MODX 2 пакета.
   Куда: `core/components/mxbackup/`, `assets/components/mxbackup/`,
   `modxbuilder/mxbackup/`.
   Зачем: получить базовый устанавливаемый transport.

2. Что: добавить переносимое ядро конфигурации.
   Куда: `src/Core/Config/`.
   Зачем: все точки входа должны одинаково читать настройки и профили.

3. Что: добавить CLI.
   Куда: `core/components/mxbackup/cli/mxbackup.php`.
   Зачем: обеспечить основной production-сценарий запуска из cron.

4. Что: реализовать storage path и path validation.
   Куда: `src/Core/Storage/`.
   Зачем: безопасно сохранять архивы в указанное место.

5. Что: реализовать файловый backup.
   Куда: `src/Core/Files/`, `src/Core/Archive/`.
   Зачем: получить первый рабочий prod backup файлов.

6. Что: реализовать database dump.
   Куда: `src/Core/Database/`, `src/Platform/Modx2/DatabaseAdapter.php`.
   Зачем: добавить backup БД.

7. Что: реализовать dev masking/hide.
   Куда: `src/Core/Masking/`.
   Зачем: сделать dev-архив безопасным для передачи разработчикам.

8. Что: добавить schema/model таблиц профилей, правил и запусков.
   Куда: `core/components/mxbackup/model/schema/mxbackup.mysql.schema.xml`.
   Зачем: хранить сложные настройки и историю в БД пакета.

9. Что: добавить MODX 2 manager UI.
   Куда: `controllers/mgr/`, `processors/mgr/`, `assets/components/mxbackup/js/mgr/`.
   Зачем: дать настройку пакета без SSH.

10. Что: добавить email delivery.
    Куда: `src/Core/Delivery/`.
    Зачем: отправлять небольшие архивы или отчёты.

11. Что: добавить manifest и run reports.
    Куда: `src/Core/Report/`.
    Зачем: сделать backup проверяемым и диагностируемым.

12. Что: покрыть ядро тестами.
    Куда: `tests/Core/`.
    Зачем: защитить правила include/exclude, config resolution и masking.

13. Что: собрать и проверить MODX 2 transport.
    Куда: стенд MODX 2 и `core/packages/`.
    Зачем: подтвердить установку пакета и работу CLI/админки.

14. Что: подготовить порт MODX 3.
    Куда: отдельная линия `mxbackup3` или ветка порта, `src/Platform/Modx3/`,
    `package_builder/packages/mxbackup/`.
    Зачем: перенести только platform/build слой, сохранив общий `Core`.

15. Что: выпустить MODX 2 и MODX 3 линии как отдельные major-версии.
    Куда: modstore, docs/readme.txt, changelog.txt, публичная документация при
    необходимости.
    Зачем: поддерживать одну карточку пакета с двумя платформенными линиями.

## Порт на MODX 3

Порт должен затронуть:

- bootstrap;
- platform adapter;
- manager UI/controller/processors;
- model namespace и schema generation;
- build config через MODX 3 package builder.

Порт не должен менять:

- формат профилей;
- формат CLI-команд;
- `Core`;
- правила masking/hide;
- manifest;
- структуру отчётов.

Версионирование:

- MODX 2 линия: `1.x`.
- MODX 3 линия: `2.x`.
