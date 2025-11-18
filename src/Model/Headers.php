<?php

declare(strict_types=1);

namespace Botbye\Model;

use JsonSerializable;

final readonly class Headers implements JsonSerializable
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        public array $headers,
    ) {
    }

    public function jsonSerialize(): array
    {
        return array_map(function ($values) {
            return implode(', ', $values);
        }, $this->headers);
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public static function fromArray(array $headers): self
    {
        $normalized = array_map(function ($value) {
            return is_array($value) ? $value : [$value];
        }, $headers);
        return new self($normalized);
    }
}
