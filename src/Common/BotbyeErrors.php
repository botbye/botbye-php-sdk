<?php

declare(strict_types=1);

namespace Botbye\Common;

/**
 * Normalized error messages surfaced in {@see BotbyeError::$message} when an evaluation falls back
 * open. Mirror the BotBye node-core error codes so messages are consistent across SDKs.
 */
final class BotbyeErrors
{
    public const SDK_ERROR = 'SDK error';
    public const UNKNOWN_ERROR = 'unknown error';
    public const TIMEOUT_ERROR = 'timeout';
    public const CONNECTION_ERROR = 'connection error';
    public const JSON_ERROR = 'invalid json response';
}
