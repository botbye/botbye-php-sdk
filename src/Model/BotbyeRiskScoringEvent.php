<?php

declare(strict_types=1);

namespace Botbye\Model;

/**
 * Level 2: Risk scoring (middleware, post-authentication).
 * Evaluates ATO/abuse risk using user context and event data.
 * Bot score comes from Level 1 result passed via botbyeResult.
 *
 * When botbyeResult is absent (no Level 1 proxy), bypass_bot_validation is set
 * to true automatically — the server skips bot engine with score 0.0.
 *
 * Wire type discriminator: "risk"
 */
final class BotbyeRiskScoringEvent implements BotbyeEvent
{
    public readonly bool $bypassBotValidation;

    /**
     * @param array<string, string> $headers     Flat header map (multi-values pre-joined with ", ")
     * @param array<string, string> $customFields
     */
    public function __construct(
        public readonly string $ip,
        public readonly array $headers,
        public readonly BotbyeUserInfo $user,
        public readonly string $eventType,
        public readonly EventStatus $eventStatus,
        public readonly ?string $botbyeResult = null,
        public readonly array $customFields = [],
    ) {
        $this->bypassBotValidation = $this->botbyeResult === null || $this->botbyeResult === '';
    }

    public function getType(): string
    {
        return 'risk';
    }

    public function getUrlToken(): ?string
    {
        return null;
    }

    public function jsonSerialize(): array
    {
        $data = [
            'type' => 'risk',
            'request' => [
                'ip' => $this->ip,
                'headers' => empty($this->headers) ? (object)[] : $this->headers,
            ],
            'event' => [
                'type' => $this->eventType,
                'status' => $this->eventStatus->value,
            ],
            'user' => $this->user,
            'config' => ['bypass_bot_validation' => $this->bypassBotValidation],
            'custom_fields' => empty($this->customFields) ? (object)[] : $this->customFields,
        ];

        if (!$this->bypassBotValidation) {
            $data['botbye_result'] = $this->botbyeResult;
        }

        return $data;
    }
}
