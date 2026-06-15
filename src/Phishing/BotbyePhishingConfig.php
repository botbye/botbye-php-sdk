<?php

declare(strict_types=1);

namespace Botbye\Phishing;

use InvalidArgumentException;

final class BotbyePhishingConfig
{
    private const DEFAULT_ENDPOINT = 'https://verify.botbye.com';

    public readonly string $endpoint;
    public readonly string $clientKey;

    public function __construct(
        string $endpoint,
        string $clientKey,
        /** Overrides where the once-per-process init-handshake guard flag is stored (default: temp dir). */
        public readonly ?string $initGuardFlagFile = null,
    ) {
        $normalizedEndpoint = rtrim($endpoint, '/');
        $this->endpoint = $normalizedEndpoint !== '' ? $normalizedEndpoint : self::DEFAULT_ENDPOINT;
        $this->clientKey = $clientKey;

        if ($this->clientKey === '') {
            throw new InvalidArgumentException('[BotBye] phishing clientKey is not specified');
        }
    }
}
