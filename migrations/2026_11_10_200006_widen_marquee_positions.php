<?php

use Illuminate\Database\Schema\Builder;

/*
 * Marquee places were season × 1000 + episode, which runs into the next
 * season past 998 episodes. Now season × 100000 (+ 99999 for the whole
 * season). Old values are all below 100000; new ones start above it.
 */
return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();
        $convert = fn (int $old) => intdiv($old, 1000) * 100000 + ($old % 1000 === 999 ? 99999 : $old % 1000);

        foreach ([['discussions', 'spoiler_work', 'spoiler_position'], ['posts', 'spoiler_work', 'spoiler_position'], ['spoiler_progress', 'work', 'position']] as [$table, $work, $position]) {
            $rows = $db->table($table)->where($work, 'like', 'mq:%')->where($position, '<', 100000)->whereNotNull($position);
            $convertRow = function ($row) use ($db, $table, $work, $position, $convert) {
                $query = $db->table($table);
                $table === 'spoiler_progress'
                    ? $query->where('user_id', $row->user_id)->where($work, $row->{$work})
                    : $query->where('id', $row->id);
                $query->update([$position => $convert((int) $row->{$position})]);
            };

            // chunkById where there's an id (converted rows leave the filter, which makes chunk() skip
            // pages); members' places have no id and are few per member, so one pass.
            $table === 'spoiler_progress'
                ? $rows->get(['user_id', $work, $position])->each($convertRow)
                : $rows->select(['id', $work, $position])->chunkById(500, fn ($chunk) => $chunk->each($convertRow));
        }
    },
    'down' => function (Builder $schema) {
        // Forward only.
    },
];
