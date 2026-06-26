<?php

declare(strict_types=1);

namespace Botbye\Common;

/**
 * Identity of this SDK build. Sent on every BotBye request via the Module-Name / Module-Version
 * headers and embedded in evaluate event payloads. Shared by the protection and phishing clients,
 * so it lives in {@see \Botbye\Common} rather than in either client's config.
 */
final class ModuleInfo
{
    public const NAME = 'PHP';
    public const VERSION = '3.0.1';
}
