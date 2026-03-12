<?php

declare(strict_types=1);

namespace Botbye\Model;

final class BotbyePhishingResponse
{
    public function __construct(
        public readonly int $status = 0,
        public readonly array $headers = [],
        public readonly string $body = '',
        public readonly ?BotbyeError $error = null,
    ) {
    }
}
