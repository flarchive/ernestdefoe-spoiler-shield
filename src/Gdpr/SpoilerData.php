<?php

namespace ErnestDefoe\SpoilerShield\Gdpr;

use Flarum\Gdpr\Data\Type;
use Illuminate\Database\ConnectionInterface;

/**
 * A member's places in each work and what they chose to reveal, in a data
 * export; both go on erasure (the rows also go with the account through their
 * foreign keys). Works they defined stay, without their name. Registered only
 * when flarum/gdpr is enabled.
 */
class SpoilerData extends Type
{
    public static function exportDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-spoiler-shield.lib.gdpr.export');
    }

    public static function anonymizeDescription(): string
    {
        return self::deleteDescription();
    }

    public static function deleteDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-spoiler-shield.lib.gdpr.delete');
    }

    public function export(): ?array
    {
        $db = resolve(ConnectionInterface::class);

        return [
            ['spoiler-shield/progress.json' => $this->encodeForExport($db->table('spoiler_progress')->where('user_id', $this->user->id)->get(['work', 'position', 'updated_at'])->all())],
            ['spoiler-shield/reveals.json' => $this->encodeForExport($db->table('spoiler_reveals')->where('user_id', $this->user->id)->get(['type', 'item_id', 'created_at'])->all())],
        ];
    }

    public function anonymize(): void
    {
        $this->delete();
    }

    public function delete(): void
    {
        $db = resolve(ConnectionInterface::class);
        $db->table('spoiler_progress')->where('user_id', $this->user->id)->delete();
        $db->table('spoiler_reveals')->where('user_id', $this->user->id)->delete();
        $db->table('spoiler_works')->where('user_id', $this->user->id)->update(['user_id' => null]);
    }
}
