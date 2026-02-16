<?php

declare(strict_types=1);

namespace Botbye\Model;

use JsonSerializable;

final class BotbyeRequest implements JsonSerializable
{
    /**
     * @param array<string, string> $customFields
     */
    public function __construct(
        public readonly string $serverKey,
        public readonly Headers $headers,
        public readonly ConnectionDetails $requestInfo,
        public readonly array $customFields = [],
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
