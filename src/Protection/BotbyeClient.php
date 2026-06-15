<?php

declare(strict_types=1);

namespace Botbye\Protection;

use Botbye\Common\BotbyeException;
use Botbye\Common\ErrorClassifier;
use Botbye\Common\ModuleInfo;
use Botbye\Protection\Model\BotbyeEvaluateResponse;
use Botbye\Protection\Model\BotbyeEvent;
use Botbye\Protection\Model\BotbyeFullEvent;
use Botbye\Protection\Model\BotbyeRequestInfo;
use Botbye\Protection\Model\BotbyeRiskScoringEvent;
use Botbye\Protection\Model\BotbyeUserInfo;
use Botbye\Protection\Model\BotbyeValidationEvent;
use Botbye\Protection\Model\EventStatus;
use Closure;
use Exception;
use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Evaluate client (Level 1/2 bot & risk scoring). Requires a server key and runs an init handshake
 * on construction. Phishing image tracking lives in {@see \Botbye\Phishing\BotbyePhishingClient}.
 */
final class BotbyeClient
{
    private static bool $inited = false;

    private LoggerInterface $logger;

    /**
     * @param (callable(mixed): BotbyeRequestInfo)|null $requestInfoExtractor maps a raw framework
     *        request to a {@see BotbyeRequestInfo}; enables the {@code evaluate*($request, ...)} methods.
     */
    public function __construct(
        private BotbyeConfig $config,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        ?LoggerInterface $logger = null,
        private readonly ?Closure $requestInfoExtractor = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->ensureInited();
    }

    /**
     * Factory for framework SDKs: bind a request extractor so callers pass only their raw request
     * object to {@see evaluateValidation} / {@see evaluateRiskScoring} / {@see evaluateFull}.
     *
     * @param callable(mixed): BotbyeRequestInfo $requestInfoExtractor
     */
    public static function withExtractor(
        BotbyeConfig $config,
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        callable $requestInfoExtractor,
        ?LoggerInterface $logger = null,
    ): self {
        return new self(
            $config,
            $httpClient,
            $requestFactory,
            $streamFactory,
            $logger,
            Closure::fromCallable($requestInfoExtractor),
        );
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
                        'module_name' => ModuleInfo::NAME,
                        'module_version' => ModuleInfo::VERSION,
                    ],
                ]
            );

            $response = $this->sendPayload($url, $payload);

            return BotbyeEvaluateResponse::fromArray($response);
        } catch (Exception $e) {
            $this->logger->warning('[BotBye] exception occurred: ' . $e->getMessage());

            return BotbyeEvaluateResponse::bypass(ErrorClassifier::classify($e->getMessage()));
        }
    }

    /**
     * Level 1 bot validation from a raw framework request (requires a request extractor).
     *
     * @param array<string, string> $customFields
     */
    public function evaluateValidation(mixed $request, ?string $token = null, array $customFields = []): BotbyeEvaluateResponse
    {
        $info = $this->extractRequestInfo($request);

        return $this->evaluate(new BotbyeValidationEvent(
            ip: $info->ip,
            token: $token ?? $info->token ?? '',
            headers: $info->headers,
            requestMethod: $info->requestMethod,
            requestUri: $info->requestUri,
            customFields: $customFields,
        ));
    }

    /**
     * Level 2 risk evaluation from a raw framework request (requires a request extractor).
     *
     * @param array<string, string> $customFields
     */
    public function evaluateRiskScoring(
        mixed $request,
        BotbyeUserInfo $user,
        string $eventType,
        EventStatus $eventStatus,
        ?string $botbyeResult = null,
        array $customFields = [],
    ): BotbyeEvaluateResponse {
        $info = $this->extractRequestInfo($request);

        return $this->evaluate(new BotbyeRiskScoringEvent(
            ip: $info->ip,
            headers: $info->headers,
            user: $user,
            eventType: $eventType,
            eventStatus: $eventStatus,
            botbyeResult: $botbyeResult,
            customFields: $customFields,
        ));
    }

    /**
     * Combined Level 1+2 evaluation from a raw framework request (requires a request extractor).
     *
     * @param array<string, string> $customFields
     */
    public function evaluateFull(
        mixed $request,
        BotbyeUserInfo $user,
        string $eventType,
        EventStatus $eventStatus,
        ?string $token = null,
        array $customFields = [],
    ): BotbyeEvaluateResponse {
        $info = $this->extractRequestInfo($request);

        return $this->evaluate(new BotbyeFullEvent(
            ip: $info->ip,
            token: $token ?? $info->token ?? '',
            headers: $info->headers,
            user: $user,
            eventType: $eventType,
            eventStatus: $eventStatus,
            requestMethod: $info->requestMethod,
            requestUri: $info->requestUri,
            customFields: $customFields,
        ));
    }

    private function extractRequestInfo(mixed $request): BotbyeRequestInfo
    {
        if ($this->requestInfoExtractor === null) {
            throw new BotbyeException(
                '[BotBye] no requestInfoExtractor configured; use BotbyeClient::withExtractor() for raw-request evaluate*'
            );
        }

        $info = ($this->requestInfoExtractor)($request);
        if (!$info instanceof BotbyeRequestInfo) {
            throw new BotbyeException('[BotBye] requestInfoExtractor must return a BotbyeRequestInfo');
        }

        return $info;
    }

    public function setConfig(BotbyeConfig $config): void
    {
        $this->config = $config;
        $this->ensureInited();
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

    /**
     * @return array<string, string>
     */
    private function buildHeaders(): array
    {
        return [
            'Content-Type' => $this->config->contentType,
            'Module-Name' => ModuleInfo::NAME,
            'Module-Version' => ModuleInfo::VERSION,
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
