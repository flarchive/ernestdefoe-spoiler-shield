<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * A work defined on the forum itself (book, show, game, anything): its ladder
 * of positions is `steps`, a JSON list of labels, position = place in the list
 * (1-based). Works from Marquee and Anthology live in those extensions' tables.
 */
return [
    'up' => function (Builder $schema) {
        $schema->create('spoiler_works', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title', 150);
            $table->string('kind', 16)->default('other');
            $table->text('steps');
            // A catalogue id ("openlibrary:OL45883W", "igdb:1942") that Curio collections match on.
            $table->string('external', 120)->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->timestamps();

            $table->index('title');
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        // A tag attached to a work: new discussions in it belong to the work.
        $schema->create('spoiler_work_tags', function (Blueprint $table) {
            $table->unsignedInteger('tag_id')->primary();
            $table->unsignedInteger('work_id');

            $table->foreign('work_id')->references('id')->on('spoiler_works')->cascadeOnDelete();
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('spoiler_work_tags');
        $schema->dropIfExists('spoiler_works');
    },
];
