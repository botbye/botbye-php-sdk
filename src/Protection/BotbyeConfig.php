<?php

declare(strict_types=1);

namespace Botbye\Protection;

use InvalidArgumentException;

final class BotbyeConfig
{
    public function __construct(
        public readonly string $serverKey,
        public readonly string $botbyeEndpoint = 'https://verify.botbye.com',
        public readonly string $contentType = 'application/json',
        public ?string $initGuardFlagFile = null,
    ) {
        if (empty($this->serverKey)) {
            throw new InvalidArgumentException('[BotBye] server key is not specified');
        }
    }
}
