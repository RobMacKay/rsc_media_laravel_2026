<?php

namespace App\Contracts;

/**
 * A record files can be attached to.
 *
 * Tickets and projects both hang off a client business, and that is what
 * decides who may download a file, so the contract asks for it outright
 * rather than assuming implementers happen to have a team_id column.
 */
interface HasAttachments
{
    /**
     * Get the client business this record belongs to.
     */
    public function teamId(): int;
}
