<?php

declare(strict_types=1);

namespace Botbye\Common;

/** Header helpers, mirroring BotBye node-core's {@code getIpFromHeaders}. */
final class HeaderUtils
{
    /**
     * Best-effort extraction of the client IP from request headers. Prefers the first hop of
     * {@code x-forwarded-for}, then falls back to {@code x-real-ip}. Lookup is case-insensitive.
     * Returns null when neither header is present.
     *
     * @param array<string, string> $headers
     */
    public static function getIpFromHeaders(array $headers): ?string
    {
        $lookup = [];
        foreach ($headers as $name => $value) {
            $lookup[strtolower((string)$name)] = $value;
        }

        if (isset($lookup['x-forwarded-for'])) {
            $firstHop = trim(explode(',', (string)$lookup['x-forwarded-for'])[0]);
            if ($firstHop !== '') {
                return $firstHop;
            }
        }

        if (isset($lookup['x-real-ip'])) {
            $realIp = trim((string)$lookup['x-real-ip']);
            if ($realIp !== '') {
                return $realIp;
            }
        }

        return null;
    }
}
