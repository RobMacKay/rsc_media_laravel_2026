<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The studio's own defaults — rates, VAT and bank details. There is exactly one row.
 *
 * @property int $id
 * @property string|null $company_name
 * @property string|null $company_number
 * @property string|null $address
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $website
 * @property string|null $welcome_video_url
 * @property int $hour_rate
 * @property int $day_rate
 * @property float $day_length
 * @property float $minimum_charge
 * @property int $out_of_hours_uplift
 * @property int $payment_terms_days
 * @property int $site_limit
 * @property float $late_fee_percent
 * @property bool $invoice_reminders
 * @property bool $vat_registered
 * @property string|null $vat_number
 * @property float $vat_rate
 * @property string|null $account_name
 * @property string|null $bank_name
 * @property string|null $sort_code
 * @property string|null $account_number
 * @property string $reference_format
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'company_name', 'company_number', 'address', 'email', 'phone', 'website', 'welcome_video_url',
    'hour_rate', 'day_rate', 'day_length', 'minimum_charge', 'out_of_hours_uplift',
    'payment_terms_days', 'site_limit', 'late_fee_percent', 'invoice_reminders', 'vat_registered', 'vat_number', 'vat_rate',
    'account_name', 'bank_name', 'sort_code', 'account_number', 'reference_format',
])]
class StudioSetting extends Model
{
    /**
     * The model's default values, mirroring the column defaults so a freshly
     * created row is usable without a round trip back to the database.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'company_name' => 'RSC Media Ltd',
        'hour_rate' => 65,
        'day_rate' => 460,
        'day_length' => 7.5,
        'minimum_charge' => 0.5,
        'out_of_hours_uplift' => 50,
        'payment_terms_days' => 21,
        'site_limit' => 5,
        'late_fee_percent' => 2,
        'invoice_reminders' => true,
        'vat_registered' => true,
        'vat_rate' => 20,
        'reference_format' => 'RSC-{invoice}',
    ];

    /**
     * Get the studio's settings row, creating it from the defaults if it is missing.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }

    /**
     * Get the studio's address as one line per entry, for the invoice header.
     *
     * @return array<int, string>
     */
    public function addressLines(): array
    {
        return collect(preg_split('/\R/', (string) $this->address) ?: [])
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Get the effective VAT rate, which is zero until the studio is both
     * registered and has a VAT number to put on the invoice.
     *
     * The number is part of the switch, not decoration: a VAT invoice has to
     * show it, so charging VAT without one would produce an invoice that is
     * not valid. It also means clearing the number is enough to stop VAT
     * appearing anywhere, which is how the studio expects it to behave.
     */
    public function effectiveVatRate(): float
    {
        return $this->vat_registered && filled($this->vat_number) ? $this->vat_rate : 0.0;
    }

    /**
     * Determine whether VAT is actually charged on anything the studio sends.
     *
     * Nothing user-facing should mention VAT when it is not, so this is the
     * one switch every "ex VAT" and "+ VAT" line is gated on. It follows
     * effectiveVatRate(), so a figure and its label cannot disagree.
     */
    public function chargesVat(): bool
    {
        return $this->effectiveVatRate() > 0;
    }

    /**
     * Get the day rate implied by the hourly rate and the length of a working day.
     */
    public function impliedDayRate(): float
    {
        return $this->hour_rate * $this->day_length;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day_length' => 'float',
            'minimum_charge' => 'float',
            'late_fee_percent' => 'float',
            'invoice_reminders' => 'boolean',
            'vat_registered' => 'boolean',
            'vat_rate' => 'float',
        ];
    }
}
