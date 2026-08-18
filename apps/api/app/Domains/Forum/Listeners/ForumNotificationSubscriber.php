<?php

declare(strict_types=1);

namespace App\Domains\Forum\Listeners;

use App\Domains\Forum\Events\ForumPostCreated;
use App\Domains\Forum\Models\ForumThread;
use App\Platform\Identity\Contracts\UserLookupPort;
use App\Platform\Shared\Notifications\Contracts\LearningNotificationPort;
use Illuminate\Events\Dispatcher;

/**
 * Fans a new forum reply out to notifications: the thread author receives `forum_reply`, and every
 *
 * @mentioned user receives `forum_mention`. Both go through the Shared {@see LearningNotificationPort},
 * so this wiring depends only on Shared + Identity contracts — no Notifications<->Forum Deptrac edge.
 *
 * Self-notification is skipped (you are never notified for replying to your own thread, nor for
 *
 * @mentioning yourself). @mention handles are resolved to users by their external public_id — the
 * only resolvable handle, since there is no username column (see UserLookupPort / MentionParser);
 * handles that resolve to no user are silently dropped. Dedup is per (post, recipient), so a redeliver
 * of the same ForumPostCreated cannot double-notify the same recipient.
 */
final class ForumNotificationSubscriber
{
    public function __construct(
        private readonly LearningNotificationPort $notifications,
        private readonly UserLookupPort $users,
    ) {}

    public function onForumPostCreated(ForumPostCreated $event): void
    {
        $thread = ForumThread::query()->find($event->threadId);

        if ($thread !== null) {
            $threadAuthorId = (int) $thread->user_id;

            // Don't notify yourself for replying to your own thread.
            if ($threadAuthorId !== $event->authorUserId) {
                $this->notifications->forumReply($threadAuthorId, $event->postId);
            }
        }

        foreach ($this->resolveMentionedUserIds($event->mentions) as $userId) {
            // Never @mention-notify yourself.
            if ($userId === $event->authorUserId) {
                continue;
            }

            $this->notifications->forumMention($userId, $event->postId);
        }
    }

    /**
     * Resolve raw @handles to internal user ids via their external public_id, de-duplicated and
     * order-preserved. A handle with no matching user is dropped.
     *
     * @param  list<string>  $handles
     * @return list<int>
     */
    private function resolveMentionedUserIds(array $handles): array
    {
        $ids = [];

        foreach ($handles as $handle) {
            $ref = $this->users->refByPublicId($handle);

            if ($ref !== null && ! in_array($ref->id, $ids, true)) {
                $ids[] = $ref->id;
            }
        }

        return $ids;
    }

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            ForumPostCreated::class => 'onForumPostCreated',
        ];
    }
}
