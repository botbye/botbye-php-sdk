<?php

declare(strict_types=1);

namespace Botbye\Model;

use JsonSerializable;

final class BotbyeAtoResult implements JsonSerializable
{
    public function __construct(
        public readonly Decision $decision = Decision::ALLOW,
        public readonly ?string $reason = null,
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
