<?php
/**
 * Analyze ATO Context Example (Coming Soon)
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Botbye\Client\BotbyeClient;
use Botbye\Client\BotbyeConfig;
use Botbye\Model\BotbyeAtoContext;
use Botbye\Model\BotbyeUserInfo;
use Botbye\Model\Decision;
use Botbye\Model\EventStatus;
use Botbye\Model\EventType;
use Botbye\Model\Headers;

// Initialize the client
$config = new BotbyeConfig(
    serverKey: 'your-server-key-here'
);

$client = new BotbyeClient($config);

// Prepare user info
$userInfo = new BotbyeUserInfo(
    accountId: '12345',
    username: 'john_doe',
    email: 'john@example.com',
    phone: '+1234567890'
);

// Create ATO context
$atoContext = new BotbyeAtoContext(
    userInfo: $userInfo,
    remoteAddr: $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    headers: Headers::fromArray(getallheaders() ?: [
        'User-Agent' => 'Mozilla/5.0',
        'Accept' => 'text/html',
    ]),
    eventType: EventType::LOGIN,
    eventStatus: EventStatus::SUCCESSFUL,
    createdAt: new DateTimeImmutable(),
    customFields: [
        'device_id' => 'device-123',
        'ip_country' => 'US',
    ]
);

// Analyze the context
try {
    $response = $client->analyze(
        token: $_GET['botbye_token'] ?? null,
        atoContext: $atoContext
    );

    // Check for errors
    if ($response->error !== null) {
        echo "Error: {$response->error->message}\n";
        exit(1);
    }

    // Handle the decision
    if ($response->result !== null) {
        echo "Decision: {$response->result->decision->value}\n";
        
        if ($response->result->reason !== null) {
            echo "Reason: {$response->result->reason}\n";
        }

        $decisionMessage = match ($response->result->decision) {
            Decision::ALLOW => "\n✅ User is allowed to proceed\n",
            Decision::BLOCK => "\n❌ User is blocked\n",
            Decision::MFA => "\n🔐 Multi-factor authentication required\n",
            Decision::CHALLENGE => "\n🤖 Challenge required (CAPTCHA)\n",
            Decision::IN_PROGRESS => "\n⏳ Analysis in progress\n",
        };
        echo $decisionMessage;
    }
} catch (Exception $e) {
    echo "Exception: {$e->getMessage()}\n";
    exit(1);
}
