<?php

namespace App\Models;

use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $blurb
 * @property int $price
 * @property float $hours_per_month
 * @property string $response_time
 * @property array<int, string> $features
 * @property bool $is_live
 * @property bool $is_featured
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Team> $teams
 */
#[Fillable([
    'slug', 'name', 'blurb', 'price', 'hours_per_month',
    'response_time', 'features', 'is_live', 'is_featured', 'sort_order',
])]
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    /**
     * Get the client teams on this plan.
     *
     * @return HasMany<Team, $this>
     */
    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    /**
     * Scope to the plans offered to clients, in display order.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function offered(Builder $query): void
    {
        $query->where('is_live', true)->orderBy('sort_order');
    }

    /**
     * Get the monthly hours as the label the design shows under the price.
     */
    public function hoursLabel(): string
    {
        return $this->hours_per_month > 0
            ? rtrim(rtrim(number_format($this->hours_per_month, 1), '0'), '.').' hours a month'
            : 'no included hours';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'features' => 'array',
            'hours_per_month' => 'float',
            'is_live' => 'boolean',
            'is_featured' => 'boolean',
        ];
    }
}
