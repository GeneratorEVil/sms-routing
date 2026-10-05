<?php

namespace SmsRouting\Clients;

use Nekkoy\GatewaySmsfly\Services\SendMessageService;

class SmsFlyClient extends SendMessageService
{
    public function __construct($config, $message)
    {
        parent::__construct($config, $message);
    }

    public function setApiUrl($url)
    {
        $this->api_url = $url;
    }
}
