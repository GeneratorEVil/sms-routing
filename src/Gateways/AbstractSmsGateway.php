<?php

namespace SmsRouting\Gateways;

use SmsRouting\Enum\SmsGateway;
use SmsRouting\DTOs\SmsResultDTO;
use SmsRouting\Contracts\SmsGatewayContract;
use Illuminate\Support\Facades\Log;

/**
 * База для адаптеров SMS-шлюзов.
 *
 * Наследники реализуют только send(), переиспользуя существующий
 * API-сервис шлюза вместо дублирования HTTP-логики.
 */
abstract class AbstractSmsGateway implements SmsGatewayContract
{
    public const LOG_CHANNEL = 'sms';

    /**
     * Ключ инстанса. Пусто = инстанс по умолчанию, он совпадает
     * с кодом провайдера. Проставляется через withInstance().
     */
    private string $instance = '';

    abstract protected function codeEnum(): SmsGateway;

    /**
     * Класс из внешнего SDK, без которого адаптер работать не будет.
     *
     * null у адаптеров на голом HTTP. SmsManager не регистрирует
     * адаптер, если SDK не установлен: иначе он упал бы на старте
     * из-за шлюза, который в маршруте не участвует.
     *
     * @return class-string|null
     */
    public static function sdkClass(): ?string
    {
        return null;
    }

    /**
     * Где лежат credentials провайдера по умолчанию.
     *
     * Формат: [<ключ credentials> => <путь в config>]. Ключи
     * используются и в секции 'credentials' инстанса, поэтому
     * у credentials инстанса и у глобальных — одни и те же имена.
     *
     * Путь в config у разных провайдеров разный намеренно:
     * у SMSC это services.smscru.*, у SMS.to — smsto.*,
     * у SMSFly — отдельный файл gateway-smsfly.php.
     *
     * @return array<string, string>
     */
    abstract protected function credentialPaths(): array;

    /**
     * Секретный ключ шлюза. null/'' означает, что шлюз не сконфигурирован
     * и не должен участвовать в отправке.
     */
    abstract protected function apiKey(): ?string;

    abstract public function send(string $phone, string $text, ?string $sender = null): SmsResultDTO;

    public function code(): SmsGateway
    {
        return $this->codeEnum();
    }

    public function instance(): string
    {
        return $this->instance !== '' ? $this->instance : $this->codeEnum()->value;
    }

    public function withInstance(string $instance): static
    {
        $clone = clone $this;
        $clone->instance = trim($instance);

        return $clone;
    }

    public function supportsInstanceCredentials(): bool
    {
        return true;
    }

    /**
     * Секция инстанса из config/sms.php. Пустая у инстанса
     * по умолчанию.
     */
    protected function instanceConfig(): array
    {
        return (array) config('sms.instances.' . $this->instance(), []);
    }

    /**
     * Настройки шлюза: блок провайдера из sms.gateways, поверх которого
     * наложены переопределения инстанса (sender, otp_template,
     * log_channel, enabled). Ключи 'provider' и 'credentials' —
     * не настройки, поэтому не попадают в выдачу.
     *
     * Пустое значение в инстансе НЕ считается переопределением —
     * ровно как и в credential(). Иначе закомментированная в .env
     * переменная молча затирала бы настройку провайдера: sender
     * уходил бы от имени config('app.name'), а enabled = null
     * тихо выключал шлюз.
     *
     * Проверка строгая, а не array_filter без колбэка: false —
     * это осмысленное "выключить", его нужно пропустить в merge.
     */
    protected function config(): array
    {
        $base = (array) config('sms.gateways.' . $this->codeEnum()->value, []);
        $overrides = $this->instanceConfig();

        unset($overrides['provider'], $overrides['credentials']);

        $overrides = array_filter($overrides, static function($value) {
            if ($value === null) {
                return false;
            }

            return ! (is_string($value) && '' === trim($value));
        });

        return array_merge($base, $overrides);
    }

    /**
     * Один credential: сперва инстанс, потом глобальный конфиг.
     *
     * Пустая строка в инстансе НЕ считается переопределением —
     * иначе нечаянно закомментированный ключ в .env молча уронил бы
     * отправку. Пустое значение в инстансе = "использовать общий".
     */
    protected function credential(string $key): ?string
    {
        $fromInstance = $this->instanceConfig()['credentials'][$key] ?? null;

        if (is_string($fromInstance) && '' !== trim($fromInstance)) {
            return trim($fromInstance);
        }

        $path = $this->credentialPaths()[$key] ?? null;
        $global = $path !== null ? config($path) : null;

        return is_string($global) && '' !== trim($global) ? $global : null;
    }

    protected function isEnabled(): bool
    {
        return (bool) $this->config()['enabled'];
    }

    public function apiKeyConfigured(): bool
    {
        return '' !== trim((string) $this->apiKey());
    }

    public function isAvailable(): bool
    {
        return $this->isEnabled() && $this->apiKeyConfigured();
    }

    public function sender(): ?string
    {
        $sender = $this->config()['sender'] ?? config('app.name');

        return is_string($sender) && '' !== trim($sender) ? $sender : null;
    }

    public function otpTemplate(): ?string
    {
        $template = $this->config()['otp_template'] ?? null;

        return is_string($template) && '' !== trim($template) ? $template : null;
    }

    protected function logChannel(): string
    {
        $channel = $this->config()['log_channel'] ?? self::LOG_CHANNEL;

        return is_string($channel) && $channel !== '' ? $channel : self::LOG_CHANNEL;
    }

    protected function timeout(): int
    {
        return (int) (config('sms.timeout') ?: 8);
    }

    protected function logSuccess(string $phone, SmsResultDTO $result): void
    {
        Log::channel($this->logChannel())->info('SMS sent', [
            'gateway' => $this->codeEnum()->value,
            'instance' => $this->instance(),
            'phone' => $phone,
            'message_id' => $result->messageId,
            'status' => $result->status,
            'raw' => $result->raw,
        ]);
    }

    protected function logFailure(string $phone, string $error): void
    {
        Log::channel($this->logChannel())->error('SMS send failed', [
            'gateway' => $this->codeEnum()->value,
            'instance' => $this->instance(),
            'phone' => $phone,
            'error' => $error,
        ]);
    }
}
