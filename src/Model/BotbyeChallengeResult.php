<?php

declare(strict_types=1);

namespace Botbye\Model;

use JsonSerializable;

final class BotbyeChallengeResult implements JsonSerializable
{
    public function __construct(
        public readonly bool $isAllowed = true,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'isAllowed' => $this->isAllowed,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            isAllowed: $data['isAllowed'] ?? true,
        );
    }
}
