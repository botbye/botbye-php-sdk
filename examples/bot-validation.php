<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Botbye\Client\BotbyeClient;
use Botbye\Client\BotbyeConfig;
use Botbye\Model\Decision;
use Botbye\Model\BotbyeValidationEvent;

// Initialize the client with any PSR-18 HTTP client (Guzzle, Symfony HttpClient, Buzz, etc.)
// Example with Guzzle:
//   composer require guzzlehttp/guzzle
//   $httpClient = new \GuzzleHttp\Client(['timeout' => 2.0]);
//   $psr17Factory = new \GuzzleHttp\Psr7\HttpFactory();
//
// Example with Symfony HttpClient + PSR-18 adapter:
//   composer require symfony/http-client nyholm/psr7
//   $httpClient = (new \Symfony\Component\HttpClient\Psr18Client());
//   $psr17Factory = new \Nyholm\Psr7\Factory\Psr17Factory();

$config = new BotbyeConfig(serverKey: 'your-server-key-here'); // from https://app.botbye.com
$client = new BotbyeClient(
    config: $config,
    httpClient: $httpClient,          // PSR-18 ClientInterface
    requestFactory: $psr17Factory,    // PSR-17 RequestFactoryInterface
    streamFactory: $psr17Factory,     // PSR-17 StreamFactoryInterface
);

// Flatten multi-value headers to comma-joined strings
$headers = array_map(
    fn($v) => is_array($v) ? implode(', ', $v) : $v,
    getallheaders() ?: ['User-Agent' => 'Mozilla/5.0']
);

// Level 1: Bot validation (proxy / pre-authentication)
$request = new BotbyeValidationEvent(
    ip: $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    token: $_SERVER['HTTP_X_BOTBYE_TOKEN'] ?? '',
    headers: $headers,
    requestMethod: $_SERVER['REQUEST_METHOD'] ?? 'GET',
    requestUri: $_SERVER['REQUEST_URI'] ?? '/',
);

$response = $client->evaluate($request);

// Propagate result to Level 2 via X-Botbye-Result header
header(BotbyeClient::RESULT_HEADER . ': ' . $client->encodeResult($response));

match ($response->decision) {
    Decision::ALLOW => print("✅ Request allowed (risk score: {$response->riskScore})\n"),
    Decision::CHALLENGE => print("⚠️ Challenge required\n"),
    Decision::BLOCK => (function () use ($response) {
        http_response_code(403);
        print("❌ Request blocked (risk score: {$response->riskScore})\n");
    })(),
};

if ($response->error !== null) {
    error_log('[BotBye] evaluation error: ' . $response->error->message);
}

// Extra device data (must be enabled in project dashboard)
if ($response->extraData !== null) {
    echo "IP: {$response->extraData->ip}, Country: {$response->extraData->country}\n";
}
