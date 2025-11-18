<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Botbye\Client\BotbyeClient;
use Botbye\Client\BotbyeConfig;
use Botbye\Model\ConnectionDetails;
use Botbye\Model\Headers;

// Initialize the client
$config = new BotbyeConfig(
    serverKey: 'your-server-key-here'
);

$client = new BotbyeClient($config);

// Prepare request data
$connectionDetails = new ConnectionDetails(
    remoteAddr: $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    requestMethod: $_SERVER['REQUEST_METHOD'] ?? 'GET',
    requestUri: $_SERVER['REQUEST_URI'] ?? '/'
);

$headers = Headers::fromArray(getallheaders() ?: [
    'User-Agent' => 'Mozilla/5.0',
    'Accept' => 'text/html',
]);

// Validate the request
try {
    $response = $client->validateRequest(
        token: getallheaders()['X-Custom-Token'] ?? null,
        connectionDetails: $connectionDetails,
        headers: $headers,
        customFields: [
            'user_id' => '12345',
        ]
    );

    // Check if the request is allowed
    if ($response->result !== null) {
        if ($response->result->isAllowed) {
            echo "✅ Request is allowed\n";
            echo "Request ID: $response->reqId\n";

            // if you want to receive extra data, you need to enable it in your project dashboard https://botbye.com/docs/dashboard/project
            // by default extra data is disabled and will not be returned in the response
            if ($response->extraData !== null) {
                echo "\nExtra Data:\n";
                echo "  IP: {$response->extraData->ip}\n";
                echo "  Country: {$response->extraData->country}\n";
                echo "  Browser: {$response->extraData->browser}\n";
                echo "  Device: {$response->extraData->deviceName}\n";
            }
        } else {
            echo "❌ Request is blocked\n";
            echo "Request ID: $response->reqId\n";
            echo "Reason: {$response->error->message}\n";
            http_response_code(403);
        }
    } else {
        echo $response->error->message;
    }
} catch (Exception $e) {
    echo "Exception: {$e->getMessage()}\n";
    exit(1);
}
