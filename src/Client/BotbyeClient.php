<?php

declare(strict_types=1);

namespace Botbye\Client;

use Botbye\Exception\BotbyeException;
use Botbye\Model\BotbyeError;
use Botbye\Model\BotbyeEvaluateResponse;
use Botbye\Model\BotbyePhishingResponse;
use Botbye\Model\BotbyeEvent;
use Exception;
use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class BotbyeClient
{
    private static bool $inited = false;

    private LoggerInterface $logger;
    private ?BotbyePhishingConfig $phishingConfig = null;

    public function __construct(
        private BotbyeConfig $config,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->ensureInited();
    }

    /**
     * Send device/event data for risk evaluation.
     * Accepts ValidateEventRequest, RiskScoringRequest, or FullEventRequest.
     *
     * On network or server error, returns a bypass response (fail open) with error set.
     */
    public function evaluate(BotbyeEvent $request): BotbyeEvaluateResponse
    {
        $token = $request->getUrlToken();
        $url = sprintf(
            '%s/api/v1/protect/evaluate%s',
            $this->config->botbyeEndpoint,
            $token !== null ? '?' . $token : ''
        );

        try {
            $payload = array_merge(
                $request->jsonSerialize(),
                [
                    'server_key' => $this->config->serverKey,
                    'integration' => [
                        'module_name' => BotbyeConfig::MODULE_NAME,
                        'module_version' => BotbyeConfig::MODULE_VERSION,
                    ],
                ]
            );

            $response = $this->sendPayload($url, $payload);

            return BotbyeEvaluateResponse::fromArray($response);
        } catch (Exception $e) {
            $this->logger->warning('[BotBye] exception occurred: ' . $e->getMessage());

            return BotbyeEvaluateResponse::bypass($this->classifyError($e->getMessage()));
        }
    }

    public function setConfig(BotbyeConfig $config): void
    {
        $this->config = $config;
        $this->ensureInited();
    }

    public function setPhishingConfig(BotbyePhishingConfig $config): void
    {
        $this->phishingConfig = $config;
    }

    public function fetchImage(?string $origin, ?string $imageId = null): BotbyePhishingResponse
    {
        $conf = $this->phishingConfig;
        if ($conf === null) {
            return new BotbyePhishingResponse(error: new BotbyeError('[BotBye] phishing config is not specified'));
        }

        $url = $conf->endpoint
            . '/api/v1/phishing/' . rawurlencode($conf->accountId)
            . '/projects/' . rawurlencode($conf->projectId)
            . '/image';

        if ($imageId === null || $imageId === '') {
            $url .= '?format=png';
            return $this->fetchPhishingAsset($conf, $url, $origin, 'png');
        }

        $url .= '?image_id=' . rawurlencode($imageId) . '&format=svg';
        return $this->fetchPhishingAsset($conf, $url, $origin, 'svg');
    }

    private function fetchPhishingAsset(
        BotbyePhishingConfig $conf,
        string $url,
        ?string $origin,
        string $assetType,
    ): BotbyePhishingResponse {
        try {
            $request = $this->requestFactory->createRequest('GET', $url)
                ->withHeader('X-Api-Key', $conf->apiKey)
                ->withHeader('Origin', $origin ?? 'origin is missing');

            $response = $this->httpClient->sendRequest($request);

            $statusCode = $response->getStatusCode();
            $content = (string)$response->getBody();

            $headers = [];
            foreach ($response->getHeaders() as $name => $values) {
                $headers[$name] = implode(', ', $values);
            }

            return new BotbyePhishingResponse(status: $statusCode, headers: $headers, body: $content);
        } catch (Exception $e) {
            $this->logger->warning('[BotBye] phishing ' . $assetType . ' exception occurred: ' . $e->getMessage());
            return new BotbyePhishingResponse(error: new BotbyeError($e->getMessage()));
        }
    }

    private function ensureInited(): void
    {
        if (self::$inited) {
            return;
        }

        $flagFile = $this->getInitGuardFlagFilePath();
        $lockFile = $flagFile . '.lock';

        $lockHandle = @fopen($lockFile, 'c');
        if ($lockHandle === false) {
            self::$inited = true;
            $this->initRequest();
            return;
        }

        try {
            if (!@flock($lockHandle, LOCK_EX)) {
                self::$inited = true;
                $this->initRequest();
                return;
            }

            clearstatcache(true, $flagFile);

            $pid = (string)getmypid();
            $needsInit = true;

            if (is_file($flagFile)) {
                $content = (string)@file_get_contents($flagFile);
                if (preg_match('/^pid:(\d+)/', $content, $m)) {
                    if ($m[1] === $pid) {
                        $needsInit = false;
                    }
                }
            }

            self::$inited = true;

            if ($needsInit) {
                $this->initRequest();
                @file_put_contents($flagFile, "pid:$pid\nat:" . time() . "\n", LOCK_EX);
            }
        } finally {
            @flock($lockHandle, LOCK_UN);
            @fclose($lockHandle);
        }
    }

    private function getInitGuardFlagFilePath(): string
    {
        if ($this->config->initGuardFlagFile !== null && $this->config->initGuardFlagFile !== '') {
            return $this->config->initGuardFlagFile;
        }

        $key = hash('sha256', rtrim($this->config->botbyeEndpoint, '/') . '|' . $this->config->serverKey);
        $dir = rtrim((string)sys_get_temp_dir(), DIRECTORY_SEPARATOR);

        return $dir . DIRECTORY_SEPARATOR . 'botbye-php-sdk-init-' . $key . '.flag';
    }

    /**
     * @return array<string, mixed>
     * @throws BotbyeException
     */
    private function sendPayload(string $url, array $payload): array
    {
        try {
            $jsonBody = json_encode($payload, JSON_THROW_ON_ERROR);

            $request = $this->requestFactory->createRequest('POST', $url);
            foreach ($this->buildHeaders() as $name => $value) {
                $request = $request->withHeader($name, $value);
            }
            $request = $request->withBody(
                $this->streamFactory->createStream($jsonBody)
            );

            $response = $this->httpClient->sendRequest($request);

            $statusCode = $response->getStatusCode();
            $content = (string)$response->getBody();

            if ($statusCode >= 500) {
                throw new BotbyeException(
                    sprintf('[BotBye] connection error: HTTP %d', $statusCode)
                );
            }

            if ($statusCode >= 400) {
                throw new BotbyeException(
                    sprintf('[BotBye] HTTP error %d: %s', $statusCode, $content)
                );
            }

            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($decoded)) {
                throw new BotbyeException('[BotBye] Invalid JSON response');
            }

            return $decoded;
        } catch (ClientExceptionInterface $e) {
            throw new BotbyeException('[BotBye] Transport error: ' . $e->getMessage(), 0, $e);
        } catch (JsonException $e) {
            throw new BotbyeException('[BotBye] JSON decode error: ' . $e->getMessage(), 0, $e);
        }
    }

    private function classifyError(string $message): string
    {
        $lower = strtolower($message);
        if (str_contains($lower, 'timeout') || str_contains($lower, 'timed out') || str_contains($lower, 'idle')) {
            return 'timeout';
        }
        if (str_contains($lower, 'transport') || str_contains($lower, 'connect') || str_contains($lower, 'refused')
            || str_contains($lower, 'empty reply') || str_contains($lower, 'reset')
            || str_contains($lower, 'end of stream') || str_contains($lower, 'closed')) {
            return 'connection error';
        }
        if (str_contains($lower, 'json') || str_contains($lower, 'decode') || str_contains($lower, 'parse')
            || str_contains($lower, 'invalid')) {
            return 'invalid json response';
        }
        return $lower;
    }

    /**
     * @return array<string, string>
     */
    private function buildHeaders(): array
    {
        return [
            'Content-Type' => $this->config->contentType,
            'Module-Name' => BotbyeConfig::MODULE_NAME,
            'Module-Version' => BotbyeConfig::MODULE_VERSION,
        ];
    }

    private function initRequest(): void
    {
        try {
            $url = rtrim($this->config->botbyeEndpoint, '/') . '/init-request/v1';
            error_log('[BotBye] init-request: url = ' . $url);

            $body = ['serverKey' => $this->config->serverKey];
            error_log('[BotBye] init-request: body = ' . json_encode($body));

            $jsonBody = json_encode($body, JSON_THROW_ON_ERROR);

            $request = $this->requestFactory->createRequest('POST', $url);
            foreach ($this->buildHeaders() as $name => $value) {
                $request = $request->withHeader($name, $value);
            }
            $request = $request->withBody(
                $this->streamFactory->createStream($jsonBody)
            );

            $response = $this->httpClient->sendRequest($request);

            $statusCode = $response->getStatusCode();
            error_log('[BotBye] init-request: HTTP status = ' . $statusCode);

            $content = (string)$response->getBody();
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            if (($decoded['error'] ?? null) !== null || ($decoded['status'] ?? null) !== 'ok') {
                error_log('[BotBye] init-request error = ' . ($decoded['error'] ?? 'null')
                    . '; status = ' . ($decoded['status'] ?? 'null'));
            } else {
                error_log('[BotBye] init-request: success, status = ' . ($decoded['status'] ?? 'null'));
            }
        } catch (Exception $e) {
            error_log('[BotBye] init-request exception: ' . $e->getMessage());
        }
    }
}
