<?php

namespace ErnestDefoe\SpoilerShield;

use Flarum\Discussion\Discussion;
use Flarum\Discussion\Event\Saving as DiscussionSaving;
use Flarum\Foundation\ValidationException;
use Flarum\Post\CommentPost;
use Flarum\Post\Post;
use Flarum\Post\Event\Saving as PostSaving;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Symfony\Contracts\Translation\TranslatorInterface;
use WeakMap;

/**
 * Puts the marks on discussions and posts, all on the row itself:
 *
 *  - a discussion marked by its author (or anyone who may rename it), else
 *    linked by Marquee to an episode or season, else in an Anthology series,
 *    else in a tag attached to a work (the work only, no position);
 *  - a reply inside a positioned discussion, stamped with its author's own
 *    position when that's further along, unless they chose otherwise.
 *
 * A positioned discussion's slug becomes its position ("s2e5"), so its title
 * never reaches a URL: links, sitemaps, emails.
 */
final class MarkWriter
{
    public const ATTRIBUTE = 'spoilerShield';

    /** @var WeakMap<Discussion, true> discussions marked by hand in this request (Marquee doesn't overrule them). */
    private WeakMap $explicit;

    public function __construct(
        private readonly Ladders $ladders,
        private readonly Positions $positions,
        private readonly Integrations $integrations,
        private readonly ConnectionInterface $db,
        private readonly SettingsRepositoryInterface $settings,
        private readonly TranslatorInterface $translator,
    ) {
        $this->explicit = new WeakMap();
    }

    public function discussionSaving(DiscussionSaving $event): void
    {
        $discussion = $event->discussion;
        $attributes = (array) ($event->data['attributes'] ?? []);

        if (array_key_exists(self::ATTRIBUTE, $attributes)) {
            if ($discussion->exists) {
                $event->actor->assertCan('rename', $discussion);
            }

            $value = $attributes[self::ATTRIBUTE];
            $this->explicit[$discussion] = true;

            if ($value === null) {
                $discussion->spoiler_work = $discussion->spoiler_position = $discussion->spoiler_label = null;

                return;
            }

            $work = is_array($value) ? ($value['work'] ?? null) : null;
            $position = is_array($value) && isset($value['position']) ? (int) $value['position'] : null;

            if (! Marks::parse($work) || str_starts_with((string) $work, 'an:')) {
                $this->invalid('work');
            }
            if ($position !== null) {
                $ladder = $this->ladders->get($work) ?? $this->invalid('work');
                Ladders::valid($ladder, $position, false) || $this->invalid('position');
            } elseif (! $this->ladders->get($work)) {
                $this->invalid('work');
            }

            $discussion->spoiler_work = $work;
            $discussion->spoiler_position = $position;
            $discussion->spoiler_label = $position === null ? null : $this->storedLabel($work, $position);

            return;
        }

        // A new discussion in a tag attached to a work belongs to it.
        if (! $discussion->exists && $discussion->spoiler_work === null && $this->integrations->tags()) {
            $tagIds = array_map(fn ($t) => (int) ($t['id'] ?? 0), (array) ($event->data['relationships']['tags']['data'] ?? []));
            if ($work = $this->workForTags($tagIds)) {
                $discussion->spoiler_work = $work;
            }
        }
    }

    /** A discussion whose tags changed later: it joins a tag's work if it has none. */
    public function retagged(Discussion $discussion): void
    {
        if ($discussion->spoiler_work !== null) {
            return;
        }

        if ($work = $this->workForTags($this->db->table('discussion_tag')->where('discussion_id', $discussion->id)->pluck('tag_id')->map(fn ($id) => (int) $id)->all())) {
            $discussion->spoiler_work = $work;
            $discussion->save();
        }
    }

