<?php

declare(strict_types=1);

namespace Botbye\Phishing;

use Botbye\Common\BotbyeError;
use Botbye\Common\ErrorClassifier;
use Exception;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Phishing-only client. Authenticates with the public {@see BotbyePhishingConfig::$clientKey}
 * embedded in the URL path — it needs no server key and performs no init handshake, so it can be
 * constructed independently of the evaluate {@see \Botbye\Protection\BotbyeClient}.
 */
final class BotbyePhishingClient
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly BotbyePhishingConfig $config,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function fetchImage(?string $origin, ?string $imageId = null): BotbyePhishingResponse
    {
        $conf = $this->config;
        $url = $conf->endpoint
            . '/api/v1/phishing/image/' . rawurlencode($conf->clientKey);

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
            return new BotbyePhishingResponse(error: new BotbyeError(ErrorClassifier::classify($e->getMessage())));
        }
    }
}
