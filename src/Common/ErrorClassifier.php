<?php

declare(strict_types=1);

namespace Botbye\Common;

/** Maps an exception message to one of the normalized {@see BotbyeErrors} messages. */
final class ErrorClassifier
{
    public static function classify(string $message): string
    {
        $lower = strtolower($message);
        if (str_contains($lower, 'timeout') || str_contains($lower, 'timed out') || str_contains($lower, 'idle')) {
            return BotbyeErrors::TIMEOUT_ERROR;
        }
        if (str_contains($lower, 'transport') || str_contains($lower, 'connect') || str_contains($lower, 'refused')
            || str_contains($lower, 'empty reply') || str_contains($lower, 'reset')
            || str_contains($lower, 'end of stream') || str_contains($lower, 'closed')) {
            return BotbyeErrors::CONNECTION_ERROR;
        }
        if (str_contains($lower, 'json') || str_contains($lower, 'decode') || str_contains($lower, 'parse')
            || str_contains($lower, 'invalid')) {
            return BotbyeErrors::JSON_ERROR;
        }
        return BotbyeErrors::UNKNOWN_ERROR;
    }
}
