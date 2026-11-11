<?php

namespace ErnestDefoe\SpoilerShield\Notification;

use ErnestDefoe\SpoilerShield\Gate;
use ErnestDefoe\SpoilerShield\MarkStore;
use Flarum\Discussion\Discussion;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\Driver\NotificationDriverInterface;
use Flarum\Post\Post;

/**
 * Whatever email driver the forum runs (core's, or one another extension put
 * in its place, like Courier's), minus the people the email would spoil. An email
 * prints the discussion's title and the post's words, and can't be blurred,
 * so a member who isn't that far along doesn't get it (their alert in the
 * forum still arrives, gated like everything else there).
 *
 * Every discussion and post the email could print is checked: the subject,
 * and any the blueprint carries (a mention's reply, a new post).
 */
class GatedEmailDriver implements NotificationDriverInterface
{
    public function __construct(
        private readonly NotificationDriverInterface $email,
        private readonly Gate $gate,
        private readonly MarkStore $store,
    ) {
    }

    public function send(BlueprintInterface $blueprint, array $users): void
    {
        [$discussionIds, $postIds] = $this->items($blueprint);

        if ($users && ($discussionIds || $postIds)) {
            $posts = $this->store->posts($postIds);
            $discussions = $this->store->discussions(array_merge($discussionIds, array_column($posts, 'discussion')));
            $verdicts = $this->gate->checkMany($users, $discussions, $posts);

            $users = array_values(array_filter($users, fn ($user) => ! $verdicts[(int) $user->id]->anythingHidden()));
        }

        if ($users) {
            $this->email->send($blueprint, $users);
        }
    }

    /**
     * Every discussion and post the email may print: the subject, and any the
     * blueprint carries in public properties (a mention's reply, a new post).
     *
     * @return array{0: int[], 1: int[]}
     */
    private function items(BlueprintInterface $blueprint): array
    {
        $discussions = $posts = [];

        foreach (array_merge([$blueprint->getSubject()], array_values(get_object_vars($blueprint))) as $model) {
            if ($model instanceof Post && $model->id) {
                $posts[] = (int) $model->id;
            } elseif ($model instanceof Discussion && $model->id) {
                $discussions[] = (int) $model->id;
            }
        }

        return [$discussions, $posts];
    }

    public function registerType(string $blueprintClass, array $driversEnabledByDefault): void
    {
        $this->email->registerType($blueprintClass, $driversEnabledByDefault);
    }
}
