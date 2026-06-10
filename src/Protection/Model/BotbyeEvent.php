<?php

declare(strict_types=1);

namespace Botbye\Protection\Model;

use JsonSerializable;

/**
 * Sealed contract for all evaluate event types.
 * Implementations: BotbyeValidationEvent, BotbyeRiskScoringEvent, BotbyeFullEvent.
 */
interface BotbyeEvent extends JsonSerializable
{
    public function getType(): string;

    /**
     * Token to append as URL query param (?token).
     * Null for risk-only events that don't carry a device token.
     */
    public function getUrlToken(): ?string;
}
