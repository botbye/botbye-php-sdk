<?php

declare(strict_types=1);

namespace Botbye\Protection\Model;

/**
 * Combined Level 1+2: Bot validation + risk scoring in a single call.
 * Use when there is no separate proxy layer.
 *
 * Wire type discriminator: "full"
 */
final class BotbyeFullEvent implements BotbyeEvent
{
    /**
     * @param array<string, string> $headers     Flat header map (multi-values pre-joined with ", ")
     * @param array<string, string> $customFields
     */
    public function __construct(
        public readonly string $ip,
        public readonly string $token,
        public readonly array $headers,
        public readonly BotbyeUserInfo $user,
        public readonly string $eventType,
        public readonly EventStatus $eventStatus,
        public readonly ?string $requestMethod = null,
        public readonly ?string $requestUri = null,
        public readonly array $customFields = [],
    ) {
    }

    public function getType(): string
    {
        return 'full';
    }

    public function getUrlToken(): ?string
    {
        return $this->token !== '' ? $this->token : null;
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
            'type' => 'full',
            'request' => $request,
            'event' => [
                'type' => $this->eventType,
                'status' => $this->eventStatus->value,
            ],
            'user' => $this->user,
            'config' => ['bypass_bot_validation' => false],
            'custom_fields' => empty($this->customFields) ? (object)[] : $this->customFields,
        ];
    }
}
