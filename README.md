# Botbye PHP SDK

Validate incoming requests for bot activity with PHP SDK for [Botbye](https://botbye.com).

## Requirements

- PHP 8.2 or higher
- Composer

## Installation

```bash
composer require botbye/botbye-php-sdk
```

## Quick Start

### 1. Initialize the Client

Make sure to replace `your-server-key` (available inside your [Projects](https://botbye.com/docs/dashboard/project)):

```php
<?php

use Botbye\Client\BotbyeClient;
use Botbye\Client\BotbyeConfig;

$config = new BotbyeConfig(
    serverKey: 'your-server-key'
);

$client = new BotbyeClient($config);
```

### 2. Validate Request (Bot Detection)

```php
<?php

use Botbye\Model\ConnectionDetails;
use Botbye\Model\Headers;

// Prepare request data
$connectionDetails = new ConnectionDetails(
    remoteAddr: $_SERVER['REMOTE_ADDR'],
    requestMethod: $_SERVER['REQUEST_METHOD'],
    requestUri: $_SERVER['REQUEST_URI']
);

$headers = Headers::fromArray(getallheaders());

/**
 * Validate the request
 * 
 * Make sure to replace the `botbye_token` with the one received from a botbye client
 */
$response = $client->validateRequest(
    token: $_GET['botbye_token'] ?? null,   // for example, the client attaches the botbye_token as a query param
    connectionDetails: $connectionDetails,
    headers: $headers,
    customFields: [                         // Optional custom fields for linking the request
        'user_id' => '12345',
        'session_id' => session_id(),
    ]
);

if ($response->result !== null && !$response->result->isAllowed) {
    http_response_code(403);
    echo 'Access denied';
    exit;
}

// Request is allowed, continue processing
echo 'Welcome!';
```

## Configuration

### Advanced Configuration

```php
<?php

use Botbye\Client\BotbyeConfig;

$config = new BotbyeConfig(
    serverKey: 'your-server-key',
    timeout: 1.0,       // the idle timeout (in seconds)
    max_duration: 2.0,  // the maximum execution time (in seconds) for the request+response as a whole
);
```

### Custom HTTP Client

```php
<?php

use Botbye\Client\BotbyeClient;
use Botbye\Client\BotbyeConfig;
use Symfony\Component\HttpClient\HttpClient;

$config = new BotbyeConfig(serverKey: 'your-server-key');

$httpClient = HttpClient::create([
    'timeout' => 2,
    'max_redirects' => 0,
]);

$client = new BotbyeClient($config, $httpClient);
```

### PSR-3 Logger Integration

```php
<?php

use Botbye\Client\BotbyeClient;
use Botbye\Client\BotbyeConfig;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$config = new BotbyeConfig(serverKey: 'your-server-key');

$logger = new Logger('botbye');
$logger->pushHandler(new StreamHandler('/var/log/botbye.log', Logger::WARNING));

$client = new BotbyeClient($config, null, $logger);
```

## Testing

Run the test suite:

```bash
composer install
vendor/bin/phpunit
```

## Framework Integration Examples

### Laravel

```php
<?php

namespace App\Http\Middleware;

use Botbye\Client\BotbyeClient;
use Botbye\Model\ConnectionDetails;
use Botbye\Model\Headers;
use Closure;
use Illuminate\Http\Request;

class BotbyeMiddleware
{
    public function __construct(
        private BotbyeClient $botbye
    ) {
    }

    public function handle(Request $request, Closure $next)
    {
        $connectionDetails = new ConnectionDetails(
            remoteAddr: $request->ip(),
            requestMethod: $request->method(),
            requestUri: $request->getRequestUri()
        );

        $headers = Headers::fromArray($request->headers->all());

        $response = $this->botbye->validateRequest(
            token: $request->query('botbye_token'),
            connectionDetails: $connectionDetails,
            headers: $headers
        );

        if ($response->result !== null && !$response->result->isAllowed) {
            abort(403, 'Access denied');
        }

        return $next($request);
    }
}
```

### Symfony

```php
<?php

namespace App\EventSubscriber;

use Botbye\Client\BotbyeClient;
use Botbye\Model\ConnectionDetails;
use Botbye\Model\Headers;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

class BotbyeSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private BotbyeClient $botbye
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onKernelRequest',
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        $connectionDetails = new ConnectionDetails(
            remoteAddr: $request->getClientIp(),
            requestMethod: $request->getMethod(),
            requestUri: $request->getRequestUri()
        );

        $headers = Headers::fromArray($request->headers->all());

        $response = $this->botbye->validateRequest(
            token: $request->query->get('botbye_token'),
            connectionDetails: $connectionDetails,
            headers: $headers
        );

        if ($response->result !== null && !$response->result->isAllowed) {
            throw new AccessDeniedHttpException('Access denied by Botbye');
        }
    }
}
```

## License

MIT

## Support

For support, please visit [https://botbye.com](https://botbye.com) or contact [accounts@botbye.com](mailto:accounts@botbye.com)
