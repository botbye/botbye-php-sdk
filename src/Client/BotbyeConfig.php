<?php

declare(strict_types=1);

namespace Botbye\Client;

use InvalidArgumentException;

final class BotbyeConfig
{
    public const MODULE_NAME = 'PHP';
    public const MODULE_VERSION = '1.0.0';

    public function __construct(
        public readonly string $serverKey,
        public readonly string $botbyeEndpoint = 'https://verify.botbye.com',
        public readonly string $contentType = 'application/json',
        public readonly float $timeout = 1.0,        // connection and read timeout
        public readonly float $max_duration = 2.0,   // maximum request duration
    ) {
        if (empty($this->serverKey)) {
            throw new InvalidArgumentException('[BotBye] server key is not specified');
        }
    }
}
