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
    ) {
        $normalizedEndpoint = rtrim($endpoint, '/');
        $this->endpoint = $normalizedEndpoint !== '' ? $normalizedEndpoint : self::DEFAULT_ENDPOINT;
        $this->clientKey = $clientKey;

        if ($this->clientKey === '') {
            throw new InvalidArgumentException('[BotBye] phishing clientKey is not specified');
        }
    }
}
