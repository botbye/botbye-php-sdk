<?php

declare(strict_types=1);

namespace Botbye\Model;

use JsonSerializable;

final readonly class BotbyeAtoResult implements JsonSerializable
{
    public function __construct(
        public Decision $decision = Decision::ALLOW,
        public ?string $reason = null,
    ) {
    }

    public function jsonSerialize(): array
    {
        return array_filter([
            'decision' => $this->decision->value,
            'reason' => $this->reason,
        ], fn($value) => $value !== null);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            decision: Decision::from($data['decision'] ?? 'ALLOW'),
            reason: $data['reason'] ?? null,
        );
    }
}
