<?php

namespace App\Domains\Assessment\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * JSON array cast that PRESERVES whole-number float fractions across the JSON round-trip
 * (7.0 stays 7.0, never collapses to the integer 7). Rubric snapshots store point values that
 * must keep their float type so grading maths and historical comparisons stay numerically
 * consistent — the default `array` cast uses json_encode() without JSON_PRESERVE_ZERO_FRACTION
 * and silently rewrites 7.0 as 7.
 *
 * @implements CastsAttributes<array<string, mixed>, array<string, mixed>>
 */
final class PreservesFloatArray implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        $decoded = json_decode(is_string($value) ? $value : (string) $value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $encoded = json_encode(
            $value,
            JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return $encoded === false ? null : $encoded;
    }
}