    /**
     * Eloquent's `saving`, so it sees every save: Marquee's link (set by its
     * own API fields, before the save) and the slug.
     */
    public function discussionModelSaving(Discussion $discussion): void
    {
        if (! isset($this->explicit[$discussion])
            && $this->integrations->marquee()
            && $discussion->isDirty(['marquee_media_id', 'marquee_season', 'marquee_episode'])
            && ($discussion->spoiler_work === null || str_starts_with((string) $discussion->spoiler_work, 'mq:'))) {
            $media = $discussion->getAttribute('marquee_media_id');

            if ($media) {
                $discussion->spoiler_work = 'mq:'.(int) $media;
                $discussion->spoiler_position = Marks::marqueePosition($discussion->getAttribute('marquee_season'), $discussion->getAttribute('marquee_episode'));
            } else {
                $discussion->spoiler_work = $discussion->spoiler_position = null;
            }
            $discussion->spoiler_label = null;
        }

        $this->keepRepliesGated($discussion);
        $this->slug($discussion);
    }

    /**
     * A discussion's mark going down, away or to another work (unmarked, a Marquee relink, out
     * of an Anthology series, its work deleted) would open its replies that carry no stamp of
     * their own: first they take the old work and place, in one UPDATE. The opening post goes
     * with the discussion: whoever changed the mark decided about it.
     */
    private function keepRepliesGated(Discussion $discussion): void
    {
        $oldWork = $discussion->getOriginal('spoiler_work');
        $oldPosition = $discussion->getOriginal('spoiler_position');

        if (! $discussion->exists || $oldWork === null || $oldPosition === null) {
            return;
        }

        $newPosition = $discussion->spoiler_position;
        if ($discussion->spoiler_work === $oldWork && $newPosition !== null && (int) $newPosition >= (int) $oldPosition) {
            return;
        }

        $this->db->table('posts')
            ->where('discussion_id', $discussion->id)
            ->whereNull('spoiler_position')
            ->when($discussion->first_post_id, fn ($q, $first) => $q->where('id', '!=', $first))
            ->update(['spoiler_work' => $oldWork, 'spoiler_position' => (int) $oldPosition, 'spoiler_label' => $discussion->getOriginal('spoiler_label')]);
    }

    /**
     * A whole work's marks going away (its deletion, a deleted Anthology series), in constant
     * queries however many discussions: unstamped replies first take their discussion's place
     * (the same rule as keepRepliesGated), then the discussions are unmarked. Their slugs stay
     * the place ("s2e5"): harmless, and no title reaches a URL.
     */
    public function clearWork(string $work): void
    {
        $g = $this->db->getQueryGrammar();
        $discussions = $g->wrapTable('discussions');
        $from = fn (string $column) => $this->db->raw('(select '.$g->wrap('discussions.'.$column).' from '.$discussions
            .' where '.$g->wrap('discussions.id').' = '.$g->wrap('posts.discussion_id').')');

        $this->db->table('posts')
            ->whereNull('spoiler_position')
            ->whereIn('discussion_id', fn ($q) => $q->select('id')->from('discussions')->where('spoiler_work', $work)->whereNotNull('spoiler_position'))
            ->whereNotIn('id', fn ($q) => $q->select('first_post_id')->from('discussions')->where('spoiler_work', $work)->whereNotNull('first_post_id'))
            ->update(['spoiler_work' => $work, 'spoiler_position' => $from('spoiler_position'), 'spoiler_label' => $from('spoiler_label')]);

        $this->db->table('discussions')->where('spoiler_work', $work)
            ->update(['spoiler_work' => null, 'spoiler_position' => null, 'spoiler_label' => null]);
    }

    /**
     * fof/split moves posts with one bulk UPDATE (no model events): those without a stamp of
     * their own take the original discussion's work and place, in one UPDATE.
     *
     * @param \FoF\Split\Events\DiscussionWasSplit $event
     */
    public function split(object $event): void
    {
        $original = $event->originalDiscussion;
        if ($original->spoiler_work === null || $original->spoiler_position === null) {
            return;
        }

        $this->db->table('posts')
            ->whereIn('id', $event->posts->pluck('id')->all())
            ->whereNull('spoiler_position')
            ->update(['spoiler_work' => $original->spoiler_work, 'spoiler_position' => (int) $original->spoiler_position, 'spoiler_label' => $original->spoiler_label]);
    }

