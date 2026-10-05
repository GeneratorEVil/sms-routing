<?php

namespace SmsRouting\Exceptions;

use SmsRouting\Enum\SmsGateway;
use Exception;

/**
 * Бросается, когда не удалось отправить SMS ни одним шлюзом из цепочки.
 *
 * В отличие от исключений отдельных шлюзов, сюда попадает только
 * итоговый провал всей цепочки failover.
 */
class SmsException extends Exception
{
    /**
     * @param  array<int, string>  $failures  Список "<шлюз>: <причина>" по всем попыткам.
     */
    public function __construct(string $message, private readonly array $failures = [], int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * @return array<int, string>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    /**
     * Какие шлюзы были в цепочке (для логов и алертов).
     *
     * @return array<int, SmsGateway>
     */
    public function gateways(): array
    {
        return array_map(
            fn(string $failure) => explode(':', $failure, 2)[0],
            $this->failures,
        );
    }
}
