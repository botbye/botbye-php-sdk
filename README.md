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
use Botbye\Protection\BotbyeClient;
use Botbye\Protection\BotbyeConfig;
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
use Botbye\Protection\BotbyeClient;
use Botbye\Protection\BotbyeConfig;
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
use Botbye\Protection\Model\BotbyeValidationEvent;
use Botbye\Common\Headers;

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
use Botbye\Protection\Model\BotbyeRiskScoringEvent;
use Botbye\Protection\Model\BotbyeUserInfo;
use Botbye\Protection\Model\EventStatus;
use Botbye\Protection\Model\Decision;

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
use Botbye\Protection\Model\BotbyeFullEvent;

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
markup, the pixel is requested with the clone's `Origin` — or, where the pixel is embedded as
`<object data="…svg">` and no `Origin` is sent at all, with its `Referer`. Either header names the
page, which is what lets BotBye record a phishing candidate.

Phishing lives in its own dedicated `BotbyePhishingClient` — **separate from the evaluate
`BotbyeClient`**. The project is identified by a public, browser-safe `clientKey` in the URL path,
so the client needs **no server key**; you can construct it standalone (it only needs a PSR-18 client
and a PSR-17 request factory). On construction it fires a best-effort server-integration init
handshake (`POST /api/v1/phishing/init-request/v1/{clientKey}`, guarded to run once per process)
reporting this module, and `fetchCatcher` proxies the asset via the server `/server` route so
the backend can attribute it to this module even when the browser never reaches BotBye directly.

```php
use Botbye\Phishing\BotbyePhishingCatcher;
use Botbye\Phishing\BotbyePhishingClient;
use Botbye\Phishing\BotbyePhishingConfig;

$phishing = new BotbyePhishingClient(
    new BotbyePhishingConfig(
        endpoint: 'https://verify.botbye.com', // default
        clientKey: '<public-client-key>',
    ),
    $httpClient,     // PSR-18 ClientInterface
    $requestFactory, // PSR-17 RequestFactoryInterface
);

// One method; the catcher you pass picks the asset. Pass Referer next to Origin — an SVG pixel embedded
// as <object data="…svg"> sends no Origin, so Referer is the only header naming the page.
$res = $phishing->fetchCatcher(
    BotbyePhishingCatcher::png(),
    $_SERVER['HTTP_ORIGIN'] ?? null,
    $_SERVER['HTTP_REFERER'] ?? null,
);

// The SVG names the URL it embeds as the nested pixel (point it at your own PNG endpoint so that fetch
// proxies through your origin too — BotBye honours it only as an absolute http(s) URL). It is a required
// argument of svg(), not an optional parameter, so an SVG without one cannot be constructed.
$svg = $phishing->fetchCatcher(
    // svg($url, false) opts into the script-carrying variant; svg($url) is script-less, no JS on the page.
    BotbyePhishingCatcher::svg('https://your-site.example/example.png'),
    $_SERVER['HTTP_ORIGIN'] ?? null,
    $_SERVER['HTTP_REFERER'] ?? null,
);

$res->status;   // 200
$res->headers;  // ['Content-Type' => 'image/png', ...] — image/svg+xml for the SVG response
$res->body;     // string — raw image bytes to relay back to the browser
$res->error;    // ?BotbyeError — non-null on transport failure
```

`format`, `image_id` and `executable` are set by the call and never read off the request: the endpoint
you expose is public, so a query on it must not be able to redirect the nested pixel fetch or pick the
SVG variant behind your back. Only `module_name` and `module_version` pass through, and only via the
extractor below. `executable` is always sent, never omitted, so the variant never rides on the backend's
default for a missing param, and `$skipExecution` defaults to `true` — the script-less SVG. A blank
`$innerPngUrl` — the one thing the signature cannot rule out — is rejected by
`BotbyePhishingCatcher::svg()` itself.

`fetchCatcher` returns `BotbyePhishingResponse`:

| Field | Type | Description |
|---|---|---|
| `status` | `int` | Upstream HTTP status. A transport failure has none, so it reports the gateway status it means: `504` for a timeout, `502` for anything else |
| `headers` | `array` | Response headers (e.g. `Content-Type`) |
| `body` | `string` | Raw image bytes (PNG or SVG, per the catcher you passed) |
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
| `challenge` | `?BotbyeChallenge` | Challenge type (when decision is `CHALLENGE`) |
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

## Request Extractors (framework integration)

Instead of building events field-by-field at every call site, describe **once** how to turn your
framework's request into a `BotbyeRequestInfo`, then pass only the raw request to the `evaluate*`
methods. Build the client with `BotbyeClient::withExtractor(...)` — the extractor is any
`callable(mixed): BotbyeRequestInfo`:

```php
use Botbye\Protection\BotbyeClient;
use Botbye\Protection\Model\BotbyeRequestInfo;
use Botbye\Common\Headers;
use Illuminate\Http\Request;

$client = BotbyeClient::withExtractor(
    config: $config,
    httpClient: $httpClient,
    requestFactory: $psr17Factory,
    streamFactory: $psr17Factory,
    requestInfoExtractor: fn (Request $request) => new BotbyeRequestInfo(
        ip: $request->ip(),
        headers: Headers::fromArray($request->headers->all())->jsonSerialize(),
        token: $request->query('botbye_token'),
        requestMethod: $request->method(),
        requestUri: $request->getRequestUri(),
    ),
);
```

