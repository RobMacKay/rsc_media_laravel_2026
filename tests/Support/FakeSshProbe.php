<?php

namespace Tests\Support;

use App\Support\Sites\SshProbe;
use App\Support\Sites\SshResult;

/**
 * Stands in for knocking on a real SSH port in tests.
 */
class FakeSshProbe implements SshProbe
{
    public function __construct(private SshResult $result) {}

    /**
     * Answer as a healthy SSH server would.
     */
    public static function answering(string $banner = 'SSH-2.0-OpenSSH_9.6p1 Ubuntu-3ubuntu13'): self
    {
        return new self(new SshResult(reachable: true, banner: $banner));
    }

    /**
     * Answer as a host with nothing listening would.
     */
    public static function silent(string $error = 'Connection refused'): self
    {
        return new self(SshResult::unreachable($error));
    }

    /**
     * Knock on a host's SSH port and see what answers.
     */
    public function probe(string $host, int $port = 22): SshResult
    {
        return $this->result;
    }
}
