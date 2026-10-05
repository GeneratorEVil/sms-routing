<?php

declare(strict_types=1);

namespace SmsRouting\Tests\Support;

/**
 * Куда тестовый логгер складывает записи. Объект, а не массив по
 * ссылке: ссылки теряются при пробросе в анонимный класс.
 */
final class LogCollector
{
    /** @var array<int, array{level: string, message: string, context: array}> */
    public array $records = [];

    public function push(string $level, string $message, array $context): void
    {
        $this->records[] = ['level' => $level, 'message' => $message, 'context' => $context];
    }

    /**
     * @return array<int, array{level: string, message: string, context: array}>
     */
    public function containing(string $needle): array
    {
        return array_values(array_filter(
            $this->records,
            static fn(array $record): bool => str_contains($record['message'], $needle)
        ));
    }
}
