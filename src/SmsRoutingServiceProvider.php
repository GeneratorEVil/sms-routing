<?php

namespace SmsRouting;

use Illuminate\Support\ServiceProvider;
use SmsRouting\Contracts\SmsRateLimiter;
use SmsRouting\RateLimit\NullSmsRateLimiter;

/**
 * Регистрирует пакет в Laravel.
 *
 * Настройки лежат в config('sms.*') — имя секции не менялось, поэтому
 * приложение может либо пользоваться дефолтами пакета, либо
 * опубликовать config/sms.php себе и переопределить нужные ключи
 * (mergeConfigFrom сливает по верхнему уровню).
 */
class SmsRoutingServiceProvider extends ServiceProvider
{
    /**
     * Каналы логов, которые пакет использует сам. Приложение может
     * переопределить их в config/logging.php — тогда его версии
     * останутся нетронутыми.
     *
     * @var array<int, string>
     */
    private const LOG_CHANNELS = [
        'sms',
        'smsc',
        'sigmasms',
        'smsto',
        'smsfly',
        'karix',
        'easysendsms',
        'smsbotto',
        'laaffic',
        'greensms',
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/sms.php', 'sms');

        $this->app->singleton(SmsRateLimiter::class, function ($app) {
            $class = (string) $app['config']->get('sms.rate_limiter');

            // Невалидим класс в конфиге — это ошибка конфигурации, а не
            // повод молча отключить антифлуд.
            if ( ! is_string($class) || ! class_exists($class)) {
                return new NullSmsRateLimiter();
            }

            return $app->make($class);
        });

        $this->app->singleton(SmsManager::class, fn($app) => new SmsManager(
            $app->make(PhoneCountryResolver::class),
            $app->make(SmsRateLimiter::class),
        ));
    }

    public function boot(): void
    {
        $this->registerLogChannels();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/sms.php' => $this->app->configPath('sms.php'),
            ], 'sms-config');
        }
    }

    /**
     * Каналы логов пакета должны существовать, иначе первый же
     * Log::channel('smsc') упал бы на отсутствующем канале. Приложение
     * с собственными определениями выигрывает: заполняем только
     * отсутствующие.
     */
    private function registerLogChannels(): void
    {
        $path = $this->app->storagePath('logs/sms.log');

        foreach (self::LOG_CHANNELS as $channel) {
            $key = 'logging.channels.' . $channel;

            if ($this->app['config']->get($key) !== null) {
                continue;
            }

            $this->app['config']->set($key, [
                'driver' => 'daily',
                'path' => $path,
                'level' => 'debug',
                'days' => 14,
            ]);
        }
    }
}
