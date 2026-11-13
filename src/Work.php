<?php

namespace ErnestDefoe\SpoilerShield;

use Flarum\Database\AbstractModel;

/**
 * A work defined on the forum (spoiler_works). `steps` is the ladder, a JSON
 * list of labels; a position is a step's place in it, from 1.
 *
 * @property int $id
 * @property string $title
 * @property string $kind
 * @property string $steps
 * @property string|null $external
 * @property int|null $user_id
 */
class Work extends AbstractModel
{
    protected $table = 'spoiler_works';

    public $timestamps = true;

    protected $fillable = ['user_id'];
}
