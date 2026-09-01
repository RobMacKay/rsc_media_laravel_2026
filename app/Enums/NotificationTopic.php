<?php

namespace App\Enums;

enum NotificationTopic: string
{
    case Tickets = 'tickets';
    case Projects = 'projects';
    case Invoices = 'invoices';
    case Studio = 'studio';

    /**
     * Get the label for the toggle.
     */
    public function label(): string
    {
        return match ($this) {
            self::Tickets => 'Ticket replies',
            self::Projects => 'Project updates',
            self::Invoices => 'Invoices',
            self::Studio => 'Studio notes',
        };
    }

    /**
     * Get the line under the label saying what the emails actually are.
     */
    public function note(): string
    {
        return match ($this) {
            self::Tickets => 'When we answer or quote a ticket you raised.',
            self::Projects => 'Phase changes and anything needing your sign-off.',
            self::Invoices => 'New invoices and payment reminders.',
            self::Studio => 'Occasional notes on maintenance and new work. Rare.',
        };
    }

    /**
     * Determine whether this topic is on for someone who has not chosen yet.
     *
     * Everything that is a reply to something the client started is on;
     * the one we send unprompted is off until they ask for it.
     */
    public function onByDefault(): bool
    {
        return $this !== self::Studio;
    }

    /**
     * Get the default set, keyed by topic, for a brand new account.
     *
     * @return array<string, bool>
     */
    public static function defaults(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $topic) => [$topic->value => $topic->onByDefault()])
            ->all();
    }
}
