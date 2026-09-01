<?php

namespace App\Support\Sites;

/**
 * What we learned from knocking on a host's SSH port.
 */
class SshResult
{
    public function __construct(
        public bool $reachable,
        public ?string $banner = null,
        public ?string $error = null,
    ) {}

    /**
     * Build the result for a port that did not answer.
     */
    public static function unreachable(?string $error = null): self
    {
        return new self(reachable: false, error: $error);
    }

    /**
     * Get the server version out of the banner, e.g. "OpenSSH_9.6p1".
     *
     * The banner is "SSH-2.0-OpenSSH_9.6p1 Ubuntu-3ubuntu13", of which the
     * middle part is the only bit worth showing anyone.
     */
    public function serverVersion(): ?string
    {
        if ($this->banner === null) {
            return null;
        }

        preg_match('/^SSH-\d+\.\d+-(\S+)/', trim($this->banner), $matches);

        return $matches[1] ?? null;
    }
}
