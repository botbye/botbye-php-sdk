<?php

declare(strict_types=1);

namespace Botbye\Protection\Model;

/**
 * HTTP request context produced by a request extractor (see {@see \Botbye\Protection\BotbyeClient::withExtractor}).
 * A framework integration maps its request object (PSR-7 ServerRequestInterface, Laravel/Symfony
 * Request, ...) to this once; the client then builds the matching event from it.
 *
 * @phpstan-type Headers array<string, string>
 */
final class BotbyeRequestInfo
{
    /**
     * @param array<string, string> $headers Flat header map (multi-values pre-joined with ", ")
     */
    public function __construct(
        public readonly string $ip,
        public readonly array $headers = [],
        public readonly ?string $token = null,
        public readonly ?string $requestMethod = null,
        public readonly ?string $requestUri = null,
    ) {
    }
}
