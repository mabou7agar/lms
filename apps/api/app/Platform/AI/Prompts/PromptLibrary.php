<?php

declare(strict_types=1);

namespace App\Platform\AI\Prompts;

use App\Platform\AI\Data\RenderedPrompt;
use App\Platform\AI\Exceptions\PromptNotFoundException;
use App\Platform\AI\Models\AiPrompt;

/**
 * Resolves, renders, and versions library prompts. The active version for a (key, locale) is the
 * one a run uses; the resolved version number is returned so callers can stamp it immutably onto
 * the usage row. Duplicate mints the next version (inactive draft); rollback flips which version is
 * active — history is never mutated in place.
 */
final class PromptLibrary
{
    /** Resolve the ACTIVE prompt for a key, falling back to the default locale when needed. */
    public function resolve(string $key, string $locale = 'en'): AiPrompt
    {
        $prompt = AiPrompt::query()
            ->where('key', $key)
            ->where('locale', $locale)
            ->where('active', true)
            ->orderByDesc('version')
            ->first();

        if ($prompt === null) {
            $fallback = (string) config('app.fallback_locale', 'en');
            if ($fallback !== $locale) {
                $prompt = AiPrompt::query()
                    ->where('key', $key)
                    ->where('locale', $fallback)
                    ->where('active', true)
                    ->orderByDesc('version')
                    ->first();
            }
        }

        if ($prompt === null) {
            throw new PromptNotFoundException($key, $locale);
        }

        return $prompt;
    }

    /** Resolve the active version and interpolate its templates with the given variables. */
    public function render(string $key, array $variables = [], string $locale = 'en'): RenderedPrompt
    {
        $prompt = $this->resolve($key, $locale);

        return new RenderedPrompt(
            key: $prompt->key,
            version: $prompt->version,
            locale: $prompt->locale,
            systemPrompt: $this->interpolate((string) $prompt->system_prompt, $variables),
            userPrompt: $this->interpolate($prompt->user_template, $variables),
            modelPreference: $prompt->model_preference,
        );
    }

    /**
     * Create the first (or next) version of a prompt.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): AiPrompt
    {
        $key = (string) ($attributes['key'] ?? '');
        $attributes['version'] = $attributes['version'] ?? ($this->maxVersion($key) + 1);

        /** @var AiPrompt $prompt */
        $prompt = AiPrompt::create($attributes);

        return $prompt;
    }

    /** Duplicate a source version into a new INACTIVE draft version (next version number). */
    public function duplicate(string $key, int $fromVersion, ?string $locale = null): AiPrompt
    {
        $source = AiPrompt::query()
            ->where('key', $key)
            ->where('version', $fromVersion)
            ->firstOrFail();

        /** @var AiPrompt $copy */
        $copy = AiPrompt::create([
            'key' => $source->key,
            'purpose' => $source->purpose,
            'version' => $this->maxVersion($key) + 1,
            'system_prompt' => $source->system_prompt,
            'user_template' => $source->user_template,
            'variables' => $source->variables,
            'model_preference' => $source->model_preference,
            'locale' => $locale ?? $source->locale,
            'active' => false,
            'created_by' => $source->created_by,
        ]);

        return $copy;
    }

    /**
     * Make a specific version the ACTIVE one for its (key, locale), deactivating the others. This is
     * both "publish" and "rollback" — rollback is just activating an older version again.
     */
    public function activate(string $key, int $version): AiPrompt
    {
        $target = AiPrompt::query()
            ->where('key', $key)
            ->where('version', $version)
            ->firstOrFail();

        AiPrompt::query()
            ->where('key', $key)
            ->where('locale', $target->locale)
            ->where('id', '!=', $target->id)
            ->update(['active' => false]);

        $target->active = true;
        $target->save();

        return $target;
    }

    /** Alias for activate() — restore an earlier version as the active one. */
    public function rollbackTo(string $key, int $version): AiPrompt
    {
        return $this->activate($key, $version);
    }

    public function maxVersion(string $key): int
    {
        return (int) AiPrompt::query()->where('key', $key)->max('version');
    }

    /**
     * Replace {{ var }} / {{var}} placeholders with provided values; unknown placeholders collapse
     * to empty so a stray token never leaks into a prompt.
     *
     * @param  array<string, mixed>  $variables
     */
    private function interpolate(string $template, array $variables): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*(\w+)\s*\}\}/',
            static function (array $match) use ($variables): string {
                $value = $variables[$match[1]] ?? '';

                return is_scalar($value) ? (string) $value : '';
            },
            $template,
        );
    }
}
