<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * The marks live on the rows themselves, so reading them costs no query: a
 * discussion belongs to a work and may sit at a position on its ladder; a post
 * may sit further along (its author's position when they replied).
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('discussions', function (Blueprint $table) {
            $table->string('spoiler_work', 24)->nullable();
            $table->unsignedInteger('spoiler_position')->nullable();
            $table->string('spoiler_label', 64)->nullable();
        });

        $schema->table('posts', function (Blueprint $table) {
            $table->unsignedInteger('spoiler_position')->nullable();
            $table->string('spoiler_label', 64)->nullable();
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('discussions', function (Blueprint $table) {
            $table->dropColumn(['spoiler_work', 'spoiler_position', 'spoiler_label']);
        });

        $schema->table('posts', function (Blueprint $table) {
            $table->dropColumn(['spoiler_position', 'spoiler_label']);
        });
    },
];
