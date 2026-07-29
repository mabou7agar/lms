<?php

use App\Domains\Authoring\Exceptions\UnsupportedSnapshotSchemaException;
use App\Domains\Authoring\Snapshots\SnapshotSerializer;

/**
 * P2/W03 - Pure (no DB) proofs for the snapshot checksum + schema validation.
 */
function sampleSnapshot(): array
{
    return [
        'schema_version' => SnapshotSerializer::SCHEMA_VERSION,
        'course_id' => 7,
        'modules' => [],
        'sections' => [
            [
                'public_id' => 'sec-1', 'title' => 'A', 'summary' => null, 'position' => 0,
                'publish_state' => 'draft',
                'lessons' => [
                    [
                        'public_id' => 'les-1', 'title' => 'L1', 'type' => 'article', 'content' => ['html' => 'x'],
                        'position' => 0, 'publish_state' => 'draft', 'is_preview' => false,
                        'assessment_id' => null, 'media' => null, 'prerequisite_public_ids' => [], 'blocks' => [],
                    ],
                ],
            ],
        ],
    ];
}

it('produces a stable checksum for identical snapshots', function () {
    expect(SnapshotSerializer::checksum(sampleSnapshot()))
        ->toBe(SnapshotSerializer::checksum(sampleSnapshot()))
        ->toHaveLength(64);
});

it('is independent of associative key ordering (canonicalised)', function () {
    $a = ['schema_version' => 1, 'course_id' => 7, 'modules' => [], 'sections' => []];
    $b = ['sections' => [], 'course_id' => 7, 'modules' => [], 'schema_version' => 1];

    expect(SnapshotSerializer::checksum($a))->toBe(SnapshotSerializer::checksum($b));
});

it('changes when a value changes', function () {
    $modified = sampleSnapshot();
    $modified['sections'][0]['title'] = 'B';

    expect(SnapshotSerializer::checksum($modified))
        ->not->toBe(SnapshotSerializer::checksum(sampleSnapshot()));
});

it('is sensitive to list ordering', function () {
    $one = ['schema_version' => 1, 'course_id' => 1, 'modules' => [], 'sections' => [['public_id' => 'a'], ['public_id' => 'b']]];
    $two = ['schema_version' => 1, 'course_id' => 1, 'modules' => [], 'sections' => [['public_id' => 'b'], ['public_id' => 'a']]];

    expect(SnapshotSerializer::checksum($one))->not->toBe(SnapshotSerializer::checksum($two));
});

it('validates a well-formed current-schema snapshot', function () {
    SnapshotSerializer::validate(sampleSnapshot());
})->throwsNoExceptions();

it('rejects an unsupported schema version', function () {
    $bad = sampleSnapshot();
    $bad['schema_version'] = 999;

    expect(fn () => SnapshotSerializer::validate($bad))
        ->toThrow(UnsupportedSnapshotSchemaException::class);
});

it('rejects a malformed snapshot missing required keys', function () {
    expect(fn () => SnapshotSerializer::validate(['schema_version' => SnapshotSerializer::SCHEMA_VERSION]))
        ->toThrow(UnsupportedSnapshotSchemaException::class);
});

it('counts modules, sections, lessons and blocks', function () {
    $snap = sampleSnapshot();
    $snap['sections'][0]['lessons'][0]['blocks'] = [['type' => 'article'], ['type' => 'pdf']];

    expect(SnapshotSerializer::counts($snap))
        ->toBe(['modules' => 0, 'sections' => 1, 'lessons' => 1, 'blocks' => 2]);
});
