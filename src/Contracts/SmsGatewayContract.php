<?php

namespace SmsRouting\Contracts;

use SmsRouting\DTOs\SmsResultDTO;
use SmsRouting\Enum\SmsGateway;

interface SmsGatewayContract
{
    /**
     * Код провайдера. У одного провайдера может быть несколько
     * инстансов (аккаунтов) — они различаются ключом instance().
     */
    public function code(): SmsGateway;

    /**
     * Ключ инстанса: уникальное имя набора настроек и credentials.
     * Для инстанса по умолчанию равен коду провайдера.
     */
    public function instance(): string;

    /**
     * Возвращает копию адаптера, привязанную к инстансу.
     * Копия нужна, потому что у провайдера может быть несколько
     * аккаунтов с разными credentials.
     */
    public function withInstance(string $instance): static;

    /**
     * Умеет ли провайдер брать credentials из инстанса.
     *
     * false у провайдеров, чей SDK/сервис читает ключ внутри себя
     * и не даёт передать его на вызов: тогда аккаунт на инстанс
     * не разделить, и SmsManager падает на старте с внятной ошибкой
     * вместо молчаливой отправки с чужого аккаунта.
     */
    public function supportsInstanceCredentials(): bool;

    /**
     * Готов ли шлюз к отправке: включён в конфиге и заполнены креды.
     * Вызывается до попытки отправки, чтобы не тратить failover на заведомо
     * нерабочий шлюз.
     */
    public function isAvailable(): bool;

    /**
     * Имя отправителя (Sender ID), если шлюз его требует.
     * null означает "использовать дефолт шлюза".
     */
    public function sender(): ?string;

    /**
     * Шаблон текста OTP этого инстанса, уже с учётом переопределений
     * из sms.instances. null означает "взять дефолт SmsManager".
     */
    public function otpTemplate(): ?string;

    /**
     * Отправляет SMS. Бросает исключение при неуспехе —
     * SmsManager перехватит его и попробует следующий шлюз из цепочки.
     */
    public function send(string $phone, string $text, ?string $sender = null): SmsResultDTO;
}
