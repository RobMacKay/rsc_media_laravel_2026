<?php

namespace App\Support\Sites;

interface CertificateInspector
{
    /**
     * Read the TLS certificate a host is serving.
     */
    public function inspect(string $host, int $port = 443): Certificate;
}
