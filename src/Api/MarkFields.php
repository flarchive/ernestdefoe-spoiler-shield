<?php

namespace ErnestDefoe\SpoilerShield\Api;

use ErnestDefoe\SpoilerShield\MarkWriter;
use Flarum\Api\Schema;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;

/**
 * The marks as the Redactor reads them (`ssMark`, read from the row, so free),
 * and the attribute the composer writes (`spoilerShield`, handled by the
 * MarkWriter on the Saving events). The Redactor removes ssMark from every
 * response and puts a `spoilerShield` description in its place.
 */
final class MarkFields
{
    /** @return array<int, mixed> */
    public static function discussion(): array
    {
        return [
            Schema\Arr::make('ssMark')
                ->get(fn (Discussion $d) => [
                    $d->spoiler_work,
                    $d->spoiler_position === null ? null : (int) $d->spoiler_position,
                    $d->spoiler_label,
                    $d->user_id === null ? null : (int) $d->user_id,
                    $d->first_post_id === null ? null : (int) $d->first_post_id,
                    $d->last_post_id === null ? null : (int) $d->last_post_id,
                ]),
            Schema\Arr::make(MarkWriter::ATTRIBUTE)
                ->hidden()
                ->writable()
                ->nullable()
                ->set(fn () => null),
        ];
    }

    /** @return array<int, mixed> */
    public static function post(): array
    {
        return [
            Schema\Arr::make('ssMark')
                ->get(fn (Post $p) => [
                    $p->spoiler_position === null ? null : (int) $p->spoiler_position,
                    $p->spoiler_label,
                    $p->user_id === null ? null : (int) $p->user_id,
                    (int) $p->discussion_id,
                    $p->spoiler_work,
                ]),
            Schema\Arr::make(MarkWriter::ATTRIBUTE)
                ->hidden()
                ->writable()
                ->nullable()
                ->set(fn () => null),
        ];
    }
}
