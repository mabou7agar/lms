<?php

namespace App\Platform\Shared\Media\Enums;

/**
 * P2/W04 - Why an asset is being uploaded. Bounds the accepted media types and the maximum size at
 * direct-upload creation time, so a caller cannot request an upload slot for an unexpected kind or
 * an unbounded file.
 */
enum MediaPurpose: string
{
    case LessonVideo = 'lesson_video';
    case LessonAudio = 'lesson_audio';
    case LessonDocument = 'lesson_document';
    case LessonImage = 'lesson_image';
    case LessonAttachment = 'lesson_attachment';
    case AssignmentSubmission = 'assignment_submission';
    case Caption = 'caption';

    /**
     * Media types this purpose accepts.
     *
     * @return list<MediaType>
     */
    public function allowedTypes(): array
    {
        return match ($this) {
            self::LessonVideo => [MediaType::Video],
            self::LessonAudio => [MediaType::Audio],
            self::LessonDocument, self::AssignmentSubmission, self::LessonAttachment => [MediaType::Document, MediaType::File, MediaType::Image],
            self::LessonImage => [MediaType::Image],
            self::Caption => [MediaType::File],
        };
    }

    /** Maximum accepted size in bytes for this purpose (0 = use the global default). */
    public function maxBytes(): int
    {
        return match ($this) {
            self::LessonVideo => 5 * 1024 * 1024 * 1024,        // 5 GB
            self::LessonAudio => 1 * 1024 * 1024 * 1024,        // 1 GB
            self::LessonDocument, self::AssignmentSubmission, self::LessonAttachment => 200 * 1024 * 1024, // 200 MB
            self::LessonImage => 25 * 1024 * 1024,              // 25 MB
            self::Caption => 2 * 1024 * 1024,                   // 2 MB
        };
    }

    public function accepts(MediaType $type): bool
    {
        return in_array($type, $this->allowedTypes(), true);
    }
}
