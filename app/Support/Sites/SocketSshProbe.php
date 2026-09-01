<?php

namespace App\Support\Sites;

/**
 * Opens a plain TCP connection and reads the greeting an SSH server sends.
 *
 * That is enough to answer "is SSH working": the port is open, something is
 * listening, and it identifies itself as an SSH server. It does not attempt to
 * authenticate — the studio holds no keys for a client's server and should not.
 */
class SocketSshProbe implements SshProbe
{
    public function __construct(private int $timeoutSeconds = 8) {}

    /**
     * Knock on a host's SSH port and see what answers.
     */
    public function probe(string $host, int $port = 22): SshResult
    {
        $client = @stream_socket_client(
            'tcp://'.$host.':'.$port,
            $errorCode,
            $errorMessage,
            $this->timeoutSeconds,
        );

        if ($client === false) {
            return SshResult::unreachable($errorMessage ?: __('Nothing answered on port :port.', ['port' => $port]));
        }

        try {
            stream_set_timeout($client, $this->timeoutSeconds);

            $banner = fgets($client, 512);

            if ($banner === false || trim($banner) === '') {
                return SshResult::unreachable(__('The port is open but said nothing.'));
            }

            $banner = trim($banner);

            if (! str_starts_with($banner, 'SSH-')) {
                return SshResult::unreachable(__('Something is listening on port :port, but it is not SSH.', ['port' => $port]));
            }

            return new SshResult(reachable: true, banner: $banner);
        } finally {
            fclose($client);
        }
    }
}
