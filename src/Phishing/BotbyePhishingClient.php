<?php

declare(strict_types=1);

namespace Botbye\Phishing;

use Botbye\Common\BotbyeError;
use Botbye\Common\ErrorClassifier;
use Botbye\Common\ModuleInfo;
use Exception;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Phishing-only client. Authenticates with the public {@see BotbyePhishingConfig::$clientKey}
 * embedded in the URL path — it needs no server key, so it can be constructed independently of the
 * evaluate {@see \Botbye\Protection\BotbyeClient}.
 *
 * On construction it fires a best-effort server-integration init handshake
 * ({@code POST /api/v1/phishing/init-request/v1/{clientKey}}), reporting this module via the
 * {@code Module-Name} / {@code Module-Version} headers (guarded to run once per process, like the
 * evaluate client). {@see fetchImage} fetches the tracking pixel server-side via the {@code /server}
 * route, so the backend can attribute it to this module even when the browser never reaches BotBye
 * directly (the SDK proxies the image).
 */
final class BotbyePhishingClient
{
    private static bool $inited = false;

    private LoggerInterface $logger;

    public function __construct(
        private readonly BotbyePhishingConfig $config,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->ensureInited();
    }

    public function fetchImage(?string $origin, ?string $imageId = null): BotbyePhishingResponse
    {
        $conf = $this->config;
        $url = $conf->endpoint
            . '/api/v1/phishing/image/' . rawurlencode($conf->clientKey) . '/server';

        if ($imageId === null || $imageId === '') {
            $url .= '?format=png';
            return $this->fetchPhishingAsset($url, $origin, 'png');
        }

        $url .= '?image_id=' . rawurlencode($imageId) . '&format=svg';
        return $this->fetchPhishingAsset($url, $origin, 'svg');
    }

    private function fetchPhishingAsset(
        string $url,
        ?string $origin,
        string $assetType,
    ): BotbyePhishingResponse {
        try {
            $request = $this->requestFactory->createRequest('GET', $url)
                ->withHeader('Origin', $origin ?? 'origin is missing')
                ->withHeader('Module-Name', ModuleInfo::NAME)
                ->withHeader('Module-Version', ModuleInfo::VERSION);

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
            return new BotbyePhishingResponse(error: new BotbyeError(ErrorClassifier::classify($e->getMessage())));
        }
    }

    /**
     * Fires the init handshake at most once per process. PHP re-instantiates the client per request,
     * so a static flag + a per-(endpoint, clientKey) file flag suppress the otherwise per-request POST.
     * Mirrors the evaluate client's guard.
     */
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

        $key = hash('sha256', rtrim($this->config->endpoint, '/') . '|' . $this->config->clientKey);
        $dir = rtrim((string)sys_get_temp_dir(), DIRECTORY_SEPARATOR);

        return $dir . DIRECTORY_SEPARATOR . 'botbye-php-sdk-phishing-init-' . $key . '.flag';
    }

    /**
     * Reports the server-side phishing integration to the backend (the {@code SERVER_INTEGRATION_INIT}
     * get-started milestone). Best-effort: any failure is logged and swallowed, so it never blocks or
     * breaks the customer's request.
     */
    private function initRequest(): void
    {
        try {
            $url = rtrim($this->config->endpoint, '/')
                . '/api/v1/phishing/init-request/v1/' . rawurlencode($this->config->clientKey);

            $request = $this->requestFactory->createRequest('POST', $url)
                ->withHeader('Module-Name', ModuleInfo::NAME)
                ->withHeader('Module-Version', ModuleInfo::VERSION);

            $response = $this->httpClient->sendRequest($request);

            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                $this->logger->warning('[BotBye] phishing init-request returned HTTP ' . $statusCode);
            }
        } catch (Exception $e) {
            $this->logger->warning('[BotBye] phishing init-request exception: ' . $e->getMessage());
        }
    }
}
