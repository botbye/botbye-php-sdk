# BotBye PHP SDK

PHP SDK for the [BotBye](https://botbye.com) Unified Protection Platform — unifying fraud prevention and real-time event monitoring in one platform.

BotBye goes beyond fixed bot/ATO checks. Risk dimensions and metrics are fully dynamic — you define what to measure and what rules to apply per project. This means the same platform covers bot detection, account takeover, multi-accounting, payment fraud, promotion abuse, or any custom fraud scenario specific to your business.

## Requirements

- PHP 8.1 or higher
- Composer
- Any PSR-18 compatible HTTP client (Guzzle, Symfony HttpClient, Buzz, etc.)

## Installation

```bash
composer require botbye/botbye-php-sdk
```

You also need a PSR-18 HTTP client and PSR-17 factories. Install one of the ready-made implementations:

**Guzzle** (most common):
```bash
composer require guzzlehttp/guzzle
```

**Symfony HttpClient**:
```bash
composer require symfony/http-client nyholm/psr7
```

**Buzz**:
```bash
composer require kriswallsmith/buzz nyholm/psr7
```

Or write a **custom adapter** for your HTTP transport (e.g. WordPress `wp_remote_request`) — in that case you only need a PSR-17 factory:
```bash
composer require nyholm/psr7
```

## Overview

The SDK provides three request types for different integration levels:

| Request Type | Use Case | Where It Runs |
|---|---|---|
| `BotbyeValidationEvent` | **Level 1** — Bot filtering | Proxy or middleware, before user identity is known |
| `BotbyeRiskScoringEvent` | **Level 2** — Risk scoring & event logging | Application layer, when user identity is known |
| `BotbyeFullEvent` | **Level 1+2 combined** | Application layer when no separate proxy exists |

All requests go to a single endpoint (`POST /api/v1/protect/evaluate`) and return a unified response with a decision (`ALLOW`, `CHALLENGE`, `BLOCK`), risk scores per dimension, and triggered signals. Dimensions are dynamic — the platform ships with built-in ones (`bot`, `ato`, `abuse`) but you can define custom dimensions (e.g., `payment_fraud`, `promotion_abuse`) per project without code changes.

Every evaluation call is also recorded as a **protection event** — logged to the analytics pipeline and used to compute real-time metrics that feed the rules engine. Metrics are fully configurable per project: the platform ships with built-in ones (failed logins, distinct IPs per account, device reuse, etc.) and you can define custom metrics for your specific use case (e.g., "failed transactions over $1000 per account in 1 hour"). This means `BotbyeRiskScoringEvent` serves a dual purpose: it both evaluates risk **and** logs the event for future analysis and metric aggregation.

## Quick Start

### 1. Initialize the Client

The SDK uses PSR-18 / PSR-17 interfaces — bring your own HTTP client and message factories.

**With Guzzle:**

```php
use Botbye\Client\BotbyeClient;
use Botbye\Client\BotbyeConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;

$config = new BotbyeConfig(
    serverKey: 'your-server-key' // from https://botbye.com/docs/dashboard/project
);

$httpClient = new Client(['timeout' => 2.0]);
$psr17Factory = new HttpFactory();

$client = new BotbyeClient(
    config: $config,
    httpClient: $httpClient,
    requestFactory: $psr17Factory,
    streamFactory: $psr17Factory,
);
```

**With Symfony HttpClient:**

```php
use Symfony\Component\HttpClient\Psr18Client;
use Nyholm\Psr7\Factory\Psr17Factory;

$httpClient = new Psr18Client();
$psr17Factory = new Psr17Factory();

$client = new BotbyeClient(
    config: $config,
    httpClient: $httpClient,
    requestFactory: $psr17Factory,
    streamFactory: $psr17Factory,
);
```

**Custom adapter** (e.g. WordPress `wp_remote_request`):

```php
use Botbye\Client\BotbyeClient;
use Botbye\Client\BotbyeConfig;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

// Wrap any HTTP transport as a PSR-18 client
class WpHttpClient implements ClientInterface
{
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $response = wp_remote_request((string) $request->getUri(), [
            'method'  => $request->getMethod(),
            'headers' => array_map(
                fn(array $v) => implode(', ', $v),
                $request->getHeaders(),
            ),
            'body'    => (string) $request->getBody(),
            'timeout' => 2,
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException($response->get_error_message());
        }

        $psr17 = new Psr17Factory();
        $psrResponse = $psr17->createResponse(
            wp_remote_retrieve_response_code($response),
        );

        return $psrResponse->withBody(
            $psr17->createStream(wp_remote_retrieve_body($response)),
        );
    }
}

$psr17Factory = new Psr17Factory();

$client = new BotbyeClient(
    config: $config,
    httpClient: new WpHttpClient(),
    requestFactory: $psr17Factory,
    streamFactory: $psr17Factory,
);
```

### 2. Bot Validation (Level 1)

Validate device tokens where user identity is not yet available — at the proxy layer or in a middleware before authentication.

```php
use Botbye\Model\BotbyeValidationEvent;
use Botbye\Model\Headers;

$headers = Headers::fromArray(getallheaders());

$response = $client->evaluate(new BotbyeValidationEvent(
    ip: $_SERVER['REMOTE_ADDR'],
    token: $_GET['botbye_token'] ?? '',
    headers: $headers->jsonSerialize(),
    requestMethod: $_SERVER['REQUEST_METHOD'],
    requestUri: $_SERVER['REQUEST_URI'],
));

if ($response->isBlocked()) {
    http_response_code(403);
    exit('Access denied');
}
```

### 3. Risk Scoring & Event Logging (Level 2)

Evaluate risk and log events when user identity is known. Each call both scores the request **and** feeds the real-time metrics engine, so you should call `evaluate()` for every significant user action — not just when you need a decision.

```php
use Botbye\Model\BotbyeRiskScoringEvent;
use Botbye\Model\BotbyeUserInfo;
use Botbye\Model\EventStatus;
use Botbye\Model\Decision;

$response = $client->evaluate(new BotbyeRiskScoringEvent(
    ip: $_SERVER['REMOTE_ADDR'],
    headers: $headers->jsonSerialize(),
    user: new BotbyeUserInfo(
        accountId: $userId,
        email: $userEmail,       // optional
        phone: $userPhone,       // optional
    ),
    eventType: 'LOGIN',
    eventStatus: EventStatus::SUCCESSFUL,
    botbyeResult: $_SERVER['HTTP_X_BOTBYE_RESULT'] ?? null, // from Level 1
));

match ($response->decision) {
    Decision::BLOCK     => abort(403),
    Decision::CHALLENGE => showChallenge($response->challenge),
    Decision::ALLOW     => continueRequest(),
};
```

When `botbyeResult` is `null` (no Level 1 upstream), bot validation is automatically bypassed.

#### Event Types

`eventType` is an arbitrary string — the server accepts any value. Pass any string that matches your business domain:

```php
'LOGIN'
'REGISTRATION'
'TRANSACTION'
'BONUS_CLAIM'
'PASSWORD_RESET'
'WITHDRAWAL'
```

#### Using Level 2 for Event Logging

Even when you don't need to act on the decision, sending events builds the metrics profile for the account. This enables rules like "more than 5 failed logins in 10 minutes" or "distinct devices per account in 1 hour":

```php
// Log a failed login attempt — feeds metrics even if you don't act on the decision
$client->evaluate(new BotbyeRiskScoringEvent(
    ip: $_SERVER['REMOTE_ADDR'],
    headers: $headers->jsonSerialize(),
    user: new BotbyeUserInfo(accountId: $userId),
    eventType: 'LOGIN',
    eventStatus: EventStatus::FAILED,
));

// Log a custom business event
$client->evaluate(new BotbyeRiskScoringEvent(
    ip: $_SERVER['REMOTE_ADDR'],
    headers: $headers->jsonSerialize(),
    user: new BotbyeUserInfo(accountId: $userId),
    eventType: 'BONUS_CLAIM',
    eventStatus: EventStatus::SUCCESSFUL,
    customFields: ['bonus_id' => 'welcome_100'],
));
```

### 4. Full Evaluation (Level 1+2 Combined)

Use when there is no separate proxy layer — validates the device token and evaluates risk in a single call.

```php
use Botbye\Model\BotbyeFullEvent;

$response = $client->evaluate(new BotbyeFullEvent(
    ip: $_SERVER['REMOTE_ADDR'],
    token: $_GET['botbye_token'] ?? '',
    headers: $headers->jsonSerialize(),
    user: new BotbyeUserInfo(accountId: $userId),
    eventType: 'LOGIN',
    eventStatus: EventStatus::FAILED,
));
```

### 5. Phishing Image Tracking

The phishing tracking pixel is embedded on a protected site; when a phishing clone copies the
markup, the pixel is requested with the clone's `Origin`, which lets BotBye record a phishing
candidate.

The project is identified by a public, browser-safe `clientKey` in the URL path, so **no server
key is sent** — phishing uses its own client key, separate from the evaluate `serverKey`.

Configure phishing once (independently of the evaluate config), then forward the incoming `Origin`:

```php
use Botbye\Client\BotbyePhishingConfig;

$client->setPhishingConfig(new BotbyePhishingConfig(
    endpoint: 'https://verify.botbye.com', // default
    clientKey: '<public-client-key>',
));

// Default PNG pixel
$res = $client->fetchImage($_SERVER['HTTP_ORIGIN'] ?? null);

// SVG variant — pass an imageId
$svg = $client->fetchImage($_SERVER['HTTP_ORIGIN'] ?? null, 'hero-banner');

$res->status;   // 200
$res->headers;  // ['Content-Type' => 'image/png', ...]
$res->body;     // string — raw image bytes to relay back to the browser
$res->error;    // ?BotbyeError — non-null on transport failure
```

`fetchImage` returns `BotbyePhishingResponse`:

| Field | Type | Description |
|---|---|---|
| `status` | `int` | Upstream HTTP status (`0` on transport failure) |
| `headers` | `array` | Response headers (e.g. `Content-Type`) |
| `body` | `string` | Raw image bytes (PNG, or SVG when `imageId` is set) |
| `error` | `?BotbyeError` | Normalized transport error: `timeout`, `connection error`, or `invalid json response` |

## Response

`BotbyeEvaluateResponse` contains:

| Field | Type | Description |
|---|---|---|
| `requestId` | `?string` | Request UUID |
| `decision` | `Decision` | `ALLOW`, `CHALLENGE`, or `BLOCK` |
| `riskScore` | `?float` | Overall risk score (0–1) |
| `scores` | `?array` | Per-dimension scores (`bot`, `ato`, `abuse`, ...) |
| `signals` | `?array` | Triggered signal names (e.g., `BruteForce`, `ImpossibleTravel`) |
| `challenge` | `?BotbyeChallenge` | Challenge type and token (when decision is `CHALLENGE`) |
| `extraData` | `?BotbyeExtraData` | Enriched device data (IP, country, browser, device, etc.) |
| `error` | `?BotbyeError` | Error details (on fallback) |
| `botbyeResult` | `?string` | Encoded result for Level 1→2 propagation |

```php
$response->decision;              // Decision::ALLOW
$response->isBlocked();           // false
$response->riskScore;             // 0.72
$response->scores;                // ['bot' => 0.15, 'ato' => 0.72, 'abuse' => 0.05]
$response->signals;               // ['BruteForce', 'ImpossibleTravel']
$response->challenge?->type;      // 'captcha'
$response->extraData?->country;   // 'US'
```

## Level 1 to Level 2 Propagation

When using both levels, propagate the Level 1 result to Level 2 via the `botbyeResult` field from the response. This allows the platform to link both evaluations by `requestId` and combine bot score from Level 1 with risk scores from Level 2 into a single unified result:

```php
// Level 1 (proxy) — validate and get result
$l1Response = $client->evaluate(new BotbyeValidationEvent(...));

// Pass botbyeResult to Level 2 (e.g. via header or directly)
$l2Response = $client->evaluate(new BotbyeRiskScoringEvent(
    // ...
    botbyeResult: $l1Response->botbyeResult,
));
```

## Configuration

```php
$config = new BotbyeConfig(
    serverKey: 'your-server-key', // from https://app.botbye.com
    botbyeEndpoint: 'https://verify.botbye.com', // default
);
```

Timeouts are configured on the HTTP client you provide:

```php
// Guzzle
$httpClient = new \GuzzleHttp\Client(['timeout' => 2.0, 'connect_timeout' => 1.0]);

// Symfony
$httpClient = new Psr18Client(HttpClient::create(['timeout' => 2.0, 'max_duration' => 3.0]));
```

### PSR-3 Logger

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('botbye');
$logger->pushHandler(new StreamHandler('/var/log/botbye.log', Logger::WARNING));

$client = new BotbyeClient(
    config: $config,
    httpClient: $httpClient,
    requestFactory: $psr17Factory,
    streamFactory: $psr17Factory,
    logger: $logger,
);
```

## Error Handling

The SDK follows a **fail-open** strategy. On network or server errors, `evaluate()` returns a default response (`Decision::ALLOW` with error details) instead of throwing:

```php
$response = $client->evaluate($event);

if ($response->error !== null) {
    // Evaluation failed, request was allowed by default
    log($response->error->message);
}
```

`BotbyeException` is only thrown for unrecoverable errors during `sendPayload()` — the client catches these internally and returns the bypass response.

## Framework Integration

### Laravel Middleware

```php
namespace App\Http\Middleware;

use Botbye\Client\BotbyeClient;
use Botbye\Model\BotbyeValidationEvent;
use Botbye\Model\Headers;
use Closure;
use Illuminate\Http\Request;

class BotbyeMiddleware
{
    public function __construct(private BotbyeClient $botbye) {}

    public function handle(Request $request, Closure $next)
    {
        $headers = Headers::fromArray($request->headers->all());

        $response = $this->botbye->evaluate(new BotbyeValidationEvent(
            ip: $request->ip(),
            token: $request->query('botbye_token', ''),
            headers: $headers->jsonSerialize(),
            requestMethod: $request->method(),
            requestUri: $request->getRequestUri(),
        ));

        if ($response->isBlocked()) {
            abort(403, 'Access denied');
        }

        return $next($request);
    }
}
```

Register the `BotbyeClient` in a service provider:

```php
// AppServiceProvider.php
$this->app->singleton(BotbyeClient::class, function ($app) {
    $httpClient = new \GuzzleHttp\Client(['timeout' => 2.0]);
    $factory = new \GuzzleHttp\Psr7\HttpFactory();

    return new BotbyeClient(
        config: new BotbyeConfig(serverKey: config('services.botbye.key')),
        httpClient: $httpClient,
        requestFactory: $factory,
        streamFactory: $factory,
    );
});
```

### Symfony Event Subscriber

```php
namespace App\EventSubscriber;

use Botbye\Client\BotbyeClient;
use Botbye\Model\BotbyeValidationEvent;
use Botbye\Model\Headers;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

class BotbyeSubscriber implements EventSubscriberInterface
{
    public function __construct(private BotbyeClient $botbye) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => 'onKernelRequest'];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $headers = Headers::fromArray($request->headers->all());

        $response = $this->botbye->evaluate(new BotbyeValidationEvent(
            ip: $request->getClientIp(),
            token: $request->query->get('botbye_token', ''),
            headers: $headers->jsonSerialize(),
            requestMethod: $request->getMethod(),
            requestUri: $request->getRequestUri(),
        ));

        if ($response->isBlocked()) {
            throw new AccessDeniedHttpException('Access denied');
        }
    }
}
```

## Testing

```bash
composer install
vendor/bin/phpunit
```

## License

MIT

## Support

For support, visit [botbye.com](https://botbye.com) or contact [accounts@botbye.com](mailto:accounts@botbye.com).
