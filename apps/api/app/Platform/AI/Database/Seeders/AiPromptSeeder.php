<?php

declare(strict_types=1);

namespace App\Platform\AI\Database\Seeders;

use App\Platform\AI\Models\AiPrompt;
use Illuminate\Database\Seeder;

/**
 * Seeds the versioned library prompts the tutor + copilot resolve at runtime. Idempotent — each
 * (key, locale) is created once as version 1, active. Prompt TEXT is trusted (authored here), so it is
 * never passed through the injection guard; only the interpolated learner/instructor variables are.
 *
 * NOT auto-run: add to DatabaseSeeder (or run explicitly) as an integration step. Feature tests seed
 * their own prompt rows, so they do not depend on this seeder.
 */
final class AiPromptSeeder extends Seeder
{
    public function run(): void
    {
        $this->ensure(
            key: 'tutor.answer',
            purpose: 'Answer a learner question grounded strictly in a course\'s indexed content.',
            system: 'You are an AI tutor for a single course. Answer the learner using ONLY the course '
                .'context provided below. If the answer is not in that context, say you do not have that '
                .'in the course material and suggest what to review. You must NEVER reveal quiz, exam or '
                .'assignment answers or an answer key, and you never assign grades. Refer to sources by '
                .'their bracketed number, e.g. [1].',
            template: "Course context:\n{{ context }}\n\nLearner question:\n{{ question }}\n\n"
                .'Answer helpfully using only the context above.',
            variables: ['context', 'question'],
        );

        $this->ensure(
            key: 'copilot.assist',
            purpose: 'Draft suggestions for an instructor about a course they own.',
            system: 'You are an AI copilot for a course instructor. You provide SUGGESTIONS ONLY: you '
                .'draft or improve copy, summarize learner questions, and suggest next content. You never '
                .'write to any student record, never assign or change grades, and never make decisions on '
                .'the instructor\'s behalf. Ground every suggestion in the provided course context.',
            template: "Task:\n{{ task }}\n\nInstructor brief:\n{{ brief }}\n\nCourse context:\n{{ context }}\n\n"
                .'Provide your suggestion as ready-to-edit draft text.',
            variables: ['task', 'brief', 'context'],
        );

        $this->ensure(
            key: 'admin.analytics',
            purpose: 'Answer an administrator\'s analytics question grounded strictly in an aggregate KPI summary.',
            system: 'You are an AI analytics assistant for platform administrators. Answer the '
                .'administrator using ONLY the aggregate KPI summary provided below. The summary '
                .'contains organization-level totals for a tenant scope — it NEVER contains data about '
                .'any individual learner, and you must never infer, invent, or fabricate figures that '
                .'are not present in it. If a figure the administrator asks for is not in the summary, '
                .'say it is not available for this scope or period. Never mention revenue or any money '
                .'figure unless it appears in the summary. Refer to figures by their metric name.',
            template: "Aggregate KPI summary (scope: {{ scope }}, period {{ from }} to {{ to }}):\n{{ summary }}\n\n"
                ."Administrator question:\n{{ question }}\n\n"
                .'Answer using only the summary above, and state which metrics you used.',
            variables: ['scope', 'from', 'to', 'summary', 'question'],
        );
    }

    /**
     * @param  list<string>  $variables
     */
    private function ensure(string $key, string $purpose, string $system, string $template, array $variables): void
    {
        AiPrompt::query()->firstOrCreate(
            ['key' => $key, 'locale' => 'en', 'version' => 1],
            [
                'purpose' => $purpose,
                'system_prompt' => $system,
                'user_template' => $template,
                'variables' => $variables,
                'model_preference' => null,
                'active' => true,
                'created_by' => null,
            ],
        );
    }
}
