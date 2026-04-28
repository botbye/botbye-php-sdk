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
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class BotbyeClient
{
    public const RESULT_HEADER = 'X-Botbye-Result';

    private static bool $inited = false;

    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;
    private ?BotbyePhishingConfig $phishingConfig = null;

    private readonly string $bypassResultBase64;

    public function __construct(
        private BotbyeConfig $config,
        ?HttpClientInterface $httpClient = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->httpClient = $httpClient ?? $this->createDefaultHttpClient();
        $this->logger = $logger ?? new NullLogger();
        $this->bypassResultBase64 = base64_encode(
            (string)json_encode(['config' => ['bypass_bot_validation' => true]])
        );
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

    /**
     * Encodes evaluate response as base64 JSON for propagation to Level 2
     * via the X-Botbye-Result (RESULT_HEADER) header.
     * Mirrors Kotlin Botbye.encodeResult() and OpenResty M.encodeResult().
     */
    public function encodeResult(BotbyeEvaluateResponse $response): string
    {
        return base64_encode((string)json_encode([
            'request_id' => $response->requestId,
            'decision' => $response->decision->value,
            'risk_score' => $response->riskScore,
            'signals' => $response->signals,
            'scores' => $response->scores,
            'config' => $response->config,
        ]));
    }

    /**
     * Returns a pre-computed bypass result (base64 JSON with bypass_bot_validation=true).
     * Use when request should not be validated (excluded URI, service token, etc).
     * Mirrors Kotlin Botbye.bypassResult() and OpenResty M.propagateBypass().
     */
    public function bypassResult(): string
    {
        return $this->bypassResultBase64;
    }

    public function setConfig(BotbyeConfig $config): void
    {
        $this->config = $config;
        $this->httpClient = $this->createDefaultHttpClient();
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
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'X-Api-Key' => $conf->apiKey,
                    'Origin' => $origin ?? 'origin is missing',
                ],
                'timeout' => $conf->timeout,
                'max_duration' => $conf->max_duration,
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);

            $headers = array_map(function ($values) {
                return implode(', ', $values);
            }, $response->getHeaders(false));

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
            $response = $this->httpClient->request('POST', $url, [
                'headers' => $this->buildHeaders(),
                'json' => $payload,
                'timeout' => $this->config->timeout,
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);

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
        } catch (TransportExceptionInterface $e) {
            throw new BotbyeException('[BotBye] Transport error: ' . $e->getMessage(), 0, $e);
        } catch (JsonException $e) {
            throw new BotbyeException('[BotBye] JSON decode error: ' . $e->getMessage(), 0, $e);
        } catch (ClientExceptionInterface|RedirectionExceptionInterface|ServerExceptionInterface $e) {
            throw new BotbyeException('[BotBye] HTTP error: ' . $e->getMessage(), 0, $e);
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

            $response = $this->httpClient->request('POST', $url, [
                'headers' => $this->buildHeaders(),
                'json' => $body,
                'timeout' => $this->config->timeout,
            ]);

            $statusCode = $response->getStatusCode();
            error_log('[BotBye] init-request: HTTP status = ' . $statusCode);

            $content = $response->getContent(false);
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

    private function createDefaultHttpClient(): HttpClientInterface
    {
        return HttpClient::create([
            'timeout' => $this->config->timeout,
            'max_duration' => $this->config->max_duration,
            'max_redirects' => 0,
        ]);
    }
}