    /**
     * A post moved to another discussion (fof/merge-discussions, a split) carries its source
     * discussion's place with it, unless it has its own.
     */
    public function postMoving(Post $post): void
    {
        if (! $post->exists || ! $post->isDirty('discussion_id') || $post->spoiler_position !== null) {
            return;
        }

        $source = $this->db->table('discussions')->where('id', $post->getOriginal('discussion_id'))->first(['spoiler_work', 'spoiler_position', 'spoiler_label']);
        if ($source && $source->spoiler_work !== null && $source->spoiler_position !== null) {
            $post->spoiler_work = $source->spoiler_work;
            $post->spoiler_position = (int) $source->spoiler_position;
            $post->spoiler_label = $source->spoiler_label;
        }
    }

    public function slug(Discussion $discussion): void
    {
        if (! $discussion->isDirty(['spoiler_position', 'spoiler_work', 'spoiler_label', 'slug', 'title'])) {
            return;
        }

        if ($discussion->spoiler_position !== null && $discussion->spoiler_work !== null) {
            $discussion->slug = Marks::slugFor($discussion->spoiler_work, (int) $discussion->spoiler_position, $discussion->spoiler_label);
        } elseif ($discussion->getOriginal('spoiler_position') !== null && $discussion->title) {
            $discussion->slug = Str::slug($discussion->title);
        }
    }

    public function postSaving(PostSaving $event): void
    {
        $post = $event->post;
        $discussion = $post->discussion;

        if (! $post instanceof CommentPost || ! $discussion) {
            return;
        }

        $attributes = (array) ($event->data['attributes'] ?? []);
        $first = $discussion->first_post_id === null || (int) $discussion->first_post_id === (int) $post->id;
        $work = $discussion->spoiler_work;
        $floor = $discussion->spoiler_position === null ? -1 : (int) $discussion->spoiler_position;
        $position = $post->spoiler_position === null ? null : (int) $post->spoiler_position;
        $stampWork = $post->spoiler_work ?? ($position === null ? null : $work);

        // The first post is the discussion's own, and shares its mark (quotes aside, below).
        if (! $first && array_key_exists(self::ATTRIBUTE, $attributes)) {
            if ($post->exists) {
                $event->actor->assertCan('edit', $post);
            }
            if ($work !== null) {
                $value = $attributes[self::ATTRIBUTE];
                $position = is_array($value) && isset($value['position']) ? (int) $value['position'] : null;

                if ($position !== null) {
                    $ladder = $this->ladders->get($work) ?? $this->invalid('work');
                    Ladders::valid($ladder, $position, true) || $this->invalid('position');
                }
                $stampWork = $work;
            }
        } elseif (! $first && ! $post->exists && $work !== null && $discussion->spoiler_position !== null
            && $this->settings->get('ernestdefoe-spoiler-shield.stamp_replies')) {
            $position = $this->positions->read($event->actor, $work);
            $stampWork = $work;
        }

        // An edit can add what its author has seen since: the stamp rises to their place now (never lower).
        if (! $first && $post->exists && $post->isDirty('content') && $work !== null && $discussion->spoiler_position !== null
            && ($stampWork === null || $stampWork === $work) && $post->user && $this->settings->get('ernestdefoe-spoiler-shield.stamp_replies')) {
            $now = $this->positions->read($post->user, $work);
            if ($now !== null && ($position === null || $now > $position)) {
                $position = $now;
                $stampWork = $work;
            }
        } elseif (! $first && $post->exists && $post->isDirty('content') && $work !== null && $discussion->spoiler_position !== null
            && $stampWork !== null && $stampWork !== $work && $position !== null && $post->user) {
            // Its stamp is another work's (it quoted that one). If its author is now past this
            // discussion's place, an edit could add this work's spoilers with nothing to gate them:
            // refused when it adds anything (new quotes, more words). Fixing a typo still works.
            $now = $this->positions->read($post->user, $work);
            if ($now !== null && $now > $floor && $this->grows($post)) {
                $this->invalid('quote_other_work');
            }
        }

        // Nothing past the discussion's own place: no mark needed.
        if ($stampWork === $work && $position !== null && $position <= $floor) {
            $position = null;
        }

        // A quote or a mention republishes what it points at: never below the furthest of those,
        // wherever it came from. One post carries one work's place, so a reply that already goes
        // somewhere in one work can't also quote a spoiler from another: refused, never overwritten.
        if (! $post->exists || $post->isDirty('content')) {
            foreach ($this->quoted($post) as [$quotedWork, $quotedPosition]) {
                if ($position === null) {
                    if ($quotedWork !== $work || $quotedPosition > $floor) {
                        $position = $quotedPosition;
                        $stampWork = $quotedWork;
                    }
                } elseif ($quotedWork === $stampWork) {
                    $position = max($position, $quotedPosition);
                } else {
                    $this->invalid('quote_other_work');
                }
            }
        }

        $post->spoiler_position = $position;
        $post->spoiler_work = $position === null ? null : $stampWork;
        $post->spoiler_label = $position === null ? null : $this->storedLabel((string) $stampWork, $position);
    }

