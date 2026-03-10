<?php

declare(strict_types=1);

namespace Botbye\Client;

use InvalidArgumentException;

final class BotbyePhishingConfig
{
    private const DEFAULT_ENDPOINT = 'https://verify.botbye.com';

    public readonly string $endpoint;
    public readonly string $accountId;
    public readonly string $projectId;
    public readonly string $apiKey;
    public readonly float $timeout;
    public readonly float $max_duration;

    public function __construct(
        string $endpoint,
        string $accountId,
        string $projectId,
        string $apiKey,
        float $timeout = 1.0,
        float $max_duration = 2.0,
    ) {
        $normalizedEndpoint = rtrim($endpoint, '/');
        $this->endpoint = $normalizedEndpoint !== '' ? $normalizedEndpoint : self::DEFAULT_ENDPOINT;
        $this->accountId = $accountId;
        $this->projectId = $projectId;
        $this->apiKey = $apiKey;
        $this->timeout = $timeout;
        $this->max_duration = $max_duration;

        if ($this->accountId === '') {
            throw new InvalidArgumentException('[BotBye] phishing accountId is not specified');
        }
        if ($this->projectId === '') {
            throw new InvalidArgumentException('[BotBye] phishing projectId is not specified');
        }
        if ($this->apiKey === '') {
            throw new InvalidArgumentException('[BotBye] phishing apiKey is not specified');
        }
    }
}
