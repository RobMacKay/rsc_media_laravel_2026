<?php

namespace App\Support\Sites;

use Carbon\CarbonInterface;

/**
 * What we learned about a site's TLS certificate.
 */
class Certificate
{
    public function __construct(
        public bool $valid,
        public ?CarbonInterface $expiresAt = null,
        public ?string $issuer = null,
        public ?string $error = null,
    ) {}

    /**
     * Build the result for a site that has no usable certificate.
     */
    public static function missing(?string $error = null): self
    {
        return new self(valid: false, error: $error);
    }
}
