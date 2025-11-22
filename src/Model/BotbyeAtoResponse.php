<?php

declare(strict_types=1);

namespace Botbye\Model;

use JsonSerializable;

final class BotbyeAtoResponse implements JsonSerializable
{
    public function __construct(
        public readonly ?BotbyeAtoResult $result = null,
        public readonly ?BotbyeError $error = null,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'result' => $this->result,
            'error' => $this->error,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            result: isset($data['result']) ? BotbyeAtoResult::fromArray($data['result']) : new BotbyeAtoResult(),
            error: isset($data['error']) ? BotbyeError::fromArray($data['error']) : null,
        );
    }
}
