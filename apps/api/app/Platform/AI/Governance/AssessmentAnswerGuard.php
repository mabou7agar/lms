<?php

declare(strict_types=1);

namespace App\Platform\AI\Governance;

/**
 * Deterministic guardrail that keeps the AI tutor from becoming an assessment-cheating oracle.
 *
 * It detects when a learner's message is soliciting the answers to a live quiz/exam/assignment or an
 * answer key, so the tutor can REFUSE before any retrieval or provider call happens. It is the
 * companion to {@see GradingPolicy} (which structurally forbids AI from setting a grade of record):
 * GradingPolicy stops AI grading the assessment, this stops AI handing out the answers.
 *
 * Like {@see PromptInjectionGuard} this is a best-effort pattern filter, not a complete defence — the
 * tutor's system prompt also instructs refusal — but it makes the refusal deterministic and testable,
 * and it fails closed (a match always refuses).
 */
final class AssessmentAnswerGuard
{
    /** @var list<string> */
    private const SOLICITATION_PATTERNS = [
        '/\banswer\s*keys?\b/i',
        '/\b(quiz|exam|test|assessment|assignment)\s+answers?\b/i',
        '/\banswers?\s+(to|for)\s+(the\s+)?(quiz|exam|test|assessment|assignment|final|midterm)\b/i',
        '/\b(give|tell|show|send)\s+me\s+(the\s+)?(correct\s+)?answers?\b/i',
        '/\bwhat\s+(is|are|s)\s+the\s+(correct\s+)?answers?\s+(to|for)\b/i',
        '/\bcorrect\s+answers?\s+(to|for)\b/i',
        '/\bsolutions?\s+(to|for)\s+(the\s+)?(quiz|exam|test|assessment|assignment)\b/i',
        '/\bhelp\s+me\s+(cheat|pass\s+the\s+(quiz|exam|test))\b/i',
    ];

    /** True when the message is asking the tutor to reveal assessment answers / an answer key. */
    public function solicitsAnswers(string $message): bool
    {
        foreach (self::SOLICITATION_PATTERNS as $pattern) {
            if (preg_match($pattern, $message) === 1) {
                return true;
            }
        }

        return false;
    }
}
