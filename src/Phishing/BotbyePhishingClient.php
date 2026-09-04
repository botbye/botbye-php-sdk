<?php

declare(strict_types=1);

namespace Botbye\Phishing;

use Botbye\Common\BotbyeError;
use Botbye\Common\BotbyeErrors;
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
 * Phishing-only client, keyed by the public {@see BotbyePhishingConfig::$clientKey} — no server key
 * needed. {@see fetchCatcher} proxies the asset via the {@code /server} route so the backend can
 * attribute it even though the browser never reaches BotBye; construction fires a best-effort init
 * handshake (once per process) reporting this module via {@code Module-Name} / {@code Module-Version}.
 */
final class BotbyePhishingClient
{
    use InitGuard;

    /** The only params a request may contribute to the fetch — attribution, nothing that picks the asset. */
    private const FORWARDABLE_PARAMS = ['module_name', 'module_version'];

    private LoggerInterface $logger;

    /**
     * @param (callable(mixed): BotbyePhishingRequestInfo)|null $requestInfoExtractor maps a raw framework
     *        request to a {@see BotbyePhishingRequestInfo}; lets {@see fetchCatcher} take that request.
     */
    public function __construct(
        private readonly BotbyePhishingConfig $config,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        ?LoggerInterface $logger = null,
        private readonly ?Closure $requestInfoExtractor = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->ensureInited();
    }

    /**
     * Framework SDKs: bind a request-info extractor so callers pass only their raw request.
     *
     * @param callable(mixed): BotbyePhishingRequestInfo $requestInfoExtractor
     */
    public static function withExtractor(
        BotbyePhishingConfig $config,
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        callable $requestInfoExtractor,
        ?LoggerInterface $logger = null,
    ): self {
        return new self(
            $config,
            $httpClient,
            $requestFactory,
            $logger,
            Closure::fromCallable($requestInfoExtractor),
        );
    }

    /**
     * Fetch the catcher asset: {@see BotbyePhishingCatcher::png()} is the 1×1 pixel,
     * {@see BotbyePhishingCatcher::svg()} the wrapper that makes the browser fetch it.
     *
     * @param BotbyePhishingCatcher $catcher Which asset, and its parameters — the SVG one cannot be
     *        built without its {@code $innerPngUrl}.
     * @param mixed $origin The {@code Origin} header value, or a raw framework request the bound
     *        extractor reads (headers and query alike), or a {@see BotbyePhishingRequestInfo} built
     *        yourself. A string or null is the header; anything else goes through the extractor.
     * @param ?string $referer Pass next to an {@code $origin} header value: an
     *        {@code <object data="…svg">} pixel sends no {@code Origin}. Ignored for the other two
     *        forms, which carry their own.
     */
    public function fetchCatcher(
        BotbyePhishingCatcher $catcher,
        mixed $origin = null,
        ?string $referer = null,
    ): BotbyePhishingResponse {
        $info = match (true) {
            $origin instanceof BotbyePhishingRequestInfo => $origin,
            $origin === null || is_string($origin) => new BotbyePhishingRequestInfo($origin, $referer),
            default => $this->extractRequestInfo($origin),
        };

        $catcherQuery = ['format' => $catcher->format] + self::forwardable($info->query);

        if ($catcher->isSvg()) {
            $catcherQuery['image_id'] = trim((string) $catcher->innerPngUrl);
            $catcherQuery['executable'] = $catcher->skipExecution ? 'false' : 'true';
        }

        return $this->fetchAsset($info->origin, $info->referer, $catcherQuery);
    }

    private function extractRequestInfo(mixed $request): BotbyePhishingRequestInfo
    {
        if ($this->requestInfoExtractor === null) {
            throw new \Botbye\Common\BotbyeException(
                '[BotBye] no phishing extractor configured; use BotbyePhishingClient::withExtractor() to fetch from a raw request'
            );
        }

        $info = ($this->requestInfoExtractor)($request);

        // Checked, not left to the typehint: a v3 extractor returned a string, and the TypeError that
        // produces is an Error, so it slips past every catch (Exception).
        if (!$info instanceof BotbyePhishingRequestInfo) {
            throw new \Botbye\Common\BotbyeException(
                '[BotBye] phishing requestInfoExtractor must return a BotbyePhishingRequestInfo'
            );
        }

        return $info;
    }

    /**
     * Whitelist, not blacklist: the endpoint is public, so a control param the route adds later must not
     * become forwardable by default.
     *
     * A repeated param arrives as an array (`?module_name[]=a`), and http_build_query would put that on
     * the wire as `module_name[0]=a` — not a param the route knows. So a nested value is flattened to its
     * first scalar, and anything else is dropped rather than sent as `module_name=Array`.
     *
     * @param array<string, mixed> $query the request's own query string, as a framework hands it over
     * @return array<string, string>
     */
    private static function forwardable(array $query): array
    {
        $forwardable = [];

        foreach (self::FORWARDABLE_PARAMS as $key) {
            $value = $query[$key] ?? null;

            if (is_array($value)) {
                $value = $value === [] ? null : reset($value);
            }

            if (is_string($value) || is_int($value) || is_float($value)) {
                $forwardable[$key] = (string) $value;
            }
        }

        return $forwardable;
    }

    private static function errorStatus(string $message): int
    {
        return $message === BotbyeErrors::TIMEOUT_ERROR ? 504 : 502;
    }

    /** @param array<string, string> $query */
    private function fetchAsset(?string $origin, ?string $referer, array $query): BotbyePhishingResponse
    {
        $conf = $this->config;
        $url = $conf->endpoint
            . '/api/v1/phishing/image/' . rawurlencode($conf->clientKey) . '/server';

        if (!empty($query)) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $this->fetchPhishingAsset($url, $origin, $referer);
    }

    private function fetchPhishingAsset(
        string $url,
        ?string $origin,
        ?string $referer,
    ): BotbyePhishingResponse {
        try {
            $request = $this->requestFactory->createRequest('GET', $url)
                ->withHeader('Module-Name', ModuleInfo::NAME)
                ->withHeader('Module-Version', ModuleInfo::VERSION);

            // Only forward a header when the caller has a real value
            if (!self::isMissingHeaderValue($origin)) {
                $request = $request->withHeader('Origin', trim((string)$origin));
            }

            if (!self::isMissingHeaderValue($referer)) {
                $request = $request->withHeader('Referer', trim((string)$referer));
            }

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

            $message = ErrorClassifier::classify($e->getMessage());

            return new BotbyePhishingResponse(
                status: self::errorStatus($message),
                error: new BotbyeError($message),
            );
        }
    }

    /**
     * Unusable when absent, blank, the literal "null", or carrying a control char: PSR-7 would throw and
     * cost the whole pixel. Also blocks CR/LF injection.
     */
    private static function isMissingHeaderValue(?string $value): bool
    {
        if ($value === null || trim($value) === '' || strcasecmp(trim($value), 'null') === 0) {
            return true;
        }

        return preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 1;
    }

    /**
     * Once per process per (endpoint, clientKey), via {@see InitGuard}. The blocking POST runs outside
     * the guard's file lock.
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
     * Reports the server-side phishing integration to the backend. Best-effort: never blocks the request.
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
