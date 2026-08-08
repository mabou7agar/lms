<?php

namespace App\Domains\Authoring\Http\Controllers\Api\V1\Admin;

use App\Domains\Authoring\Actions\Block\CreateBlockAction;
use App\Domains\Authoring\Actions\Block\DeleteBlockAction;
use App\Domains\Authoring\Actions\Block\DuplicateBlockAction;
use App\Domains\Authoring\Actions\Block\ReorderBlocksAction;
use App\Domains\Authoring\Actions\Block\SetBlockPublishStateAction;
use App\Domains\Authoring\Actions\Block\UpdateBlockAction;
use App\Domains\Authoring\Enums\PublishState;
use App\Domains\Authoring\Http\Requests\CreateBlockRequest;
use App\Domains\Authoring\Http\Requests\ReorderRequest;
use App\Domains\Authoring\Http\Requests\SetPublishStateRequest;
use App\Domains\Authoring\Http\Requests\UpdateBlockRequest;
use App\Domains\Authoring\Http\Resources\BlockResource;
use App\Domains\Authoring\Models\Block;
use App\Domains\Authoring\Models\Lesson;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

/**
 * C5 - The ordered block layer INSIDE a lesson. Follows the Authoring admin controller conventions
 * (Gate::authorize, FormRequests, Resources, action delegation) and the C3 optimistic-lock contract.
 *
 * Every endpoint is gated behind `authoring.blocks_enabled`: when the flag is off the block layer is
 * dormant (404) and the learner runtime is unchanged — exactly as the current block design requires.
 *
 * Authorization mirrors the rest of Authoring: writes under a {lesson} authorize `update` on the
 * lesson; block-bound writes authorize on the {block} (whose policy resolves the block -> lesson ->
 * section -> course ancestry, so a foreign-course block id is denied). Nested {lesson}/{block}
 * routes additionally assert the block belongs to the lesson, so cross-lesson forgery 404s.
 *
 * TENANCY NOTE (later phase): once tenant scoping lands, these handlers must also assert the
 * lesson/block belong to the caller's active tenant (organization) — the policy ancestry check will
 * need a tenant dimension. Deferred by instruction; no tenant scoping added here.
 */
class BlockAdminController extends Controller
{
    public function index(Lesson $lesson): JsonResponse
    {
        $this->assertEnabled();
        Gate::authorize('update', $lesson);

        $blocks = Block::query()
            ->where('lesson_id', $lesson->id)
            ->orderBy('position')
            ->orderBy('public_id')
            ->get();

        return ApiResponse::success(BlockResource::collection($blocks));
    }

    public function store(CreateBlockRequest $request, Lesson $lesson, CreateBlockAction $action): JsonResponse
    {
        $this->assertEnabled();
        Gate::authorize('update', $lesson);

        $block = $action->execute($lesson, $request->validated(), $this->actorId($request));

        return ApiResponse::created(new BlockResource($block));
    }

    public function update(UpdateBlockRequest $request, Block $block, UpdateBlockAction $action): JsonResponse
    {
        $this->assertEnabled();
        Gate::authorize('update', $block);

        return ApiResponse::updated(new BlockResource(
            $action->execute($block, $request->validated(), $request->expectedVersion()),
        ));
    }

    public function destroy(Block $block, DeleteBlockAction $action): JsonResponse
    {
        $this->assertEnabled();
        Gate::authorize('delete', $block);

        $action->execute($block);

        return ApiResponse::deleted('Block deleted.');
    }

    /**
     * Deep-copy a block within its lesson. The block is bound independently of {lesson}, so ownership
     * is verified two ways: the caller must manage the lesson (Gate), and the block must actually
     * belong to that lesson — a foreign block id resolves to a mismatch and 404s rather than being
     * copied into a lesson the caller does not own.
     */
    public function duplicate(Request $request, Lesson $lesson, Block $block, DuplicateBlockAction $action): JsonResponse
    {
        $this->assertEnabled();
        Gate::authorize('update', $lesson);

        abort_unless((int) $block->lesson_id === (int) $lesson->id, 404);

        return ApiResponse::created(new BlockResource($action->execute($block, $this->actorId($request))));
    }

    public function reorder(ReorderRequest $request, Lesson $lesson, ReorderBlocksAction $action): JsonResponse
    {
        $this->assertEnabled();
        Gate::authorize('update', $lesson);

        $lockVersion = $action->execute($lesson, $request->validated()['order'], $request->expectedVersion());

        return ApiResponse::success(['lock_version' => $lockVersion], 'Blocks reordered.');
    }

    public function publish(SetPublishStateRequest $request, Block $block, SetBlockPublishStateAction $action): JsonResponse
    {
        $this->assertEnabled();
        Gate::authorize('update', $block);

        $state = PublishState::from($request->validated()['state']);

        return ApiResponse::updated(new BlockResource($action->execute($block, $state)));
    }

    /** Read a single block as it would render (locale-resolved via BlockResource). */
    public function preview(Block $block): JsonResponse
    {
        $this->assertEnabled();
        Gate::authorize('view', $block);

        return ApiResponse::success(new BlockResource($block));
    }

    /**
     * The block layer is dormant until the feature flag is on. A 404 (rather than 403) keeps the
     * surface invisible while off and preserves learner-runtime backward compatibility.
     */
    private function assertEnabled(): void
    {
        abort_unless((bool) config('authoring.blocks_enabled', false), 404);
    }

    private function actorId(Request $request): ?int
    {
        $user = $request->user();

        return $user === null ? null : (int) $user->getAuthIdentifier();
    }
}
