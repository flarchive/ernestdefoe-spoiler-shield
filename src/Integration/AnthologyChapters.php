<?php

namespace ErnestDefoe\SpoilerShield\Integration;

use Ernestdefoe\Anthology\Chapter;
use Ernestdefoe\Anthology\Series;
use ErnestDefoe\SpoilerShield\Gate;
use ErnestDefoe\SpoilerShield\MarkStore;
use ErnestDefoe\SpoilerShield\MarkWriter;
use Flarum\Discussion\Discussion;
use Flarum\Discussion\UserState;
use Flarum\User\User;

/**
 * An Anthology chapter's discussion sits at its chapter: added, moved or taken
 * out of a series, the mark follows (Anthology saves chapters as models, so
 * their events fire). A deleted series takes its chapters with it in the
 * database, without events, so its discussions are cleared first.
 */
final class AnthologyChapters
{
    public static function saved(Chapter $chapter): void
    {
        $discussion = Discussion::query()->find($chapter->discussion_id);

        // A discussion marked by hand at a work defined here keeps that.
        if (! $discussion || str_starts_with((string) $discussion->spoiler_work, 'w:')) {
            return;
        }

        $discussion->spoiler_work = 'an:'.(int) $chapter->series_id;
        $discussion->spoiler_position = max(1, (int) $chapter->position);
        $discussion->spoiler_label = null;
        $discussion->save();
    }

    /**
     * Anthology counts a chapter read when its discussion was opened. A chapter
     * hidden from the reader (they opened a link to it, and saw a veil) isn't
     * read: its read marker stays where it was.
     */
    public static function reading(UserState $state): void
    {
        if (! $state->isDirty('last_read_post_number')) {
            return;
        }

        $discussions = resolve(MarkStore::class)->discussions([(int) $state->discussion_id]);
        $mark = $discussions[(int) $state->discussion_id] ?? null;
        if (! $mark || ! str_starts_with((string) $mark['work'], 'an:')) {
            return;
        }

        $reader = User::query()->find($state->user_id);
        if ($reader && resolve(Gate::class)->check($reader, null, $discussions, [])->discussionHidden((int) $state->discussion_id)) {
            $state->last_read_post_number = (int) $state->getOriginal('last_read_post_number');
        }
    }

    public static function deleted(Chapter $chapter): void
    {
        self::clear(Discussion::query()->whereKey($chapter->discussion_id), 'an:'.(int) $chapter->series_id);
    }

    public static function seriesDeleting(Series $series): void
    {
        resolve(MarkWriter::class)->clearWork('an:'.(int) $series->id);

        // A deleted series has no chapters left to read up to: its stamps would stay blurred for good.
        resolve(\Illuminate\Database\ConnectionInterface::class)->table('posts')->where('spoiler_work', 'an:'.(int) $series->id)
            ->update(['spoiler_work' => null, 'spoiler_position' => null, 'spoiler_label' => null]);
    }

    /** @param \Illuminate\Database\Eloquent\Builder<Discussion> $query */
    private static function clear($query, string $work): void
    {
        $query->where('spoiler_work', $work)->eachById(function (Discussion $discussion) {
            $discussion->spoiler_work = $discussion->spoiler_position = $discussion->spoiler_label = null;
            $discussion->save();
        });
    }
}
