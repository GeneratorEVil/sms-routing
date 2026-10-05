<?php

namespace SmsRouting;

use SmsRouting\Enum\SmsGateway;
use SmsRouting\DTOs\SmsResultDTO;
use SmsRouting\Exceptions\SmsException;
use SmsRouting\Contracts\SmsGatewayContract;
use SmsRouting\Contracts\SmsRateLimiter;
use SmsRouting\RateLimit\NullSmsRateLimiter;
use SmsRouting\Gateways\AbstractSmsGateway;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Точка входа для отправки SMS.
 *
 * Отвечает за выбор цепочки шлюзов по стране получателя, антифлуд
 * и failover: при ошибке одного шлюза пробует следующий по маршруту.
 *
 * ЗАВИСИМОСТИ. Пакет ничего не знает о моделях приложения: получатель
 * приходит аргументом (mixed), а правило антифлуда задаёт реализация
 * SmsRateLimiter из контейнера. По умолчанию — NullSmsRateLimiter.
 *
 * ИНСТАНСЫ. Адаптер зарегистрирован не по коду провайдера, а по
 * ключу инстанса. Для каждого провайдера всегда есть инстанс
 * по умолчанию с ключом, равным коду провайдера (например "smsc"),
 * поэтому маршруты, написанные до появления инстансов, работают
 * как раньше. Дополнительные инстансы описаны в config/sms.php
 * в секции 'instances' и позволяют использовать один провайдер
 * с разными аккаунтами, например "sigmasms_ru" и "sigmasms_kz".
 */
class SmsManager
{
    /**
     * Текст OTP, если для шлюза не задан свой шаблон.
     * Плейсхолдер ровно один: %code.
     */
    public const DEFAULT_OTP_TEMPLATE = 'Код подтверждения: %code';

    /**
     * Плейсхолдер кода в шаблоне OTP. Меняете здесь — меняется
     * ожидаемое поведение всех шаблонов в .env.
     */
    public const OTP_PLACEHOLDER = '%code';

    /**
     * Все адаптеры, keyed по ключу инстанса. В цепочке маршрута
     * указываются именно эти ключи.
     *
     * @var array<string, SmsGatewayContract>
     */
    private array $gateways = [];

    private SmsRateLimiter $rateLimiter;

