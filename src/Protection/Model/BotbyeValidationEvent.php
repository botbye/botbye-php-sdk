<?php

declare(strict_types=1);

namespace Botbye\Protection\Model;

/**
 * Level 1: Bot validation (proxy, pre-authentication).
 * Validates device token and returns bot score. No user context.
 *
 * Wire type discriminator: "validate"
 */
final class BotbyeValidationEvent implements BotbyeEvent
{
    /**
     * @param array<string, string> $headers    Flat header map (multi-values pre-joined with ", ")
     * @param array<string, string> $customFields
     */
    public function __construct(
        public readonly string $ip,
        public readonly string $token,
        public readonly array $headers,
        public readonly ?string $requestMethod = null,
        public readonly ?string $requestUri = null,
        public readonly array $customFields = [],
    ) {
    }

    public function getType(): string
    {
        return 'validate';
    }

    public function getUrlToken(): ?string
    {
        return $this->token;
    }

    public function jsonSerialize(): array
    {
        $request = [
            'ip' => $this->ip,
            'token' => $this->token,
            'headers' => empty($this->headers) ? (object)[] : $this->headers,
        ];
        if ($this->requestMethod !== null) {
            $request['request_method'] = $this->requestMethod;
        }
        if ($this->requestUri !== null) {
            $request['request_uri'] = $this->requestUri;
        }

        return [
            'type' => 'validate',
            'request' => $request,
            'config' => ['bypass_bot_validation' => false],
            'custom_fields' => empty($this->customFields) ? (object)[] : $this->customFields,
        ];
    }
}
