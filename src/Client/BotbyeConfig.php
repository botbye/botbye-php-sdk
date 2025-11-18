<?php

declare(strict_types=1);

namespace Botbye\Client;

use InvalidArgumentException;

final readonly class BotbyeConfig
{
    public const MODULE_NAME = 'PHP';
    public const MODULE_VERSION = '1.0.0';

    public function __construct(
        public string $serverKey,
        public string $botbyeEndpoint = 'https://verify.botbye.com',
        public string $contentType = 'application/json',
        public float $timeout = 1.0,        // connection and read timeout
        public float $max_duration = 2.0,   // maximum request duration
    ) {
        if (empty($this->serverKey)) {
            throw new InvalidArgumentException('[BotBye] server key is not specified');
        }
    }
}
