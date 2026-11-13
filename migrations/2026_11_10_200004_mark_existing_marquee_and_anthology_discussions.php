<?php

use ErnestDefoe\SpoilerShield\Marks;
use Illuminate\Database\Schema\Builder;

/*
 * Discussions Marquee already linked to an episode or a season, and chapters
 * already in Anthology series, get their marks (and position slugs) now. After
 * this the MarkWriter keeps them in step. Either extension may be missing.
 */
return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();
        // Tables outlive a disabled extension: only an enabled one's links count.
        $enabled = function (string $id): bool {
            try {
                return resolve(\Flarum\Extension\ExtensionManager::class)->isEnabled($id);
            } catch (\Throwable) {
                return false;
            }
        };

        if ($enabled('ernestdefoe-marquee') && $schema->hasColumn('discussions', 'marquee_media_id')) {
            // chunkById: the filter (spoiler_work null) changes as rows are filled, which makes chunk() skip pages.
            $db->table('discussions')->whereNotNull('marquee_media_id')->whereNull('spoiler_work')
                ->select(['id', 'marquee_media_id', 'marquee_season', 'marquee_episode'])
                ->chunkById(500, function ($rows) use ($db) {
                    foreach ($rows as $row) {
                        $position = Marks::marqueePosition($row->marquee_season ? (int) $row->marquee_season : null, $row->marquee_episode ? (int) $row->marquee_episode : null);
                        $episode = $position === null ? 0 : $position % Marks::SEASON;
                        $db->table('discussions')->where('id', $row->id)->update([
                            'spoiler_work' => 'mq:'.(int) $row->marquee_media_id,
                            'spoiler_position' => $position,
                        ] + ($position === null ? [] : [
                            'slug' => $episode === Marks::SEASON_END ? 'season-'.intdiv($position, Marks::SEASON) : Marks::slug('S'.intdiv($position, Marks::SEASON).'E'.$episode),
                        ]));
                    }
                });
        }

        if ($enabled('ernestdefoe-anthology') && $schema->hasTable('anthology_chapters')) {
            $db->table('anthology_chapters')->select(['discussion_id', 'series_id', 'position'])
                ->chunkById(500, function ($rows) use ($db) {
                    foreach ($rows as $row) {
                        $position = max(1, (int) $row->position);
                        $db->table('discussions')->where('id', $row->discussion_id)
                            ->where(fn ($q) => $q->whereNull('spoiler_work')->orWhere('spoiler_work', 'like', 'mq:%'))
                            ->update(['spoiler_work' => 'an:'.(int) $row->series_id, 'spoiler_position' => $position, 'spoiler_label' => null, 'slug' => 'chapter-'.$position]);
                    }
                }, 'discussion_id');
        }
    },
    'down' => function (Builder $schema) {
        // The columns go with the migration before this one.
    },
];
