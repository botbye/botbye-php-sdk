<?php

declare(strict_types=1);

namespace Botbye\Model;

use JsonSerializable;

final readonly class BotbyeError implements JsonSerializable
{
    public function __construct(
        public string $message,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'message' => $this->message,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            message: $data['message'] ?? '',
        );
    }
}