Now call sites pass only the raw request (plus user/event for Level 2):

```php
use Botbye\Protection\Model\BotbyeUserInfo;
use Botbye\Protection\Model\EventStatus;

// Level 1 — bot validation
$l1 = $client->evaluateValidation($request);

// Level 2 — risk scoring & event logging
$l2 = $client->evaluateRiskScoring(
    request: $request,
    user: new BotbyeUserInfo(accountId: $userId),
    eventType: 'LOGIN',
    eventStatus: EventStatus::SUCCESSFUL,
    botbyeResult: $l1->botbyeResult,
);

// Level 1+2 combined (no separate proxy)
$full = $client->evaluateFull($request, new BotbyeUserInfo(accountId: $userId), 'LOGIN', EventStatus::FAILED);
```

An explicit `token` argument overrides the one returned by the extractor. The explicit-event API
(`new BotbyeClient(...)` + `evaluate(new BotbyeValidationEvent(...))`) remains available with no
extractor.

### Laravel Middleware

```php
namespace App\Http\Middleware;

use Botbye\Protection\BotbyeClient;
use Closure;
use Illuminate\Http\Request;

class BotbyeMiddleware
{
    public function __construct(private BotbyeClient $botbye) {}

    public function handle(Request $request, Closure $next)
    {
        if ($this->botbye->evaluateValidation($request)->isBlocked()) {
            abort(403, 'Access denied');
        }

        return $next($request);
    }
}
```

Register the extractor-bound `BotbyeClient` in a service provider:

```php
// AppServiceProvider.php
use Botbye\Protection\Model\BotbyeRequestInfo;
use Botbye\Common\Headers;
use Illuminate\Http\Request;

$this->app->singleton(BotbyeClient::class, function ($app) {
    $httpClient = new \GuzzleHttp\Client(['timeout' => 2.0]);
    $factory = new \GuzzleHttp\Psr7\HttpFactory();

    return BotbyeClient::withExtractor(
        config: new BotbyeConfig(serverKey: config('services.botbye.key')),
        httpClient: $httpClient,
        requestFactory: $factory,
        streamFactory: $factory,
        requestInfoExtractor: fn (Request $request) => new BotbyeRequestInfo(
            ip: $request->ip(),
            headers: Headers::fromArray($request->headers->all())->jsonSerialize(),
            token: $request->query('botbye_token'),
            requestMethod: $request->method(),
            requestUri: $request->getRequestUri(),
        ),
    );
});
```

### Symfony Event Subscriber

```php
namespace App\EventSubscriber;

use Botbye\Protection\BotbyeClient;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

// Build $botbye via BotbyeClient::withExtractor(..., requestInfoExtractor: fn (Request $r) => new BotbyeRequestInfo(...))
class BotbyeSubscriber implements EventSubscriberInterface
{
    public function __construct(private BotbyeClient $botbye) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => 'onKernelRequest'];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if ($this->botbye->evaluateValidation($event->getRequest())->isBlocked()) {
            throw new AccessDeniedHttpException('Access denied');
        }
    }
}
```

### Phishing from a raw request

The phishing client mirrors the same pattern — bind an extractor once, then pass the raw request to
`fetchCatcher` where the `Origin` header value would go. One entry point either way: a string or `null`
there is the header, anything else is the request the extractor reads.

```php
use Botbye\Phishing\BotbyePhishingCatcher;
use Botbye\Phishing\BotbyePhishingClient;
use Botbye\Phishing\BotbyePhishingConfig;
use Botbye\Phishing\BotbyePhishingRequestInfo;

$phishing = BotbyePhishingClient::withExtractor(
    config: new BotbyePhishingConfig(clientKey: '<public-client-key>'),
    httpClient: $httpClient,
    requestFactory: $requestFactory,
    requestInfoExtractor: fn ($request) => new BotbyePhishingRequestInfo(
        origin: $request->headers->get('Origin'),
        referer: $request->headers->get('Referer'),
        query: $request->getQueryParams(),
    ),
);

// Origin, Referer and the attribution params all via the extractor — the single place that reads the
// request
$res = $phishing->fetchCatcher(BotbyePhishingCatcher::png(), $request);
$svg = $phishing->fetchCatcher(
    BotbyePhishingCatcher::svg('https://your-site.example/example.png'),
    $request,
);
```

## Helpers

| Helper | Description |
|---|---|
| `BotbyeEvaluateResponse::bypass($message)` | Build a fail-open response (`ALLOW` + `error`) for your own short-circuit paths. |
| `BotbyeErrors` | Normalized error message constants: `SDK_ERROR`, `UNKNOWN_ERROR`, `TIMEOUT_ERROR`, `CONNECTION_ERROR`, `JSON_ERROR`. |

## Testing

```bash
composer install
vendor/bin/phpunit
```

## License

MIT

## Support

For support, visit [botbye.com](https://botbye.com) or contact [accounts@botbye.com](mailto:accounts@botbye.com).
