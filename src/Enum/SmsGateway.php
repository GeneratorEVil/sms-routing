<?php

namespace SmsRouting\Enum;

enum SmsGateway: string
{
    case SMSC = 'smsc';
    case SIGMASMS = 'sigmasms';
    case SMSTO = 'smsto';
    case SMSFLY = 'smsfly';
    case KARIX = 'karix';
    case EASYSENDSMS = 'easysendsms';
    case BOTTO = 'botto';
    case LAAFFIC = 'laaffic';
    case GREENSMS = 'greensms';

    /**
     * Список всех шлюзов, которые умеет отправлять SmsManager.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Безопасный разбор строки из конфига. Неизвестные коды отбрасываются,
     * чтобы опечатка в .env не роняла весь процесс отправки.
     */
    public static function tryFromString(?string $value): ?self
    {
        if ( ! is_string($value)) {
            return null;
        }

        return self::tryFrom(strtolower(trim($value)));
    }
}
