<?php

declare(strict_types=1);

namespace Botbye\Model;

use JsonSerializable;

final class BotbyeValidatorResponse implements JsonSerializable
{
    public function __construct(
        public readonly ?BotbyeChallengeResult $result = null,
        public readonly string $reqId = '00000000-0000-0000-0000-000000000000',
        public readonly ?BotbyeError $error = null,
        public readonly ?BotbyeExtraData $extraData = null,
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
