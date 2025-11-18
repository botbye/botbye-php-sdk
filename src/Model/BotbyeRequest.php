<?php

declare(strict_types=1);

namespace Botbye\Model;

use JsonSerializable;

final readonly class BotbyeRequest implements JsonSerializable
{
    /**
     * @param array<string, string> $customFields
     */
    public function __construct(
        public string $serverKey,
        public Headers $headers,
        public ConnectionDetails $requestInfo,
        public array $customFields = [],
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'server_key' => $this->serverKey,
            'headers' => $this->headers,
            'request_info' => $this->requestInfo,
            'custom_fields' => empty($this->customFields) ? (object)[] : $this->customFields,
        ];
    }
}
