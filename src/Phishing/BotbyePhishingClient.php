<?php

declare(strict_types=1);

namespace Botbye\Phishing;

use Botbye\Common\BotbyeError;
use Botbye\Common\ErrorClassifier;
use Botbye\Common\InitGuard;
use Botbye\Common\ModuleInfo;
use Closure;
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
    use InitGuard;

    private LoggerInterface $logger;

    /**
     * @param (callable(mixed): ?string)|null $originExtractor extracts the {@code Origin} header from
     *        a raw framework request; enables {@see fetchImageFromRequest}.
     */
    public function __construct(
        private readonly BotbyePhishingConfig $config,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        ?LoggerInterface $logger = null,
        private readonly ?Closure $originExtractor = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->ensureInited();
    }

    /**
     * Factory for framework SDKs: bind an Origin extractor so callers pass only their raw request
     * object to {@see fetchImageFromRequest}.
     *
     * @param callable(mixed): ?string $originExtractor
     */
    public static function withExtractor(
        BotbyePhishingConfig $config,
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        callable $originExtractor,
        ?LoggerInterface $logger = null,
    ): self {
        return new self(
            $config,
            $httpClient,
            $requestFactory,
            $logger,
            Closure::fromCallable($originExtractor),
        );
    }

    /**
     * @param array<string, string> $query Forwarded verbatim to the {@code /server} route — pass the
     *        browser's original pixel query (which carries {@code format}, {@code image_id}, and the
     *        JS tag's {@code module_name} / {@code module_version}).
     */
    public function fetchImage(?string $origin, array $query = []): BotbyePhishingResponse
    {
        $conf = $this->config;
        $url = $conf->endpoint
            . '/api/v1/phishing/image/' . rawurlencode($conf->clientKey) . '/server';

        if (!empty($query)) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $this->fetchPhishingAsset($url, $origin);
    }

    /**
     * Fetch the tracking pixel from a raw framework request (requires an Origin extractor).
     *
     * @param array<string, string> $query Forwarded browser pixel query (see {@see fetchImage}).
     */
    public function fetchImageFromRequest(mixed $request, array $query = []): BotbyePhishingResponse
    {
        if ($this->originExtractor === null) {
            throw new \Botbye\Common\BotbyeException(
                '[BotBye] no phishing extractor configured; use BotbyePhishingClient::withExtractor() to fetch from a raw request'
            );
        }

        /** @var ?string $origin */
        $origin = ($this->originExtractor)($request);

        return $this->fetchImage($origin, $query);
    }

    private function fetchPhishingAsset(
        string $url,
        ?string $origin,
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
            $this->logger->warning('[BotBye] phishing image exception occurred: ' . $e->getMessage());
            return new BotbyePhishingResponse(error: new BotbyeError(ErrorClassifier::classify($e->getMessage())));
        }
    }

    /**
     * Fires the init handshake at most once per process per (endpoint, clientKey), via the shared
     * {@see InitGuard}. The blocking POST runs outside the guard's file lock; mirrors the evaluate client.
     */
    private function ensureInited(): void
    {
        $guardKey = hash('sha256', rtrim($this->config->endpoint, '/') . '|' . $this->config->clientKey);
        $flagFile = $this->initGuardFlagFilePath(
            $this->config->initGuardFlagFile,
            'botbye-php-sdk-phishing-init-',
            $guardKey,
        );

        $this->runInitGuardOnce($guardKey, $flagFile, fn () => $this->initRequest());
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
