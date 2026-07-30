<?php

namespace App\Contexts\Commerce\Models;

use App\Contexts\Commerce\Enums\TaxType;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * A jurisdictional tax rate (VAT today) keyed by (type, country_code, currency). A null currency
 * row is the catch-all for any currency in that country. Rate is stored in basis points
 * (1500 = 15.00%); money is never derived here — the TaxService applies the rate via the tax
 * value objects using integer arithmetic only.
 *
 * @property string $public_id
 * @property int $id
 * @property TaxType $type
 * @property string $country_code
 * @property string|null $currency
 * @property int $rate_bps
 * @property bool $inclusive
 * @property string $name
 * @property bool $is_active
 */
class TaxRate extends Model
{
    use HasPublicId;

    protected $fillable = [
        'type',
        'country_code',
        'currency',
        'rate_bps',
        'inclusive',
        'name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => TaxType::class,
            'rate_bps' => 'integer',
            'inclusive' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** Typed rate accessor (basis points) for PHPStan-clean reads. */
    public function rateBps(): int
    {
        return (int) $this->getAttribute('rate_bps');
    }

    public function isInclusive(): bool
    {
        return (bool) $this->getAttribute('inclusive');
    }

    public function typeEnum(): TaxType
    {
        $type = $this->getAttribute('type');

        return $type instanceof TaxType ? $type : TaxType::from((string) $type);
    }
}
