<?php

namespace App\Domains\Authoring\Enums;

/**
 * P2/W02 - First-class content block types. Mirrors the frontend authoring block-registry
 * (src/lib/authoring/block-registry.ts) one-for-one. Backward-compatible superset of LessonType
 * (see BlockTypeMap). Parity with the frontend registry is pinned by BlockTypeMapTest.
 */
enum BlockType: string
{
    // content
    case Article = 'article';
    case Pdf = 'pdf';
    case Download = 'download';
    case ExternalLink = 'external_link';
    // media
    case Video = 'video';
    case Audio = 'audio';
    case LiveSession = 'live_session';
    // interactive
    case QuizPlaceholder = 'quiz_placeholder';
    case Quiz = 'quiz';
    case Assignment = 'assignment';
    case Survey = 'survey';
    // package
    case Scorm = 'scorm';
    case Xapi = 'xapi';
    case Cmi5 = 'cmi5';
    // engagement
    case Discussion = 'discussion';
    case Certificate = 'certificate';

    public function family(): BlockFamily
    {
        return match ($this) {
            self::Article, self::Pdf, self::Download, self::ExternalLink => BlockFamily::Content,
            self::Video, self::Audio, self::LiveSession => BlockFamily::Media,
            self::QuizPlaceholder, self::Quiz, self::Assignment, self::Survey => BlockFamily::Interactive,
            self::Scorm, self::Xapi, self::Cmi5 => BlockFamily::Package,
            self::Discussion, self::Certificate => BlockFamily::Engagement,
        };
    }
}
