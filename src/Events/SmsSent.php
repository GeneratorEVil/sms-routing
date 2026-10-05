<?php

namespace SmsRouting\Events;

use SmsRouting\DTOs\SmsResultDTO;

/**
 * SMS доставлено: отправка прошла успешно, и приложение может
 * записать это в свой журнал.
 *
 * Раньше канал вызывал LogController::insert() напрямую, из-за чего
 * пакет зависел от моделей приложения. Теперь точка расширения —
 * слушатель события на стороне приложения.
 */
class SmsSent
{
    public function __construct(
        /**
         * Получатель в терминах Laravel-уведомлений. Приходит
         * "как есть": пакет ничего о нём не знает.
         */
        public readonly mixed $notifiable,
        public readonly SmsResultDTO $result,
    ) {}
}
