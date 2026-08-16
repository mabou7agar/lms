<?php

namespace App\Contexts\Commerce\Enums;

/**
 * Who may buy a product. Individuals buy for themselves and are enrolled directly; companies buy
 * seats their org manager distributes to employees. `Both` exposes the product to either buyer.
 */
enum ProductAudience: string
{
    case Individual = 'individual';
    case Company = 'company';
    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::Individual => 'Individuals only',
            self::Company => 'Companies only',
            self::Both => 'Individuals and companies',
        };
    }

    public function allowsIndividual(): bool
    {
        return $this === self::Individual || $this === self::Both;
    }

    public function allowsCompany(): bool
    {
        return $this === self::Company || $this === self::Both;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
