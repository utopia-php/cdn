<?php

namespace Utopia\Cdn\Certificates;

class Status
{
    public const PENDING = 'pending';
    public const PROCESSING = 'processing';
    public const ISSUED = 'issued';
    public const RENEWING = 'renewing';
    public const FAILED = 'failed';
    public const UNKNOWN = 'unknown';

    public static function fromFastlyState(string $state): string
    {
        return match (\strtolower($state)) {
            'pending' => self::PENDING,
            'processing' => self::PROCESSING,
            'issued' => self::ISSUED,
            'renewing' => self::RENEWING,
            'failed' => self::FAILED,
            default => self::UNKNOWN,
        };
    }
}
