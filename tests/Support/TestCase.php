<?php

declare(strict_types=1);

namespace SmsRouting\Tests\Support;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\TestCase as BaseTestCase;
use SmsRouting\Enum\SmsGateway;
use SmsRouting\Gateways\BottoGateway;
use SmsRouting\Gateways\EasySendSmsGateway;
use SmsRouting\Gateways\GreenSmsGateway;
use SmsRouting\Gateways\KarixGateway;
use SmsRouting\Gateways\LaafficGateway;
use SmsRouting\Gateways\SmsFlyGateway;
use SmsRouting\Gateways\SmsToGateway;
use SmsRouting\Gateways\SmscGateway;
use SmsRouting\Gateways\SigmasmsGateway;
use SmsRouting\PhoneCountryResolver;
use SmsRouting\SmsManager;

/**
 * Тесты пакета не зависят от laravel/framework: поднимается только тот
 * минимум illuminate, который пакет использует сам (container, config,
 * log, events). Набор тестов остаётся быстрым и не тянет фреймворк.
 */
abstract class TestCase extends BaseTestCase
{
    protected Container $app;

    protected Repository $config;

    protected Dispatcher $events;

    protected LogCollector $logCollector;

    /** @var array<int, object> события, отправленные через event() */
    protected array $dispatchedEvents = [];

    protected FakeRateLimiter $rateLimiter;

    /** @var array<class-string, SmsGateway> */
    private const GATEWAY_CLASSES = [
        KarixGateway::class => SmsGateway::KARIX,
        SmscGateway::class => SmsGateway::SMSC,
        SigmasmsGateway::class => SmsGateway::SIGMASMS,
        SmsFlyGateway::class => SmsGateway::SMSFLY,
        SmsToGateway::class => SmsGateway::SMSTO,
        EasySendSmsGateway::class => SmsGateway::EASYSENDSMS,
        BottoGateway::class => SmsGateway::BOTTO,
        LaafficGateway::class => SmsGateway::LAAFFIC,
        GreenSmsGateway::class => SmsGateway::GREENSMS,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new Container();
        $this->logCollector = new LogCollector();
        $this->dispatchedEvents = [];

        $this->config = new Repository(['sms' => require __DIR__ . '/../../config/sms.php']);
        $this->app->instance('config', $this->config);
        $this->app->instance(Repository::class, $this->config);

        $this->events = new Dispatcher($this->app);
        $this->events->listen('*', function (string $event, array $payload): void {
            $this->dispatchedEvents[] = is_object($payload[0] ?? null) ? $payload[0] : $event;
        });
        $this->app->instance('events', $this->events);
        $this->app->instance(Dispatcher::class, $this->events);

        $this->app->singleton('log', fn() => new TestLogManager($this->app, $this->logCollector));
        $this->app->instance(LogManager::class, $this->app->make('log'));

        $this->rateLimiter = new FakeRateLimiter();
        $this->app->instance(\SmsRouting\Contracts\SmsRateLimiter::class, $this->rateLimiter);

        $this->fakeGateways();

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        TestContainer::set($this->app);

        $this->app->singleton(PhoneCountryResolver::class);
        $this->app->singleton(SmsManager::class);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        TestContainer::forget();

        parent::tearDown();
    }

    /**
     * Подменяет ВСЕ адаптеры пакета фейками: реальные шлюзы в тестах
     * не должны ходить в сеть.
     */
    protected function fakeGateways(): void
    {
        foreach (self::GATEWAY_CLASSES as $class => $code) {
            $this->app->instance($class, new FakeGateway($code));
        }
    }

    /**
     * Провайдер, который не умеет per-instance credentials (SMSC, SMS.to):
     * их ключи читаются внутри SDK.
     */
    protected function fakeWithoutInstanceCredentials(string $code): void
    {
        $enum = SmsGateway::tryFromString($code);

        if ($enum === null) {
            $this->fail(sprintf('Unknown gateway "%s"', $code));
        }

        foreach (self::GATEWAY_CLASSES as $class => $mapped) {
            if ($mapped !== $enum) {
                continue;
            }

            $fake = new FakeGateway($mapped);
            $fake->supportsInstanceCredentials = false;

            $this->app->instance($class, $fake);

            return;
        }

        $this->fail(sprintf('No adapter bound for gateway "%s"', $code));
    }

    /**
     * Фейк зарегистрированного инстанса.
     *
     * Именно из SmsManager, а не из контейнера: register() кладёт в
     * менеджер клон (withInstance), поэтому править нужно тот объект,
     * который реально отправляет SMS.
     */
    protected function fake(string $code): FakeGateway
    {
        $gateway = $this->manager()->instance($code);

        if ( ! $gateway instanceof FakeGateway) {
            $this->fail(sprintf('Fake gateway "%s" is not registered (optional SDK is missing?)', $code));
        }

        return $gateway;
    }

    /**
     * Заменяет целиком секцию конфига пакета.
     *
     * @param  array<string, mixed>  $values
     */
    protected function smsConfig(array $values): void
    {
        $this->config->set('sms', array_replace(require __DIR__ . '/../../config/sms.php', $values));
    }

    /**
     * Меняет значение по точечному пути: 'routes.UA', 'default' и т.п.
     */
    protected function smsSet(string $key, mixed $value): void
    {
        $this->config->set('sms.' . $key, $value);
    }

    protected function manager(): SmsManager
    {
        return $this->app->make(SmsManager::class);
    }

    /**
     * @param  array<int, string>  $chain
     */
    protected function assertChain(array $chain, ?string $country): void
    {
        $this->assertSame(
            $chain,
            $this->manager()->chainFor($country),
            sprintf('Unexpected chain for country "%s"', $country ?? 'null')
        );
    }

    /**
     * Записи логов с указанным сообщением.
     *
     * @return array<int, array{level: string, message: string, context: array}>
     */
    protected function logsWith(string $needle): array
    {
        return $this->logCollector->containing($needle);
    }
}
