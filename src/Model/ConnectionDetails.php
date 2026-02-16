<?php

declare(strict_types=1);

namespace Botbye\Model;

use JsonSerializable;

final class ConnectionDetails implements JsonSerializable
{
    public function __construct(
        public readonly string $remoteAddr,
        public readonly string $requestMethod,
        public readonly string $requestUri,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'remote_addr' => $this->remoteAddr,
            'request_method' => $this->requestMethod,
            'request_uri' => $this->requestUri,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            remoteAddr: $data['remote_addr'] ?? '',
            requestMethod: $data['request_method'] ?? '',
            requestUri: $data['request_uri'] ?? '',
        );
    }
}
