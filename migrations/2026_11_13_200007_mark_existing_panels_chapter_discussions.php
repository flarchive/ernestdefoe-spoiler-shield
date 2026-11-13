<?php

use ErnestDefoe\SpoilerShield\Marks;
use Illuminate\Database\Schema\Builder;

/*
 * Chapters Panels already put out have discussions: they get their marks (and
 * position slugs) now. After this, PanelsChapters keeps them in step. Panels
 * may be missing.
 */
return [
    'up' => function (Builder $schema) {
        try {
            $enabled = resolve(\Flarum\Extension\ExtensionManager::class)->isEnabled('ernestdefoe-panels');
        } catch (\Throwable) {
            $enabled = false;
        }
        if (! $enabled || ! $schema->hasTable('panel_chapters')) {
            return;
        }

        $db = $schema->getConnection();
        $db->table('panel_chapters')->whereNotNull('discussion_id')->select(['id', 'discussion_id', 'series_id', 'number'])
            ->chunkById(500, function ($rows) use ($db) {
                foreach ($rows as $row) {
                    $work = 'pn:'.(int) $row->series_id;
                    $position = max(1, Marks::panelsPosition($row->number));
                    // A discussion marked by hand at a work defined here keeps that.
                    $db->table('discussions')->where('id', $row->discussion_id)
                        ->where(fn ($q) => $q->whereNull('spoiler_work')->orWhere('spoiler_work', 'not like', 'w:%'))
                        ->update(['spoiler_work' => $work, 'spoiler_position' => $position, 'spoiler_label' => null, 'slug' => Marks::slugFor($work, $position, null)]);
                }
            });
    },
    'down' => function (Builder $schema) {
        // The columns go with an earlier migration.
    },
];
