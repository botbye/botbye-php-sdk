<?php

declare(strict_types=1);

namespace Botbye\Model;

use JsonSerializable;

final class BotbyeEvaluateConfig implements JsonSerializable
{
    public function __construct(
        public readonly bool $bypassBotValidation = false,
    ) {
    }

    public function jsonSerialize(): array
    {
        return ['bypass_bot_validation' => $this->bypassBotValidation];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            bypassBotValidation: $data['bypass_bot_validation'] ?? false,
        );
    }
}
