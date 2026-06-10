<?php

declare(strict_types=1);

namespace Botbye\Protection\Model;

use Botbye\Common\BotbyeError;

final class BotbyeEvaluateResponse implements \JsonSerializable
{
    public function __construct(
        public readonly ?string $requestId = null,
        public readonly Decision $decision = Decision::ALLOW,
        public readonly ?float $riskScore = null,
        public readonly ?array $signals = null,
        public readonly ?array $scores = null,
        public readonly ?BotbyeChallenge $challenge = null,
        public readonly ?BotbyeExtraData $extraData = null,
        public readonly ?BotbyeError $error = null,
        public readonly ?string $botbyeResult = null,
    ) {
    }

    public function isBlocked(): bool
    {
        return $this->decision === Decision::BLOCK;
    }

    public function jsonSerialize(): array
    {
        $data = [
            'decision' => $this->decision->value,
        ];
        if ($this->requestId !== null) {
            $data['request_id'] = $this->requestId;
        }
        if ($this->riskScore !== null) {
            $data['risk_score'] = $this->riskScore;
        }
        if ($this->signals !== null) {
            $data['signals'] = $this->signals;
        }
        if ($this->scores !== null) {
            $data['scores'] = empty($this->scores) ? new \stdClass() : $this->scores;
        }
        if ($this->challenge !== null) {
            $data['challenge'] = $this->challenge->jsonSerialize();
        }
        if ($this->extraData !== null) {
            $data['extra_data'] = $this->extraData->jsonSerialize();
        }
        if ($this->error !== null) {
            $data['error'] = $this->error->jsonSerialize();
        }
        if ($this->botbyeResult !== null) {
            $data['botbye_result'] = $this->botbyeResult;
        }
        return $data;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            requestId: $data['request_id'] ?? null,
            decision: Decision::tryFrom($data['decision'] ?? '') ?? Decision::ALLOW,
            riskScore: isset($data['risk_score']) ? (float)$data['risk_score'] : null,
            signals: $data['signals'] ?? null,
            scores: $data['scores'] ?? null,
            challenge: isset($data['challenge']) ? BotbyeChallenge::fromArray($data['challenge']) : null,
            extraData: isset($data['extra_data']) ? BotbyeExtraData::fromArray($data['extra_data']) : null,
            error: isset($data['error']) ? BotbyeError::fromArray($data['error']) : null,
            botbyeResult: $data['botbye_result'] ?? null,
        );
    }

    /**
     * Error/bypass fallback — returns ALLOW with the provided error message.
     */
    public static function bypass(string $errorMessage): self
    {
        return new self(
            error: new BotbyeError($errorMessage),
        );
    }
}