    /** Whether an edit adds anything: other quotes or mentions than before, or more words. */
    private function grows(CommentPost $post): bool
    {
        $before = (string) $post->getRawOriginal('content');
        $after = (string) ($post->getAttributes()['content'] ?? '');
        $quotes = function (string $xml): array {
            preg_match_all('/<POSTMENTION\b[^>]*\bid="(\d+)"/', $xml, $m);
            $ids = array_unique($m[1]);
            sort($ids);

            return $ids;
        };

        return $quotes($before) !== $quotes($after)
            || mb_strlen(trim(strip_tags($after))) > mb_strlen(trim(strip_tags($before)));
    }

    /**
     * The places of every post this one quotes or mentions (Flarum writes a
     * quote as a post mention): each post's own stamp, or its discussion's
     * place. One query each for the posts and their discussions.
     *
     * @return array<int, array{0: string, 1: int}>
     */
    private function quoted(CommentPost $post): array
    {
        $xml = (string) ($post->getAttributes()['content'] ?? '');
        if (! str_contains($xml, 'POSTMENTION')) {
            return [];
        }

        preg_match_all('/<POSTMENTION\b[^>]*\bid="(\d+)"/', $xml, $m);
        $ids = array_values(array_unique(array_map('intval', $m[1])));
        if (! $ids) {
            return [];
        }

        $posts = $this->db->table('posts')->whereIn('id', $ids)->get(['id', 'discussion_id', 'spoiler_position', 'spoiler_work']);
        $discussions = $this->db->table('discussions')->whereIn('id', $posts->pluck('discussion_id')->unique()->all())->get(['id', 'spoiler_work', 'spoiler_position'])->keyBy('id');

        $out = [];
        foreach ($posts as $quoted) {
            $d = $discussions[$quoted->discussion_id] ?? null;
            if ($quoted->spoiler_position !== null) {
                $out[] = [(string) ($quoted->spoiler_work ?? $d?->spoiler_work), (int) $quoted->spoiler_position];
            } elseif ($d && $d->spoiler_work !== null && $d->spoiler_position !== null) {
                $out[] = [(string) $d->spoiler_work, (int) $d->spoiler_position];
            }
        }

        return array_values(array_filter($out, fn ($q) => $q[0] !== ''));
    }

    /** Only works defined here keep their step's name on the row; the others are made from the number. */
    public function storedLabel(string $work, int $position): ?string
    {
        if (! str_starts_with($work, 'w:') || $position === Marks::FINISHED) {
            return null;
        }

        $steps = Ladders::steps($this->db->table('spoiler_works')->where('id', (int) substr($work, 2))->value('steps'));

        return isset($steps[$position - 1]) ? mb_substr($steps[$position - 1], 0, 64) : null;
    }

    /** @param int[] $tagIds */
    private function workForTags(array $tagIds): ?string
    {
        $tagIds = array_filter($tagIds);
        if (! $tagIds) {
            return null;
        }

        $work = $this->db->table('spoiler_work_tags')->whereIn('tag_id', $tagIds)->orderBy('tag_id')->value('work_id');

        return $work ? 'w:'.$work : null;
    }

    private function invalid(string $what): never
    {
        throw new ValidationException([self::ATTRIBUTE => $this->translator->trans('ernestdefoe-spoiler-shield.api.invalid_'.$what)]);
    }
}
