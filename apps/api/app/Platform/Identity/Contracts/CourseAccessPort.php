<?php

namespace App\Platform\Identity\Contracts;

/**
 * Answers "may this actor manage the content of this course?" without exposing the Course model.
 *
 * Lives in IdentityContracts rather than Shared for a structural reason: this contract carries an
 * Actor, and the Deptrac ruleset gives Shared no internal dependencies at all (`Shared: ~`) — it
 * is the leaf of the graph. IdentityContracts is the layer designed to hold actor-bearing
 * contracts, and every bounded context is already allowed to depend on it. Compare
 * `Shared\Media\Contracts\MediaAssetPort`, which stays in Shared precisely because it passes only
 * scalars.
 *
 * Implemented by Catalog (which owns Course). The implementation delegates to the SINGLE existing
 * ownership rule rather than restating it — there must be exactly one definition of "this
 * instructor owns this course" in the codebase.
 */
interface CourseAccessPort
{
    /** False for an unknown course id — a missing course grants nobody anything. */
    public function canManageContent(Actor $actor, int $courseId): bool;

    /**
     * Resolve a course public_id to its internal id, but ONLY if the actor may manage its content.
     *
     * Returns null both for "no such course" and for "not yours" — deliberately indistinguishable,
     * so this cannot be used to enumerate which courses exist. Exists because a context that may
     * not import the Course model still has to turn a route parameter into a foreign key.
     */
    public function manageableCourseId(Actor $actor, string $coursePublicId): ?int;

    /**
     * Every course this actor may manage the content of.
     *
     * An instructor-facing queue — "the questions waiting on me" — has to start from a set of
     * courses, and asking canManageContent() once per course in the catalogue is not a way to build
     * one. The same single ownership rule decides membership; this only evaluates it in bulk.
     *
     * @return list<int>
     */
    public function manageableCourseIds(Actor $actor): array;
}
