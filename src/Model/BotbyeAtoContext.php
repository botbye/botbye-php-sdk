<?php

declare(strict_types=1);

namespace Botbye\Model;

use DateTimeImmutable;
use DateTimeInterface;
use JsonSerializable;

final readonly class BotbyeAtoContext implements JsonSerializable
{
    /**
     * @param array<string, string>|null $customFields
     */
    public function __construct(
        public BotbyeUserInfo $userInfo,
        public string $remoteAddr,
        public Headers $headers,
        public EventType $eventType,
        public EventStatus $eventStatus,
        public DateTimeImmutable $createdAt,
        public ?array $customFields = null,
    ) {
    }

    public function jsonSerialize(): array
    {
        $data = [
            'user_info' => $this->userInfo,
            'remote_addr' => $this->remoteAddr,
            'headers' => $this->headers,
            'event_type' => $this->eventType->value,
            'event_status' => $this->eventStatus->value,
            'created_at' => $this->createdAt->format(DateTimeInterface::ATOM),
        ];

        if ($this->customFields !== null) {
            $data['custom_fields'] = empty($this->customFields) ? (object)[] : $this->customFields;
        }

        return $data;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            userInfo: BotbyeUserInfo::fromArray($data['user_info'] ?? []),
            remoteAddr: $data['remote_addr'] ?? '',
            headers: Headers::fromArray($data['headers'] ?? []),
            eventType: EventType::from($data['event_type'] ?? 'CUSTOM'),
            eventStatus: EventStatus::from($data['event_status'] ?? 'PENDING'),
            createdAt: new DateTimeImmutable($data['created_at'] ?? 'now'),
            customFields: $data['custom_fields'] ?? null,
        );
    }
}
