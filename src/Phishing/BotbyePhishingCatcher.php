<?php

declare(strict_types=1);

namespace Botbye\Phishing;

use Botbye\Common\BotbyeException;

final class BotbyePhishingCatcher
{
    private function __construct(
        public readonly string $format,
        public readonly ?string $innerPngUrl,
        public readonly bool $skipExecution,
    ) {
    }

    public static function png(): self
    {
        return new self('png', null, true);
    }

    public static function svg(string $innerPngUrl, bool $skipExecution = true): self
    {
        if (trim($innerPngUrl) === '') {
            throw new BotbyeException(
                '[BotBye] BotbyePhishingCatcher::svg: $innerPngUrl must be a non-blank absolute http(s) URL'
            );
        }

        return new self('svg', $innerPngUrl, $skipExecution);
    }

    public function isSvg(): bool
    {
        return $this->format === 'svg';
    }
}
