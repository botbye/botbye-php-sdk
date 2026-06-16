<?php

declare(strict_types=1);

namespace Botbye\Phishing;

use Botbye\Common\BotbyeError;
use Botbye\Common\ErrorClassifier;
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
    private static bool $inited = false;

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

            $token = $this->processToken();
            $needsInit = true;

            if (is_file($flagFile)) {
                $content = (string)@file_get_contents($flagFile);
                if (preg_match('/^token:(.+)$/m', $content, $m)) {
                    if ($m[1] === $token) {
                        $needsInit = false;
                    }
                }
            }

            self::$inited = true;

            if ($needsInit) {
                $this->initRequest();
                @file_put_contents($flagFile, "token:$token\nat:" . time() . "\n", LOCK_EX);
            }
        } finally {
            @flock($lockHandle, LOCK_UN);
            @fclose($lockHandle);
        }
    }

    /**
     * Identifies this process incarnation for the init guard. The pid alone is not enough: a process
     * manager (or a container, where the SAPI is always pid 1) reuses pids across restarts, so a stale
     * guard file left in a persistent temp dir would suppress the handshake for the new process. Pairing
     * the pid with the kernel process start time (field 22 of {@code /proc/self/stat}) makes the token
     * unique per incarnation; on platforms without procfs it degrades to pid-only.
     */
    private function processToken(): string
    {
        $pid = (string)getmypid();

        $stat = @file_get_contents('/proc/self/stat');
        if ($stat !== false) {
            // comm (field 2) is parenthesised and may contain spaces, so parse after the final ')':
            // the remaining whitespace-separated fields start at field 3 (state), making starttime
            // (field 22) the element at index 19.
            $rparen = strrpos($stat, ')');
            if ($rparen !== false) {
                $rest = preg_split('/\s+/', trim(substr($stat, $rparen + 1)));
                if (is_array($rest) && isset($rest[19]) && $rest[19] !== '') {
                    return $pid . '-' . $rest[19];
                }
            }
        }

        return $pid;
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
