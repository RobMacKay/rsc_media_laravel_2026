<?php

namespace App\Support\Sites;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Reads the certificate a host actually serves, by opening a TLS connection
 * and looking at what comes back.
 */
class OpenSslCertificateInspector implements CertificateInspector
{
    public function __construct(private int $timeoutSeconds = 8) {}

    /**
     * Read the TLS certificate a host is serving.
     */
    public function inspect(string $host, int $port = 443): Certificate
    {
        // Capture the certificate even when it fails verification, so an
        // expired or mismatched one can be reported rather than swallowed.
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ],
        ]);

        $client = @stream_socket_client(
            'ssl://'.$host.':'.$port,
            $errorCode,
            $errorMessage,
            $this->timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($client === false) {
            return Certificate::missing($errorMessage ?: __('Could not start a secure connection.'));
        }

        try {
            $params = stream_context_get_params($client);
            $peer = $params['options']['ssl']['peer_certificate'] ?? null;

            if ($peer === null) {
                return Certificate::missing(__('The site did not present a certificate.'));
            }

            $parsed = openssl_x509_parse($peer);

            if ($parsed === false || ! isset($parsed['validTo_time_t'])) {
                return Certificate::missing(__('The certificate could not be read.'));
            }

            $expiresAt = Carbon::createFromTimestampUTC((int) $parsed['validTo_time_t']);

            return new Certificate(
                valid: $expiresAt->isFuture(),
                expiresAt: $expiresAt,
                issuer: $parsed['issuer']['O'] ?? $parsed['issuer']['CN'] ?? null,
            );
        } catch (Throwable $e) {
            return Certificate::missing($e->getMessage());
        } finally {
            fclose($client);
        }
    }
}
