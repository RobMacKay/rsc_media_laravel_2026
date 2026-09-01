<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Refuse anything that is not a public website.
 *
 * Clients type these in and the server then fetches them on a schedule, so
 * without this the monitor is a way to probe whatever the server can reach —
 * other machines on the private network, cloud metadata endpoints, localhost.
 */
class PubliclyRoutableUrl implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, string|null=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $host = is_string($value) ? parse_url($value, PHP_URL_HOST) : null;

        if (! is_string($host) || $host === '') {
            $fail(__('That does not look like a web address.'));

            return;
        }

        // A name with no dot cannot be a public domain, and covers "localhost"
        // and any internal short name at the same time.
        if (! str_contains($host, '.') && filter_var($host, FILTER_VALIDATE_IP) === false) {
            $fail(__('Use a full domain, like braemarjoinery.co.uk.'));

            return;
        }

        foreach ($this->addressesFor($host) as $address) {
            if (! $this->isPublic($address)) {
                $fail(__('That address is not reachable from the public internet.'));

                return;
            }
        }
    }

    /**
     * Get every address the host resolves to, or the host itself when it is
     * already an IP.
     *
     * A name that does not resolve is left to the check itself to report as
     * down, rather than being refused at the point someone adds it.
     *
     * @return array<int, string>
     */
    private function addressesFor(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $records = gethostbynamel($host);

        return $records === false ? [] : $records;
    }

    /**
     * Determine whether an address is on the public internet.
     */
    private function isPublic(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
