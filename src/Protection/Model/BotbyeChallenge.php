<?php

declare(strict_types=1);

namespace Botbye\Protection\Model;

use JsonSerializable;

final class BotbyeChallenge implements JsonSerializable
{
    public function __construct(
        public readonly string $type,
    ) {
    }

    public function jsonSerialize(): array
    {
        return ['type' => $this->type];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            type: $data['type'] ?? '',
        );
    }
}
