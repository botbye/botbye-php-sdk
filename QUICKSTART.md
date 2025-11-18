# Botbye PHP SDK - Quick Start Guide

## Installation

```bash
composer require botbye/botbye-php-sdk
```

## 1. Bot Detection (5 minutes)

### Step 1: Initialize Client

Make sure to replace `your-server-key` (available inside your [Projects](https://botbye.com/docs/dashboard/project)):

```php
<?php
use Botbye\Client\BotbyeClient;
use Botbye\Client\BotbyeConfig;

$client = new BotbyeClient(
    new BotbyeConfig(serverKey: 'your-server-key')
);
```

### Step 2: Validate Request

```php
use Botbye\Model\ConnectionDetails;
use Botbye\Model\Headers;

// Make sure to replace the `botbye_token` with the one received from a botbye client
$response = $client->validateRequest(
    token: $_GET['botbye_token'] ?? null,           // for example, the client attaches the botbye_token as a query param
    connectionDetails: new ConnectionDetails(
        remoteAddr: $_SERVER['REMOTE_ADDR'],
        requestMethod: $_SERVER['REQUEST_METHOD'],
        requestUri: $_SERVER['REQUEST_URI']
    ),
    headers: Headers::fromArray(getallheaders())
);
```

### Step 3: Check Result

```php
if ($response->result?->isAllowed === false) {
    http_response_code(403);
    die('Access denied');
}

// Continue with your application
```

## Common Patterns

### With PSR-3 Logger

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('botbye');
$logger->pushHandler(new StreamHandler('php://stderr'));

$client = new BotbyeClient($config, null, $logger);
```

### Custom HTTP Client

```php
use Symfony\Component\HttpClient\HttpClient;

$httpClient = HttpClient::create(['timeout' => 2]);
$client = new BotbyeClient($config, $httpClient);
```

## Testing

Run the included examples:

### Bot detection

Start the PHP built-in web server:

```bash
php -S localhost:8000 -t path/to/your/project
```

Then send a request to:

```bash
http://localhost:8000/examples/validate-request.php
```

For the Botbye token, you can pass it through a custom HTTP header.
In this example, we use the X-Custom-Token header to send a Botbye token:

```bash
curl --location 'http://localhost:8000/examples/validate-request.php' \
  --header 'X-Custom-Token: botbye_token'
```

## Framework Integration

### Laravel Middleware

```php
// app/Http/Middleware/BotbyeMiddleware.php
public function handle($request, Closure $next)
{
    $response = $this->botbye->validateRequest(
        token: $request->query('botbye_token'),
        connectionDetails: new ConnectionDetails(
            remoteAddr: $request->ip(),
            requestMethod: $request->method(),
            requestUri: $request->getRequestUri()
        ),
        headers: Headers::fromArray($request->headers->all())
    );

    if (!$response->result?->isAllowed) {
        abort(403);
    }

    return $next($request);
}
```

### Symfony Event Subscriber

```php
// src/EventSubscriber/BotbyeSubscriber.php
public function onKernelRequest(RequestEvent $event): void
{
    $request = $event->getRequest();
    
    $response = $this->botbye->validateRequest(
        token: $request->query->get('botbye_token'),
        connectionDetails: new ConnectionDetails(
            remoteAddr: $request->getClientIp(),
            requestMethod: $request->getMethod(),
            requestUri: $request->getRequestUri()
        ),
        headers: Headers::fromArray($request->headers->all())
    );

    if (!$response->result?->isAllowed) {
        throw new AccessDeniedHttpException();
    }
}
```

## Troubleshooting

### Server key is not specified
Make sure you pass a non-empty server key to `BotbyeConfig`.

### Timeout errors
Increase timeout values in config:
```php
new BotbyeConfig(
    serverKey: 'key',
    max_duration: 5.0
)
```

### JSON decode errors
Check that the Botbye endpoint is correct and responding with valid JSON.

## Next Steps

- Read the full [README.md](README.md)
- Review examples in `examples/` directory
- Run the test suite

## Support

- Documentation: https://botbye.com/docs
- Email: [accounts@botbye.com](mailto:accounts@botbye.com)
- GitHub: https://github.com/botbye
