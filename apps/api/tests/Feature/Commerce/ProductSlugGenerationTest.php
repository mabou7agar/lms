<?php

use App\Contexts\Commerce\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('generates a product slug from the translated title when the legacy title is not submitted', function () {
    $product = Product::factory()->make([
        'title' => null,
        'title_i18n' => ['en' => 'Enterprise Leadership', 'ar' => 'القيادة المؤسسية'],
        'slug' => null,
    ]);

    $product->save();

    expect($product->slug)->toBe('enterprise-leadership')
        ->and($product->title)->toBe('Enterprise Leadership');
});

it('preserves an operator supplied product slug', function () {
    $product = Product::factory()->create([
        'title_i18n' => ['en' => 'Enterprise Leadership'],
        'slug' => 'custom-product-url',
    ]);

    expect($product->slug)->toBe('custom-product-url');
});
