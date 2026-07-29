<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P2/W03 - Content versioning. An append-only, immutable history of authoring snapshots per course.
 *
 * Constraints (PostgreSQL, the app/test driver):
 *  - unique (course_id, version_number)                  -> safe concurrent numbering backstop
 *  - CHECK reason IN (manual|safety|rollback|clone|fork) -> only valid operation values
 *  - CHECK source rule                                   -> rollback/clone/fork require a source,
 *                                                            manual/safety forbid one
 *  - BEFORE UPDATE/DELETE trigger                        -> rows are immutable once written
 *
 * The snapshot is opaque JSON (jsonb) captured by SnapshotSerializer; no live Eloquent reference is
 * stored. `course_id` / `source_course_id` reference courses by string table name (no model import).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_versions', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('label')->nullable();
            $table->string('reason');
            $table->foreignId('source_version_id')->nullable()->constrained('content_versions')->nullOnDelete();
            // Fork attribution only: the course this snapshot originally came from. Nullable, no
            // cascade obligation on the destination draft.
            $table->foreignId('source_course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->jsonb('snapshot');
            $table->unsignedSmallInteger('snapshot_schema_version');
            $table->char('checksum', 64);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['course_id', 'version_number']);
            $table->index(['course_id', 'id']);
        });

        DB::statement(
            'ALTER TABLE content_versions ADD CONSTRAINT content_versions_reason_check '
            ."CHECK (reason IN ('manual','safety','rollback','clone','fork'))"
        );

        DB::statement(
            'ALTER TABLE content_versions ADD CONSTRAINT content_versions_source_rule_check CHECK ('
            ."(reason IN ('rollback','clone','fork') AND source_version_id IS NOT NULL) OR "
            ."(reason IN ('manual','safety') AND source_version_id IS NULL))"
        );

        // Immutability: block every UPDATE at the database layer (an UPDATE is the only way to
        // mutate a stored snapshot). DELETE is intentionally allowed so a course's ON DELETE CASCADE
        // can still remove its history; app code is additionally blocked from deleting by the model.
        DB::unprepared(
            'CREATE OR REPLACE FUNCTION content_versions_immutable() RETURNS trigger AS $$ '
            ."BEGIN RAISE EXCEPTION 'content_versions rows are immutable'; END; \$\$ LANGUAGE plpgsql;"
        );
        DB::unprepared(
            'CREATE TRIGGER content_versions_no_mutation BEFORE UPDATE ON content_versions '
            .'FOR EACH ROW EXECUTE FUNCTION content_versions_immutable();'
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS content_versions_no_mutation ON content_versions;');
        DB::unprepared('DROP FUNCTION IF EXISTS content_versions_immutable();');
        Schema::dropIfExists('content_versions');
    }
};
