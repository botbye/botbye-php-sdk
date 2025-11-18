<?php

declare(strict_types=1);

namespace Botbye\Model;

use JsonSerializable;

final readonly class BotbyeValidatorResponse implements JsonSerializable
{
    public function __construct(
        public ?BotbyeChallengeResult $result = null,
        public string $reqId = '00000000-0000-0000-0000-000000000000',
        public ?BotbyeError $error = null,
        public ?BotbyeExtraData $extraData = null,
    ) {
    }

    public function jsonSerialize(): array
    {
        $data = [
            'result' => $this->result,
            'reqId' => $this->reqId,
            'error' => $this->error,
        ];

        if ($this->extraData !== null) {
            $data['extraData'] = $this->extraData;
        }

        return $data;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            result: isset($data['result']) ? BotbyeChallengeResult::fromArray($data['result']) : new BotbyeChallengeResult(),
            reqId: $data['reqId'] ?? '00000000-0000-0000-0000-000000000000',
            error: isset($data['error']) ? BotbyeError::fromArray($data['error']) : null,
            extraData: isset($data['extraData']) ? BotbyeExtraData::fromArray($data['extraData']) : null,
        );
    }
}
