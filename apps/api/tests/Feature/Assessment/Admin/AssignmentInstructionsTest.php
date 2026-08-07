<?php

use App\Domains\Assessment\Models\Assignment;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('sanitizes and persists bilingual assignment instructions on save', function () {
    $assignment = Assignment::factory()->create();

    $assignment->instructions = [
        'en' => '<p>Upload your essay.</p><script>alert(1)</script>',
        'ar' => '<p>ارفع مقالك.</p>',
    ];
    $assignment->save();

    $fresh = $assignment->fresh();

    // The <script> is stripped by the shared HtmlSanitizer (translatableHtml), the safe markup kept.
    expect($fresh->instructions['en'])->toContain('Upload your essay.')
        ->and($fresh->instructions['en'])->not->toContain('<script')
        ->and($fresh->instructions['ar'])->toBe('<p>ارفع مقالك.</p>');
});

it('does not create a legacy scalar column for instructions', function () {
    // instructions is a lone JSON map (no `_i18n` suffix, no legacy scalar) — the sync path must be a
    // no-op for it, so the whole map survives rather than collapsing to a default-locale string.
    $assignment = Assignment::factory()->create([
        'instructions' => ['en' => '<p>Do the task.</p>', 'ar' => '<p>افعل المهمة.</p>'],
    ]);

    expect($assignment->fresh()->instructions)->toEqual([
        'en' => '<p>Do the task.</p>',
        'ar' => '<p>افعل المهمة.</p>',
    ]);
});
