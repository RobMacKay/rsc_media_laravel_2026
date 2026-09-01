<?php

namespace Tests\Support;

use App\Support\Sites\Certificate;
use App\Support\Sites\CertificateInspector;
use Carbon\CarbonInterface;

/**
 * Stands in for a real TLS handshake in tests.
 */
class FakeCertificateInspector implements CertificateInspector
{
    public function __construct(private Certificate $result) {}

    /**
     * Return a certificate that expires on the given day.
     */
    public static function expiring(CarbonInterface $expiresAt, string $issuer = "Let's Encrypt"): self
    {
        return new self(new Certificate(
            valid: $expiresAt->isFuture(),
            expiresAt: $expiresAt,
            issuer: $issuer,
        ));
    }

    /**
     * Return a result for a site with no usable certificate.
     */
    public static function missing(string $error = 'The site did not present a certificate.'): self
    {
        return new self(Certificate::missing($error));
    }

    /**
     * Read the TLS certificate a host is serving.
     */
    public function inspect(string $host, int $port = 443): Certificate
    {
        return $this->result;
    }
}
