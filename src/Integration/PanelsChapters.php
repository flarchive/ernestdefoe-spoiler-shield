<?php

namespace ErnestDefoe\SpoilerShield\Integration;

use ErnestDefoe\Panels\Model\Chapter;
use ErnestDefoe\SpoilerShield\Integrations;
use ErnestDefoe\SpoilerShield\Marks;
use Flarum\Discussion\Discussion;

/**
 * A Panels chapter's discussion sits at its chapter (number × 100). Panels
 * starts the discussion when the chapter comes out and saves the chapter with
 * its discussion_id, so `saved` marks it then, and again if the chapter is
 * renumbered. A chapter taken down keeps its mark (its discussion is hidden,
 * and a restore brings it back where it was); a deleted one is cleared.
 */
final class PanelsChapters
{
    public static function saved(Chapter $chapter): void
    {
        if (! $chapter->discussion_id || ! resolve(Integrations::class)->panels()) {
            return;
        }

        $discussion = Discussion::query()->find($chapter->discussion_id);

        // A discussion marked by hand at a work defined here keeps that.
        if (! $discussion || str_starts_with((string) $discussion->spoiler_work, 'w:')) {
            return;
        }

        $discussion->spoiler_work = 'pn:'.(int) $chapter->series_id;
        $discussion->spoiler_position = max(1, Marks::panelsPosition($chapter->number));
        $discussion->spoiler_label = null;
        if ($discussion->isDirty()) {
            $discussion->save();
        }
    }

    public static function deleted(Chapter $chapter): void
    {
        if (! $chapter->discussion_id) {
            return;
        }

        Discussion::query()->whereKey($chapter->discussion_id)->where('spoiler_work', 'pn:'.(int) $chapter->series_id)
            ->eachById(function (Discussion $discussion) {
                $discussion->spoiler_work = $discussion->spoiler_position = $discussion->spoiler_label = null;
                $discussion->save();
            });
    }
}
