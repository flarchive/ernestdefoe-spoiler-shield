<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        // Where a member is up to in a work ("w:12", "mq:345", "an:7"). 2147483647 = finished.
        $schema->create('spoiler_progress', function (Blueprint $table) {
            $table->unsignedInteger('user_id');
            $table->string('work', 24);
            $table->unsignedInteger('position');
            $table->dateTime('updated_at');

            $table->primary(['user_id', 'work']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // One item a member chose to see anyway: d = discussion, p = post.
        $schema->create('spoiler_reveals', function (Blueprint $table) {
            $table->unsignedInteger('user_id');
            $table->char('type', 1);
            $table->unsignedInteger('item_id');
            $table->dateTime('created_at');

            $table->primary(['user_id', 'type', 'item_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('spoiler_reveals');
        $schema->dropIfExists('spoiler_progress');
    },
];
