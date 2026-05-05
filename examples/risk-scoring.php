<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Botbye\Client\BotbyeClient;
use Botbye\Client\BotbyeConfig;
use Botbye\Model\BotbyeUserInfo;
use Botbye\Model\Decision;
use Botbye\Model\EventStatus;
use Botbye\Model\BotbyeRiskScoringEvent;

// Initialize the client with any PSR-18 HTTP client (Guzzle, Symfony HttpClient, Buzz, etc.)
$config = new BotbyeConfig(serverKey: 'your-server-key-here'); // from https://app.botbye.com
$client = new BotbyeClient(
    config: $config,
    httpClient: $httpClient,          // PSR-18 ClientInterface
    requestFactory: $psr17Factory,    // PSR-17 RequestFactoryInterface
    streamFactory: $psr17Factory,     // PSR-17 StreamFactoryInterface
);

$headers = array_map(
    fn($v) => is_array($v) ? implode(', ', $v) : $v,
    getallheaders() ?: ['User-Agent' => 'Mozilla/5.0']
);

$user = new BotbyeUserInfo(
    accountId: '12345',
    username: 'john_doe',
    email: 'john@example.com',
    phone: '+1234567890',
);

// Level 2: Risk scoring & event logging (when user identity is known).
// Pass X-Botbye-Result from Level 1 as botbyeResult to link both evaluations.
// If absent, bypass_bot_validation is set automatically.
$request = new BotbyeRiskScoringEvent(
    ip: $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    headers: $headers,
    user: $user,
    eventType: 'LOGIN',
    eventStatus: EventStatus::SUCCESSFUL,
    botbyeResult: $_SERVER['HTTP_X_BOTBYE_RESULT'] ?? null,
);

$response = $client->evaluate($request);

match ($response->decision) {
    Decision::ALLOW => print("✅ User allowed (risk score: {$response->riskScore})\n"),
    Decision::CHALLENGE => print("⚠️ Challenge required\n"),
    Decision::BLOCK => (function () use ($response) {
        http_response_code(403);
        print("❌ User blocked (risk score: {$response->riskScore})\n");
    })(),
};

if ($response->error !== null) {
    error_log('[BotBye] evaluation error: ' . $response->error->message);
}
