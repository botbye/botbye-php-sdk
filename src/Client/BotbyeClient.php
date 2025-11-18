<?php

declare(strict_types=1);

namespace Botbye\Client;

use Botbye\Exception\BotbyeException;
use Botbye\Model\BotbyeAtoContext;
use Botbye\Model\BotbyeAtoResponse;
use Botbye\Model\BotbyeError;
use Botbye\Model\BotbyeRequest;
use Botbye\Model\BotbyeValidatorResponse;
use Botbye\Model\ConnectionDetails;
use Botbye\Model\Headers;
use Exception;
use JsonException;
use JsonSerializable;
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
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;

    public function __construct(
        private BotbyeConfig $config,
        ?HttpClientInterface $httpClient = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->httpClient = $httpClient ?? $this->createDefaultHttpClient();
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Validate incoming request for bot detection
     *
     * @param array<string, string> $customFields
     */
    public function validateRequest(
        ?string $token,
        ConnectionDetails $connectionDetails,
        Headers $headers,
        array $customFields = [],
    ): BotbyeValidatorResponse {
        $url = sprintf(
            '%s/validate-request/v2?%s',
            $this->config->botbyeEndpoint,
            $token ?? ''
        );

        $request = new BotbyeRequest(
            serverKey: $this->config->serverKey,
            headers: $headers,
            requestInfo: $connectionDetails,
            customFields: $customFields,
        );

        try {
            $response = $this->sendRequest($url, $request);
            return BotbyeValidatorResponse::fromArray($response);
        } catch (Exception $e) {
            $this->logger->warning('[BotBye] exception occurred: ' . $e->getMessage());
            return new BotbyeValidatorResponse(
                error: new BotbyeError('[BotBye] failed to sendRequest: ' . $e->getMessage())
            );
        }
    }

    /**
     * Analyze context for account takeover protection
     */
    public function analyze(
        ?string $token,
        BotbyeAtoContext $atoContext,
    ): BotbyeAtoResponse {
        $url = sprintf(
            '%s/analyze-context/v1?%s',
            $this->config->botbyeEndpoint,
            $token ?? ''
        );

        try {
            $response = $this->sendRequest($url, $atoContext);
            return BotbyeAtoResponse::fromArray($response);
        } catch (Exception $e) {
            $this->logger->warning('[BotBye] exception occurred: ' . $e->getMessage());
            return new BotbyeAtoResponse(
                error: new BotbyeError('[BotBye] failed to sendRequest: ' . $e->getMessage())
            );
        }
    }

    /**
     * Update configuration
     */
    public function setConfig(BotbyeConfig $config): void
    {
        $this->config = $config;
        $this->httpClient = $this->createDefaultHttpClient();
    }

    /**
     * @return array<string, mixed>
     * @throws BotbyeException
     */
    private function sendRequest(string $url, JsonSerializable $body): array
    {
        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => $this->buildHeaders(),
                'json' => $body,
                'timeout' => $this->config->timeout,
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);

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

    /**
     * @return array<string, string>
     */
    private function buildHeaders(): array
    {
        return [
            'Content-Type' => $this->config->contentType,
            'Module-Name' => BotbyeConfig::MODULE_NAME,
            'Module-Version' => BotbyeConfig::MODULE_VERSION,
            'X-Botbye-Server-Key' => $this->config->serverKey,
        ];
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
