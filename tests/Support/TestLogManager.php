<?php

declare(strict_types=1);

namespace SmsRouting\Tests\Support;

use Illuminate\Log\LogManager;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * LogManager, который пишет в LogCollector вместо файла: тестам не
 * нужны файлы, а проверять записи логов нужно.
 */
final class TestLogManager extends LogManager
{
    public function __construct($app, private readonly LogCollector $collector)
    {
        parent::__construct($app);
    }

    public function channel($channel = null, array $config = []): AbstractLogger
    {
        $collector = $this->collector;

        return new class($collector) extends AbstractLogger {
            public function __construct(private readonly LogCollector $collector) {}

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->collector->push((string) $level, (string) $message, $context);
            }
        };
    }
}
