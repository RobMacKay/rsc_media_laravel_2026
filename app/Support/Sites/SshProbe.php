<?php

namespace App\Support\Sites;

interface SshProbe
{
    /**
     * Knock on a host's SSH port and see what answers.
     */
    public function probe(string $host, int $port = 22): SshResult;
}
