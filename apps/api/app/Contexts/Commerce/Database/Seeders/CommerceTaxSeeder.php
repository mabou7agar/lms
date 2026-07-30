<?php

namespace App\Contexts\Commerce\Database\Seeders;

use App\Contexts\Commerce\Enums\TaxType;
use App\Contexts\Commerce\Models\TaxRate;
use Illuminate\Database\Seeder;

/**
 * Seeds the Saudi VAT rate (SA/SAR, 15% = 1500 bps) idempotently. Keyed on the
 * (type, country_code, currency) unique tuple so repeated runs never duplicate. VAT-ready only —
 * no ZATCA e-invoicing compliance is implied.
 */
class CommerceTaxSeeder extends Seeder
{
    public function run(): void
    {
        TaxRate::firstOrCreate(
            ['type' => TaxType::Vat->value, 'country_code' => 'SA', 'currency' => 'SAR'],
            ['rate_bps' => 1500, 'inclusive' => false, 'name' => 'VAT 15%', 'is_active' => true],
        );
    }
}
