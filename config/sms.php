<?php

use SmsRouting\Enum\SmsGateway;
use SmsRouting\RateLimit\NullSmsRateLimiter;

return [
    /*
    |--------------------------------------------------------------------------
    | Маршрутизация SMS
    |--------------------------------------------------------------------------
    |
    | Маршрут выбирается по ISO2-коду страны номера получателя
    | (определяется через libphonenumber, см. PhoneCountryResolver).
    |
    | Страна ищется по точному совпадению, затем по регион-группе,
    | затем используется маршрут по умолчанию.
    |
    | ВАЖНО: если маршрут страны задан, но все его шлюзы недоступны,
    | дефолтный маршрут НЕ подставляется — попытка завершается ошибкой.
    | Чтобы этого не допустить, держите в каждой цепочке хотя бы один
    | шлюз, для которого заполнены ключи в .env.
    |
    | Значение цепочки — список ключей инстансов через запятую, они же
    | определяют порядок failover. Например: "karix,smsc" означает
    | "сначала Karix, при ошибке — SMSC".
    |
    */

    'default' => env('SMS_ROUTE_DEFAULT', 'karix,smsc'),

    'default_gateway' => env('SMS_DEFAULT_GATEWAY', SmsGateway::KARIX->value),

    /*
    |--------------------------------------------------------------------------
    | Маршруты по странам
    |--------------------------------------------------------------------------
    |
    | Ключ — ISO2-код страны. Страна ищется по точному совпадению,
    | затем среди регион-групп ниже, затем используется маршрут
    | по умолчанию.
    |
    | ПУСТОЕ ЗНАЧЕНИЕ = "у страны нет своего маршрута", и поиск
    | продолжается: регион-группа, затем SMS_ROUTE_DEFAULT.
    |
    | Именно так и должно быть по умолчанию. Раньше здесь стояли
    | захардкоженные цепочки ('karix,smsc' для UA), из-за чего
    | незакомментированная в .env переменная SMS_ROUTE_UA не влияла
    | на маршрут: номер уходил через karix/smsc вместо
    | SMS_ROUTE_DEFAULT, и ошибка выглядела как "не настроено",
    | хотя .env был настроен верно.
    |
    */

    'routes' => [
        'UA' => env('SMS_ROUTE_UA'),
        'RU' => env('SMS_ROUTE_RU'),
        'KZ' => env('SMS_ROUTE_KZ'),
        'BY' => env('SMS_ROUTE_BY'),
        'KG' => env('SMS_ROUTE_KG'),
        'UZ' => env('SMS_ROUTE_UZ'),
        'BR' => env('SMS_ROUTE_BR'),
        'MX' => env('SMS_ROUTE_MX'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Регион-группы
    |--------------------------------------------------------------------------
    |
    | Удобны, когда на группу стран используется одна цепочка шлюзов.
    | Членство задано явно, а не по первой букве кода — иначе группа
    | молча захватывает не те страны.
    |
    | ПУСТОЕ ЗНАЧЕНИЕ chain = "для этой группы нет маршрута", и поиск
    | продолжается до SMS_ROUTE_DEFAULT. Значения по умолчанию у групп
    | намеренно отсутствуют: раньше здесь стояли 'smsc,karix' и
    | 'sigmasms,smsc', из-за чего SMS_ROUTE_DEFAULT не действовал ни для
    | одной страны СНГ/Европы/Латинской Америки — маршрут молча уходил
    | в зашитую цепочку, а не в .env.
    |
    */

    'groups' => [
        'cis' => [
            'countries' => ['RU', 'BY', 'KZ', 'KG', 'UZ', 'TM', 'TJ', 'AZ', 'AM', 'GE', 'MD'],
            'chain' => env('SMS_ROUTE_CIS'),
        ],

        'europe' => [
            'countries' => [
                'DE', 'FR', 'ES', 'IT', 'PT', 'NL', 'BE', 'AT', 'CH', 'PL', 'CZ', 'SK',
                'HU', 'RO', 'BG', 'GR', 'LT', 'LV', 'EE', 'FI', 'SE', 'NO', 'DK', 'IE',
                'HR', 'SI', 'RS', 'BA', 'AL', 'MK', 'MD', 'CY', 'MT', 'LU', 'IS',
            ],
            'chain' => env('SMS_ROUTE_EUROPE'),
        ],

        'latam' => [
            'countries' => ['BR', 'MX', 'AR', 'CL', 'CO', 'PE', 'UY', 'PY', 'BO', 'EC', 'VE', 'CR', 'PA', 'GT'],
            'chain' => env('SMS_ROUTE_LATAM'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Настройки отправки
    |--------------------------------------------------------------------------
    */

    'timeout' => (int) env('SMS_TIMEOUT', 8),

    'failover' => (bool) env('SMS_FAILOVER_ENABLED', true),

    'log_channel' => env('SMS_LOG_CHANNEL', 'sms'),

    /*
    |--------------------------------------------------------------------------
    | Антифлуд
    |--------------------------------------------------------------------------
    |
    | Минимальный интервал между SMS на одного получателя, минуты.
    | Ноль отключает ограничение. Раньше это правило было продублировано
    | в каждом канале; теперь проверяется централизованно в SmsManager.
    |
    | Кто считается получателем и где хранится время последней
    | отправки, решает rate_limiter ниже: пакет не знает про модели
    | приложения. По умолчанию — заглушка без ограничений.
    |
    */

    'rate_limit_minutes' => (int) env('SMS_RATE_LIMIT_MINUTES', 1),

    /*
     * Реализация SmsRateLimiter: "не чаще раза в rate_limit_minutes".
     * Приложение подставляет свою (обычно по модели игрока),
     * здесь — заглушка, которая ничего не ограничивает.
     */
    'rate_limiter' => env('SMS_RATE_LIMITER') ?: NullSmsRateLimiter::class,

    /*
    |--------------------------------------------------------------------------
    | Шлюзы
    |--------------------------------------------------------------------------
    |
    | enabled  — можно ли использовать шлюз вообще.
    | sender   — имя/префикс отправителя по умолчанию для этого шлюза.
    | log_channel — отдельный канал логов; пусто = общий 'sms'.
    | otp_template — шаблон текста OTP ИМЕННО для этого шлюза.
    |
    | Ключи и учётные данные берутся из config/services.php,
    | здесь включается только управление.
    |
    | Плейсхолдер в otp_template ровно один: %code. Текст
    | подставляется шлюзом, который реально отправил SMS, а не тем,
    | который был первым в цепочке, — иначе при failover текст
    | ушёл бы получателю в формате чужого провайдера.
    |
    | Исторически у шлюзов были разные формулировки. Сейчас дефолт
    | везде одинаковый, а отличия включаются через .env:
    |   SMSC_OTP_TEMPLATE="Код подтверждения:%code"   (без пробела)
    |   SIGMASMS_OTP_TEMPLATE="ваш код: %code"         (с маленькой)
    |   GREENSMS_OTP_TEMPLATE="Ваш код подтверждения: %code"
    |   SMSFLY_OTP_TEMPLATE="%code"                    (только код)
    |
    | Пустой otp_template = пустая строка или пробелы → берётся дефолт.
    | Шаблон без %code отправляется как есть, но в лог падает warning.
    |
    */

    'gateways' => [
        SmsGateway::SMSC->value => [
            'enabled' => (bool) env('SMS_SMSC_ENABLED', true),
            'sender' => env('SMSC_SENDER'),
            'log_channel' => env('SMS_SMSC_LOG_CHANNEL', 'smsc'),
            'otp_template' => env('SMSC_OTP_TEMPLATE', 'Код подтверждения: %code'),
        ],
        SmsGateway::SIGMASMS->value => [
            'enabled' => (bool) env('SMS_SIGMASMS_ENABLED', true),
            'sender' => env('SIGMASMS_SENDER'),
            'log_channel' => env('SMS_SIGMASMS_LOG_CHANNEL', 'sigmasms'),
            'otp_template' => env('SIGMASMS_OTP_TEMPLATE', 'Код подтверждения: %code'),
        ],

        SmsGateway::SMSTO->value => [
            'enabled' => (bool) env('SMS_SMSTO_ENABLED', true),
            'sender' => env('SMS_SMSTO_SENDER'),
            'log_channel' => env('SMS_SMSTO_LOG_CHANNEL', 'smsto'),
            'otp_template' => env('SMSTO_OTP_TEMPLATE', 'Код подтверждения: %code'),
        ],

        SmsGateway::SMSFLY->value => [
            'enabled' => (bool) env('SMS_SMSFLY_ENABLED', true),
            'sender' => env('SMSFLY_SMS_NAME'),
            'log_channel' => env('SMS_SMSFLY_LOG_CHANNEL', 'smsfly'),
            'otp_template' => env('SMSFLY_OTP_TEMPLATE', 'Код подтверждения: %code'),
        ],

        SmsGateway::KARIX->value => [
            'enabled' => (bool) env('SMS_KARIX_ENABLED', true),
            'sender' => env('KARIX_SENDER'),
            'log_channel' => env('SMS_KARIX_LOG_CHANNEL', 'karix'),
            'otp_template' => env('KARIX_OTP_TEMPLATE', 'Код подтверждения: %code'),
        ],

        SmsGateway::EASYSENDSMS->value => [
            'enabled' => (bool) env('SMS_EASYSENDSMS_ENABLED', true),
            'sender' => env('EASYSENDSMS_SENDER'),
            'log_channel' => env('SMS_EASYSENDSMS_LOG_CHANNEL', 'easysendsms'),
            'otp_template' => env('EASYSENDSMS_OTP_TEMPLATE', 'Код подтверждения: %code'),
        ],

        SmsGateway::BOTTO->value => [
            'enabled' => (bool) env('SMS_BOTTO_ENABLED', true),
            'sender' => env('SMSBOTTO_SENDER'),
            'log_channel' => env('SMS_BOTTO_LOG_CHANNEL', 'smsbotto'),
            'otp_template' => env('SMSBOTTO_OTP_TEMPLATE', 'Код подтверждения: %code'),
        ],

        SmsGateway::LAAFFIC->value => [
            'enabled' => (bool) env('SMS_LAAFFIC_ENABLED', true),
            'sender' => env('LAAFFIC_SENDER'),
            'log_channel' => env('SMS_LAAFFIC_LOG_CHANNEL', 'laaffic'),
            'otp_template' => env('LAAFFIC_OTP_TEMPLATE', 'Код подтверждения: %code'),
        ],

        SmsGateway::GREENSMS->value => [
            'enabled' => (bool) env('SMS_GREENSMS_ENABLED', true),
            'sender' => env('GREENSMS_SENDER'),
            'log_channel' => env('SMS_GREENSMS_LOG_CHANNEL', 'greensms'),
            'otp_template' => env('GREENSMS_OTP_TEMPLATE', 'Код подтверждения: %code'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Инстансы шлюзов
    |--------------------------------------------------------------------------
    |
    | Нужны, когда ОДИН провайдер используется с РАЗНЫМИ аккаунтами.
    | Типичный случай: два региональных контракта SigmaSMS, один на RU,
    | второй на KZ, каждый со своим API-ключом и своим именем отправителя.
    |
    | Ключ массива — имя инстанса, именно его указывают в цепочках:
    |     'routes' => ['RU' => 'sigmasms_ru,smsc'],
    |     'routes' => ['KZ' => 'sigmasms_kz,smsc'],
    |
    | У каждого провайдера всегда есть инстанс по умолчанию, ключ которого
    | равен коду провайдера ("smsc", "sigmasms"). Поэтому маршруты,
    | написанные до появления этой секции, работают без изменений.
    |
    | Поддерживаемые ключи определения:
    |   provider     — обязательный код провайдера: sigmasms, karix, ...
    |   credentials  — свои учётные данные; перекрывают config/services.php.
    |                  Имена ключей те же, что и у провайдера в services:
    |                  sigmasms → token, karix → api_key,
    |                  laaffic  → api_key / app_id / app_secret,
    |                  botto    → password.
    |   sender       — имя отправителя для этого аккаунта.
    |   otp_template — шаблон OTP для этого аккаунта.
    |   enabled      — включение/выключение отдельно от провайдера.
    |   log_channel  — отдельный канал логов.
    |
    | ПУСТОЕ ЗНАЧЕНИЕ credentials = "использовать общий ключ провайдера".
    | Это сделано намеренно: закомментированная в .env переменная не должна
    | молча ронять отправку.
    |
    | ОГРАНИЧЕНИЕ: per-instance credentials поддерживают не все провайдеры.
    | У SMSC ключи читаются из define() на уровне файла, у SMS.to —
    | внутри пакета; разделить их аккаунты нельзя. Попытка задать
    | credentials такому провайдеру приведёт к исключению на старте.
    | Эти два провайдера всё ещё могут иметь несколько инстансов,
    | но только для sender / otp_template / log_channel.
    |
    */

    'instances' => [
        // Пример: два региональных контракта SigmaSMS.
        'sigmasms_ru' => [
            'provider' => SmsGateway::SIGMASMS->value,
            'credentials' => [
                'token' => env('SIGMASMS_RU_TOKEN'),
            ],
            'sender' => env('SIGMASMS_RU_SENDER'),
        ],
        'sigmasms_kz' => [
            'provider' => SmsGateway::SIGMASMS->value,
            'credentials' => [
                'token' => env('SIGMASMS_KZ_TOKEN'),
            ],
            'sender' => env('SIGMASMS_KZ_SENDER'),
        ],
    ],
];
