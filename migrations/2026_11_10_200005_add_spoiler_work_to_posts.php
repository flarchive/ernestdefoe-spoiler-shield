<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * A stamped post carries its own work: a quote can bring another work's
 * spoiler into a discussion, and a discussion that's unmarked (or unlinked in
 * Marquee, or taken out of an Anthology series) mustn't expose its stamped
 * replies. Index on discussions.spoiler_work for the search rules.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasColumn('posts', 'spoiler_work')) {
            $schema->table('posts', function (Blueprint $table) {
                $table->string('spoiler_work', 24)->nullable();
                $table->index('spoiler_work');
            });
        }
        if (! $schema->hasIndex('discussions', ['spoiler_work'])) {
            $schema->table('discussions', function (Blueprint $table) {
                $table->index('spoiler_work');
            });
        }

        $db = $schema->getConnection();
        // chunkById: the filter (spoiler_work null) changes as rows are filled, which makes chunk() skip pages.
        $db->table('posts')->whereNotNull('spoiler_position')->whereNull('spoiler_work')->select(['id', 'discussion_id'])
            ->chunkById(500, function ($rows) use ($db) {
                $works = $db->table('discussions')->whereIn('id', $rows->pluck('discussion_id')->unique()->all())->pluck('spoiler_work', 'id');
                foreach ($rows as $row) {
                    if ($work = $works[$row->discussion_id] ?? null) {
                        $db->table('posts')->where('id', $row->id)->update(['spoiler_work' => $work]);
                    }
                }
            });
    },
    'down' => function (Builder $schema) {
        $schema->table('discussions', function (Blueprint $table) {
            $table->dropIndex(['spoiler_work']);
        });
        $schema->table('posts', function (Blueprint $table) {
            $table->dropIndex(['spoiler_work']);
            $table->dropColumn('spoiler_work');
        });
    },
];
