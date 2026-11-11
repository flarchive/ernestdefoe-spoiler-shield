<?php

namespace ErnestDefoe\SpoilerShield\Search;

use ErnestDefoe\SpoilerShield\Gate;
use ErnestDefoe\SpoilerShield\Marks;
use ErnestDefoe\SpoilerShield\Positions;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\ConnectionInterface;

/**
 * A word search must not confirm a guess: while a request searches by words
 * (`filter[q]`, set by RedactApi), discussions and posts hidden from the
 * searcher are left out of the query itself, as visibility scopes. So they
 * can't match, the totals and pages agree, and a readable discussion can't
 * turn up because a hidden reply in it matched.
 *
 * The same rules as the Gate, in SQL: a mark at or before your place, your
 * own, revealed, or a work whose default for you is "show".
 */
final class HiddenFromSearch
{
    private static ?User $actor = null;

    private static ?Session $session = null;

    /** @var array<string, mixed>|null this search's rules, worked out once */
    private static ?array $rules = null;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Positions $positions,
        private readonly SettingsRepositoryInterface $settings,
        private readonly \ErnestDefoe\SpoilerShield\Integrations $integrations,
    ) {
    }

    public static function during(User $actor, ?Session $session, callable $run): mixed
    {
        [$before, $beforeSession] = [self::$actor, self::$session];
        self::$actor = $actor;
        self::$session = $session;
        self::$rules = null;

        try {
            return $run();
        } finally {
            self::$actor = $before;
            self::$session = $beforeSession;
            self::$rules = null;
        }
    }

    /** @param \Illuminate\Database\Eloquent\Builder<\Flarum\Discussion\Discussion> $query */
    public function discussions(User $actor, $query): void
    {
        if (self::$actor && (int) self::$actor->id === (int) $actor->id) {
            $this->readable($query->getQuery(), $actor);
        }
    }

    /** @param \Illuminate\Database\Eloquent\Builder<\Flarum\Post\Post> $query */
    public function posts(User $actor, $query): void
    {
        if (! self::$actor || (int) self::$actor->id !== (int) $actor->id) {
            return;
        }

        $r = $this->rules($actor);

        // A stamped post carries its own work.
        $query->where(function ($w) use ($r, $actor) {
            $w->whereNull('posts.spoiler_position');
            if ($actor->exists) {
                $w->orWhere('posts.user_id', $actor->id);
            }
            if ($r['revealedP']) {
                $w->orWhereIn('posts.id', $r['revealedP']);
            }
            if ($r['open']) {
                $w->orWhereIn('posts.spoiler_work', $r['open']);
            }
            foreach ($r['limits'] as $work => $p) {
                $w->orWhere(fn ($x) => $x->where('posts.spoiler_work', $work)->where('posts.spoiler_position', '<=', $p['read']));
            }
            if ($r['unsetShow']) {
                $w->orWhereNotIn('posts.spoiler_work', array_merge(array_keys($r['limits']), $r['open']));
            }
        });

        // And its discussion must be one the searcher may read.
        $query->whereIn('posts.discussion_id', fn ($sub) => $this->readable($sub->select('discussions.id')->from('discussions'), $actor));
    }

    /**
     * @param \Illuminate\Database\Query\Builder $query
     * @return \Illuminate\Database\Query\Builder
     */
    private function readable($query, User $actor)
    {
        $r = $this->rules($actor);

        return $query->where(function ($w) use ($r, $actor) {
            $w->whereNull('discussions.spoiler_position')->orWhereNull('discussions.spoiler_work');
            if ($actor->exists) {
                $w->orWhere('discussions.user_id', $actor->id);
            }
            if ($r['revealedD']) {
                $w->orWhereIn('discussions.id', $r['revealedD']);
            }
            if ($r['open']) {
                $w->orWhereIn('discussions.spoiler_work', $r['open']);
            }
            foreach ($r['limits'] as $work => $p) {
                $w->orWhere(fn ($x) => $x->where('discussions.spoiler_work', $work)->where('discussions.spoiler_position', '<=', $p['frontier']));
            }
            if ($r['unsetShow']) {
                $w->orWhereNotIn('discussions.spoiler_work', array_merge(array_keys($r['limits']), $r['open']));
            }
        });
    }

    /**
     * Once per request: every work with a marked discussion, the searcher's
     * place in each, and what they revealed.
     *
     * @return array{open: string[], limits: array<string, array{read: int, frontier: int, set?: bool}>, revealedD: int[], revealedP: int[], unsetShow: bool}
     */
    private function rules(User $actor): array
    {
        if (self::$rules !== null && self::$rules['actor'] === (int) $actor->id) {
            return self::$rules['value'];
        }

        $works = array_values(array_unique(array_merge(
            $this->db->table('discussions')->whereNotNull('spoiler_position')->whereNotNull('spoiler_work')->distinct()->pluck('spoiler_work')->all(),
            $this->db->table('posts')->whereNotNull('spoiler_work')->distinct()->pluck('spoiler_work')->all(),
        )));
        $positions = $this->positions->for($actor, $works);

        // A work whose extension is switched off counts as unmarked: open to everyone.
        $open = array_values(array_filter($works, fn ($w) => ! $this->integrations->live($w)));
        $limits = [];
        $unsetShow = $this->settings->get($actor->isGuest() ? 'ernestdefoe-spoiler-shield.guests' : 'ernestdefoe-spoiler-shield.members') === 'show';

        foreach ($positions as $work => $p) {
            if (in_array($work, $open, true)) {
                continue;
            }
            if ($p['read'] === Marks::FINISHED || ($unsetShow && ($p['set'] ?? true) === false)) {
                $open[] = $work;
            } else {
                $limits[$work] = $p;
            }
        }

        $revealedD = $revealedP = [];
        if ($actor->isGuest()) {
            foreach ((array) self::$session?->get(Gate::SESSION_KEY, []) as $item) {
                if (is_string($item) && preg_match('/^([dp]):(\d+)$/', $item, $m)) {
                    $m[1] === 'd' ? $revealedD[] = (int) $m[2] : $revealedP[] = (int) $m[2];
                }
            }
        } else {
            foreach ($this->db->table('spoiler_reveals')->where('user_id', $actor->id)->get(['type', 'item_id']) as $row) {
                $row->type === 'd' ? $revealedD[] = (int) $row->item_id : $revealedP[] = (int) $row->item_id;
            }
        }

        $value = compact('open', 'limits', 'revealedD', 'revealedP', 'unsetShow');
        self::$rules = ['actor' => (int) $actor->id, 'value' => $value];

        return $value;
    }
}
