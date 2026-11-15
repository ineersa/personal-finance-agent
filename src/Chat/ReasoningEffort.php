<?php

declare(strict_types=1);

namespace App\Chat;

enum ReasoningEffort: string
{
    case None = 'none';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case ExtraHigh = 'xhigh';
    case Max = 'max';

    /** Hatfield Cyberpunk theme's thinking-level palette. */
    public function color(): string
    {
        return match ($this) {
            self::None => '#4a5568',
            self::Low => '#00ffff',
            self::Medium => '#ff00ff',
            self::High => '#ff3366',
            self::ExtraHigh => '#ff0000',
            self::Max => '#9214fa',
        };
    }
}
