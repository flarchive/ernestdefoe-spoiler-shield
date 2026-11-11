<?php

namespace ErnestDefoe\SpoilerShield;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Names and ladders of works, from wherever each one lives: works defined here
 * (spoiler_works), Marquee titles (marquee_media) and Anthology series
 * (anthology_series). At most one query per source, however many works.
 */
final class Works
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Integrations $integrations,
    ) {
    }

    /**
     * @param string[] $keys
     * @return array<string, array{title: string, kind: string, catalog: ?string, steps?: list<string>}>
     */
    public function describe(array $keys, ?User $actor = null): array
    {
        $ids = ['w' => [], 'mq' => [], 'an' => []];
        foreach (array_unique($keys) as $key) {
            if ($parsed = Marks::parse($key)) {
                $ids[$parsed[0]][] = $parsed[1];
            }
        }

        $out = [];

        if ($ids['w']) {
            foreach ($this->db->table('spoiler_works')->whereIn('id', $ids['w'])->get(['id', 'title', 'kind', 'external', 'steps']) as $row) {
                $out['w:'.$row->id] = ['title' => (string) $row->title, 'kind' => (string) $row->kind, 'catalog' => $row->external ?: null, 'steps' => Ladders::steps($row->steps)];
            }
        }

        if ($ids['mq'] && $this->integrations->marquee()) {
            foreach ($this->db->table('marquee_media')->whereIn('id', $ids['mq'])->get(['id', 'title', 'kind', 'external_id']) as $row) {
                $out['mq:'.$row->id] = [
                    'title' => (string) $row->title,
                    'kind' => $row->kind === 'tv' ? 'show' : (string) $row->kind,
                    // Curio files Marquee's titles as "marquee-tv" / "1399".
                    'catalog' => 'marquee-'.$row->kind.':'.$row->external_id,
                ];
            }
        }

        // An Anthology series' name only for those who may see Anthology.
        if ($ids['an'] && $this->integrations->anthology() && (! $actor || $actor->hasPermission('anthology.view'))) {
            foreach ($this->db->table('anthology_series')->whereIn('id', $ids['an'])->get(['id', 'title']) as $row) {
                $out['an:'.$row->id] = ['title' => (string) $row->title, 'kind' => 'book', 'catalog' => null];
            }
        }

        return $out;
    }

    /**
     * The works a member has finished or owns in their Curio collection, among
     * these ("finished: unlock everything" is offered for them). One query.
     *
     * @param array<string, array{title: string, kind: string, catalog: ?string, steps?: list<string>}> $works
     * @return array<string, true>
     */
    public function inCollection(User $actor, array $works): array
    {
        if ($actor->isGuest() || ! $this->integrations->curio()) {
            return [];
        }

        $wanted = [];
        foreach ($works as $key => $work) {
            if ($work['catalog'] && str_contains($work['catalog'], ':')) {
                [$source, $id] = explode(':', $work['catalog'], 2);
                $wanted[$source][$id] = $key;
            }
        }

        if (! $wanted) {
            return [];
        }

        $rows = $this->db->table('curio_items')
            ->join('curio_works', 'curio_works.id', '=', 'curio_items.work_id')
            ->where('curio_items.user_id', $actor->id)
            ->where(fn ($q) => $q->where('curio_items.progress', 'completed')->orWhere('curio_items.status', 'owned'))
            ->where(function ($q) use ($wanted) {
                foreach ($wanted as $source => $ids) {
                    $q->orWhere(fn ($q) => $q->where('curio_works.source', $source)->whereIn('curio_works.source_id', array_map('strval', array_keys($ids))));
                }
            })
            ->get(['curio_works.source', 'curio_works.source_id']);

        $out = [];
        foreach ($rows as $row) {
            if ($key = $wanted[$row->source][$row->source_id] ?? null) {
                $out[$key] = true;
            }
        }

        return $out;
    }
}
