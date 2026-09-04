<?php

declare(strict_types=1);

namespace Botbye\Phishing;

final class BotbyePhishingRequestInfo
{
    public function __construct(
        public readonly ?string $origin = null,
        public readonly ?string $referer = null,
        public readonly array $query = [],
    ) {
    }
}