    public function __construct(
        private readonly PhoneCountryResolver $resolver,
        ?SmsRateLimiter $rateLimiter = null,
    ) {
        $this->rateLimiter = $rateLimiter ?? $this->resolveRateLimiter();

        foreach (self::gatewayClasses() as $class) {
            if ( ! class_exists($class) || ! self::sdkInstalled($class)) {
                $this->log('warning', 'SMS gateway skipped: SDK is not installed', [
                    'gateway' => $class,
                    'sdk' => class_exists($class) ? $class::sdkClass() : $class,
                ]);

                continue;
            }

            try {
                /** @var SmsGatewayContract $gateway */
                $gateway = app($class);
            } catch (\Throwable $e) {
                // Адаптер есть, но собрать его не удалось. Молчаливый
                // пропуск хуже явного предупреждения, но ронять весь
                // процесс отправки из-за одного шлюза тоже нельзя.
                $this->log('warning', 'SMS gateway skipped: adapter is not available', [
                    'gateway' => $class,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            // Инстанс по умолчанию: ключ = код провайдера.
            $this->register($gateway, $gateway->code()->value);
        }

        foreach ((array) config('sms.instances', []) as $name => $definition) {
            $this->registerInstance((string) $name, $definition);
        }
    }

    /**
     * Антифлуд живёт в приложении, поэтому берём реализацию из
     * контейнера. Без биндинга (например в изолированном юнитесте)
     * отправка просто не ограничивается.
     */
    private function resolveRateLimiter(): SmsRateLimiter
    {
        return app()->bound(SmsRateLimiter::class)
            ? app(SmsRateLimiter::class)
            : new NullSmsRateLimiter();
    }

    /**
     * Шлюзы на чужих SDK (GreenSMS, SMS.to, SMSFly) объявлены
     * опциональными зависимостями пакета. Без установленного SDK
     * такой адаптер нельзя даже создать, поэтому он не попадает
     * в цепочку, а в лог уходит предупреждение.
     *
     * @param  class-string<AbstractSmsGateway>  $class
     */
    private static function sdkInstalled(string $class): bool
    {
        $sdk = $class::sdkClass();

        return $sdk === null || class_exists($sdk);
    }

    /**
     * @return array<int, class-string<AbstractSmsGateway>>
     */
    public static function gatewayClasses(): array
    {
        return [
            Gateways\KarixGateway::class,
            Gateways\SmscGateway::class,
            Gateways\SigmasmsGateway::class,
            Gateways\SmsFlyGateway::class,
            Gateways\SmsToGateway::class,
            Gateways\EasySendSmsGateway::class,
            Gateways\BottoGateway::class,
            Gateways\LaafficGateway::class,
            Gateways\GreenSmsGateway::class,
        ];
    }

    /**
     * Инстанс по умолчанию для провайдера. Обратная совместимость:
     * раньше метод назывался gateway() и отдавал единственный адаптер.
     */
    public function gateway(SmsGateway $code): ?SmsGatewayContract
    {
        return $this->gateways[$code->value] ?? null;
    }

    /**
     * Адаптер конкретного инстанса. Основной путь: маршруты
     * ссылаются на инстансы, а не напрямую на провайдеров.
     */
    public function instance(string $name): ?SmsGatewayContract
    {
        return $this->gateways[strtolower(trim($name))] ?? null;
    }

    private function register(SmsGatewayContract $gateway, string $name): void
    {
        $this->gateways[strtolower(trim($name))] = $gateway->withInstance($name);
    }

    /**
     * Регистрирует дополнительный инстанс из config/sms.php.
     *
     * Неизвестный провайдер или отсутствие адаптера — предупреждение
     * в лог, а не исключение: опечатка в .env не должна ронять
     * весь процесс отправки. Такой инстанс просто не попадёт
     * в цепочку, и ошибка всплывёт при отправке.
     *
     * А вот credentials у провайдера, который их не умеет, —
     * исключение: иначе конфиг выглядел бы рабочим, а SMS уходили
     * бы с чужого аккаунта.
     */
    private function registerInstance(string $name, mixed $definition): void
    {
        $key = strtolower(trim($name));

        if ($key === '' || ! is_array($definition)) {
            $this->log('warning', 'SMS instance skipped: empty name or malformed definition', [
                'instance' => $name,
            ]);

            return;
        }

        if (isset($this->gateways[$key])) {
            throw new InvalidArgumentException(sprintf('SMS instance "%s" collides with an already registered instance. Instance names must differ from provider codes: %s', $key, implode(', ', SmsGateway::values())));
        }

        $provider = SmsGateway::tryFromString((string) ($definition['provider'] ?? ''));

        if ($provider === null) {
            $this->log('warning', 'SMS instance skipped: unknown provider', [
                'instance' => $key,
                'provider' => (string) ($definition['provider'] ?? ''),
            ]);

            return;
        }

        $base = $this->gateway($provider);

        if ($base === null) {
            $this->log('warning', 'SMS instance skipped: provider has no registered adapter', [
                'instance' => $key,
                'provider' => $provider->value,
            ]);

            return;
        }

        $credentials = array_filter(
            (array) ($definition['credentials'] ?? []),
            static fn($value) => is_string($value) && '' !== trim($value),
        );

        if ($credentials !== [] && ! $base->supportsInstanceCredentials()) {
            throw new InvalidArgumentException(sprintf('SMS instance "%s": provider "%s" reads its credentials internally and cannot use per-instance ones. Remove "credentials" from this instance, or keep the provider-wide key in config/services.php.', $key, $provider->value));
        }

        $this->register($base, $key);
    }

    /**
     * Отправляет обычное SMS с готовым текстом.
     *
     * @param  mixed  $notifiable  получатель для антифлуда. Пакет
     *                             ничего о нём не знает: важно
     *                             лишь, чтобы приложение переиспользовало
     *                             тот же объект в SmsRateLimiter.
     *
     * @throws SmsException если не сработал ни один шлюз цепочки.
     */
    public function send(?string $phone, string $text, mixed $notifiable = null, ?string $sender = null): SmsResultDTO
    {
        return $this->dispatch($phone, $text, $notifiable, $sender, null);
    }

    /**
     * Отправляет OTP. Текст НЕ готовый: его собирает тот шлюз, который
     * реально отправил SMS, по своему шаблону.
     *
     * Так сделано из-за failover: если первый шлюз в цепочке упал,
     * текст должен соответствовать шаблону второго, а не первого.
     *
     * @throws SmsException если не сработал ни один шлюз цепочки.
     */
    public function sendOtp(?string $phone, string $code, mixed $notifiable = null, ?string $sender = null): SmsResultDTO
    {
        if ('' === trim($code)) {
            throw new SmsException('SMS OTP code is empty');
        }

        return $this->dispatch($phone, null, $notifiable, $sender, $code);
    }

    /**
     * @param  string|null  $text  готовый текст; null, если отправляется OTP
     * @param  string|null  $otpCode  код OTP; null для обычного SMS
     */
    private function dispatch(
        ?string $phone,
        ?string $text,
        mixed $notifiable,
        ?string $sender,
        ?string $otpCode,
    ): SmsResultDTO {
        if ( ! is_string($phone) || '' === trim($phone)) {
            throw new SmsException('SMS phone is empty');
        }

        if ($otpCode === null && ( ! is_string($text) || '' === trim($text))) {
            throw new SmsException('SMS text is empty');
        }

        $country = $this->resolver->resolve($phone);
        $chain = $this->chainFor($country);

        $this->log('info', 'SMS dispatch', [
            'phone' => $phone,
            'country' => $country ?? 'unknown',
            'chain' => implode(',', $chain),
            'kind' => $otpCode === null ? 'plain' : 'otp',
        ]);

        if ($notifiable !== null && ! $this->passesRateLimit($notifiable)) {
            $this->log('info', 'SMS skipped by rate limit', [
                'phone' => $phone,
                'notifiable' => $this->subjectKey($notifiable),
                'last_sent_at' => $this->rateLimiter->lastSentAt($notifiable),
            ]);

            return SmsResultDTO::skipped(
                SmsGateway::tryFromString((string) config('sms.default_gateway')) ?? SmsGateway::KARIX,
                'rate limit',
                $phone,
            );
        }

        $failover = (bool) config('sms.failover', true);
        $failures = [];
        $lastException = null;

        foreach ($chain as $name) {
            $gateway = $this->instance($name);

            if ($gateway === null) {
                $failures[] = $name . ': instance not registered';

                continue;
            }

            if ( ! $gateway->isAvailable()) {
                $failures[] = $name . ': not available (disabled or no credentials)';

                continue;
            }

            // Сборка текста внутри цикла: шаблон берётся у того шлюза,
            // который действительно отправляет, а не у первого в цепочке.
            $message = $otpCode === null
                ? (string) $text
                : $this->renderOtp($gateway, $otpCode);

            try {
                $result = $gateway->send($phone, $message, $sender ?? $gateway->sender());

                if ($result->success) {
                    $this->markSent($notifiable);

                    return $result;
                }

                $failures[] = $name . ': ' . (string) $result->error;

                // Отказ в теле ответа — такой же провал, как исключение,
                // поэтому флаг failover обязан действовать и здесь. Раньше
                // break стоял только в catch, и при SMS_FAILOVER_ENABLED=false
                // мягкий отказ всё равно уходил на второй шлюз.
                if ( ! $failover) {
                    break;
                }
            } catch (\Throwable $e) {
                $lastException = $e;
                $failures[] = $name . ': ' . $e->getMessage();

                $this->log('warning', 'SMS gateway failed, trying next', [
                    'gateway' => $gateway->code()->value,
                    'instance' => $name,
                    'phone' => $phone,
                    'error' => $e->getMessage(),
                ]);

                if ( ! $failover) {
                    break;
                }
            }
        }

        $this->log('error', 'SMS dispatch failed on all gateways', [
            'phone' => $phone,
            'country' => $country ?? 'unknown',
            'failures' => $failures,
        ]);

        throw new SmsException('Failed to send SMS via ' . implode(', ', $chain), $failures, 0, $lastException);
    }

    /**
     * Подставляет код в шаблон конкретного инстанса.
     *
     * Пустой шаблон (не задан, пустая строка, только пробелы) — берётся
     * дефолт. Шаблон без %code отправляется как есть, но в лог падает
     * предупреждение: скорее всего это опечатка в конфиге, и игрок
     * получит письмо без кода.
     */
    private function renderOtp(SmsGatewayContract $gateway, string $otpCode): string
    {
        $template = $gateway->otpTemplate() ?? self::DEFAULT_OTP_TEMPLATE;

        if ( ! str_contains($template, self::OTP_PLACEHOLDER)) {
            $this->log('warning', 'SMS OTP template has no %code placeholder', [
                'gateway' => $gateway->code()->value,
                'instance' => $gateway->instance(),
            ]);
        }

        $rendered = trim(strtr($template, [self::OTP_PLACEHOLDER => $otpCode]));

        return $rendered !== ''
            ? $rendered
            : trim(strtr(self::DEFAULT_OTP_TEMPLATE, [self::OTP_PLACEHOLDER => $otpCode]));
    }

    /**
     * Цепочка инстансов для страны: точное совпадение →
     * регион-группа → по умолчанию.
     *
     * @return array<int, string> ключи инстансов
     */
    public function chainFor(?string $country): array
    {
        foreach ($this->routeCandidates($country) as $key) {
            $chain = $this->chainFromKey($key);

            if ($chain !== []) {
                return $chain;
            }
        }

        $default = $this->parseChain((string) config('sms.default'));

        if ($default === []) {
            $fallback = SmsGateway::tryFromString((string) config('sms.default_gateway'));

            return $fallback !== null ? [$fallback->value] : [];
        }

        return $default;
    }

    /**
     * Разбирает значение цепочки в список ключей инстансов без дублей,
     * сохраняя порядок (порядок = приоритет, важен для failover).
     *
     * Неизвестные ключи НЕ отбрасываются здесь: иначе опечатка в маршруте
     * молча укоротила бы цепочку и отправила SMS не тем аккаунтом.
     * Пропуск случится в dispatch с внятной записью в лог.
     *
     * @return array<int, string>
     */
    private function parseChain(?string $chain): array
    {
        if ( ! is_string($chain) || '' === trim($chain)) {
            return [];
        }

        $result = [];

        foreach (explode(',', $chain) as $item) {
            $name = strtolower(trim($item));

            if ($name !== '' && ! in_array($name, $result, true)) {
                $result[] = $name;
            }
        }

        return $result;
    }

    /**
     * Ключи маршрутов в порядке приоритета для страны.
     *
     * Сначала точное совпадение страны, затем первая регион-группа,
     * в которую страна входит.
     *
     * @return array<int, string>
     */
    private function routeCandidates(?string $country): array
    {
        if ($country === null) {
            return [];
        }

        $keys = [];

        if (array_key_exists($country, (array) config('sms.routes', []))) {
            $keys[] = $country;
        }

        foreach ((array) config('sms.groups', []) as $group => $definition) {
            $countries = (array) ($definition['countries'] ?? []);

            if (in_array($country, $countries, true)) {
                $keys[] = $group;

                break;
            }
        }

        return $keys;
    }

    /**
     * Цепочка для группы: значение берётся из ключа 'chain' определения.
     *
     * @return array<int, string>
     */
    private function chainFromKey(string $key): array
    {
        if (array_key_exists($key, (array) config('sms.groups', []))) {
            return $this->parseChain((string) config('sms.groups.' . $key . '.chain'));
        }

        return $this->parseChain((string) config('sms.routes.' . $key));
    }

    /**
     * Антифлуд: не чаще раза в N минут на одного получателя.
     * Раньше проверка была продублирована в каждом из каналов,
     * а правило "кто считается получателем" жило в модели User.
     * Теперь решает реализация SmsRateLimiter.
     */
    private function passesRateLimit(mixed $notifiable): bool
    {
        $minutes = (int) config('sms.rate_limit_minutes', 0);

        if ($minutes <= 0) {
            return true;
        }

        return ! $this->rateLimiter->isThrottled($notifiable, $minutes);
    }

    private function markSent(mixed $notifiable): void
    {
        if ($notifiable === null) {
            return;
        }

        $this->rateLimiter->record($notifiable);
    }

    /**
     * Идентификатор получателя для логов. Пакет не знает про модели,
     * поэтому берём ключ у Eloquent-подобных объектов, а для всего
     * остального — null.
     */
    private function subjectKey(mixed $notifiable): int|string|null
    {
        if (is_object($notifiable) && method_exists($notifiable, 'getKey')) {
            $key = $notifiable->getKey();

            return is_int($key) || is_string($key) ? $key : null;
        }

        return null;
    }

    private function log(string $level, string $message, array $context = []): void
    {
        $channel = Log::channel((string) (config('sms.log_channel') ?: 'sms'));

        match ($level) {
            'warning' => $channel->warning($message, $context),
            'error' => $channel->error($message, $context),
            default => $channel->info($message, $context),
        };
    }
}
