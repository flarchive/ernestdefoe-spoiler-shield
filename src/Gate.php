<?php

namespace ErnestDefoe\SpoilerShield;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Contracts\Session\Session;

/**
 * The one place that decides what a viewer may read. Everything that shows a
 * discussion or a post (the API redactor, emails, the page's head) asks here.
 *
 * A discussion with a position is hidden from a viewer who isn't that far
 * yet. A post is hidden when its discussion is, or when it was stamped further
 * along than the viewer has got. Authors always see their own; anyone may
 * reveal one item, and that's remembered (members in the database, guests in
 * their session).
 *
 * Constant queries per call, however many items: progress, reveals.
 */
final class Gate
{
    public const SESSION_KEY = 'spoiler_shield_reveals';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Positions $positions,
        private readonly SettingsRepositoryInterface $settings,
        private readonly Integrations $integrations,
    ) {
    }

    /**
     * @param array<int, array{work: ?string, position: ?int, user: ?int}> $discussions
     * @param array<int, array{discussion: int, position: ?int, user: ?int, work?: ?string}> $posts
     */
    public function check(User $actor, ?Session $session, array $discussions, array $posts): Verdict
    {
        $works = $this->works($discussions, $posts);
        if (! $works) {
            return new Verdict();
        }

        [$revealedD, $revealedP] = $this->reveals($actor, $session, array_keys($discussions), array_keys($posts));

        return $this->decide($actor, $this->positions->for($actor, $works), $revealedD, $revealedP, $discussions, $posts);
    }

    /**
     * The same check for many members at once (an email's recipients), in the
     * same number of queries as one.
     *
     * @param User[] $users
     * @param array<int, array{work: ?string, position: ?int, user: ?int}> $discussions
     * @param array<int, array{discussion: int, position: ?int, user: ?int, work?: ?string}> $posts
     * @return array<int, Verdict> by user id
     */
    public function checkMany(array $users, array $discussions, array $posts): array
    {
        $works = $this->works($discussions, $posts);
        $ids = array_map(fn (User $u) => (int) $u->id, $users);
        $positions = $works ? $this->positions->forMany($ids, $works) : [];

        $revealed = [];
        if ($works && $ids) {
            foreach ($this->revealQuery(array_keys($discussions), array_keys($posts))->whereIn('user_id', $ids)->get(['user_id', 'type', 'item_id']) as $row) {
                $revealed[(int) $row->user_id][$row->type === 'd' ? 0 : 1][(int) $row->item_id] = true;
            }
        }

        $out = [];
        foreach ($users as $user) {
            $out[(int) $user->id] = $works
                ? $this->decide($user, $positions[(int) $user->id] ?? [], $revealed[(int) $user->id][0] ?? [], $revealed[(int) $user->id][1] ?? [], $discussions, $posts)
                : new Verdict();
        }

        return $out;
    }

    /**
     * @param array<int, array{work: ?string, position: ?int, user: ?int}> $discussions
     * @param array<int, array{discussion: int, position: ?int, user: ?int, work?: ?string}> $posts
     * @return string[]
     */
    private function works(array $discussions, array $posts = []): array
    {
        $works = [];
        foreach ([...$discussions, ...$posts] as $item) {
            if (($item['work'] ?? null) !== null) {
                $works[$item['work']] = true;
            }
        }

        return array_keys($works);
    }

    /**
     * @param array<string, array{read: int, frontier: int, set?: bool}> $positions
     * @param array<int, true> $revealedD
     * @param array<int, true> $revealedP
     * @param array<int, array{work: ?string, position: ?int, user: ?int}> $discussions
     * @param array<int, array{discussion: int, position: ?int, user: ?int, work?: ?string}> $posts
     */
    private function decide(User $actor, array $positions, array $revealedD, array $revealedP, array $discussions, array $posts): Verdict
    {
        $verdict = new Verdict();

        // A viewer who never said where they are: guests and members each get the admin's default.
        $unset = $this->settings->get($actor->isGuest() ? 'ernestdefoe-spoiler-shield.guests' : 'ernestdefoe-spoiler-shield.members') === 'show'
            ? Marks::FINISHED : -1;

        // An Anthology series nobody started reading still opens its first chapter; "show" opens it all.
        if ($unset === Marks::FINISHED) {
            foreach ($positions as $work => $p) {
                if (($p['set'] ?? true) === false) {
                    $positions[$work] = ['read' => Marks::FINISHED, 'frontier' => Marks::FINISHED, 'set' => false];
                }
            }
        }

        foreach ($discussions as $id => $d) {
            if ($d['position'] === null || ! $this->integrations->live($d['work'])) {
                continue;
            }

            $frontier = $positions[$d['work']]['frontier'] ?? $unset;
            $mine = $actor->exists && $d['user'] === (int) $actor->id;

            if (! $mine && $d['position'] > $frontier) {
                isset($revealedD[$id]) ? $verdict->revealedDiscussions[$id] = true : $verdict->hiddenDiscussions[$id] = true;
            }
        }

        foreach ($posts as $id => $p) {
            $d = $discussions[$p['discussion']] ?? null;
            // A stamp carries its own work; an older one, or a post never stamped, goes with its discussion's.
            $work = ($p['work'] ?? null) ?? ($d['work'] ?? null);

            if (! $this->integrations->live($work)) {
                continue;
            }

            $mine = $actor->exists && $p['user'] === (int) $actor->id;
            $read = $positions[$work]['read'] ?? $unset;

            if (isset($verdict->hiddenDiscussions[$p['discussion']])) {
                $hidden = true;
            } else {
                // Inside a discussion you may read, its own position is safe too (same work only).
                $floor = $d !== null && $d['work'] === $work ? ($d['position'] ?? -1) : -1;
                $hidden = $p['position'] !== null && $p['position'] > max($read, $floor);
            }

            if ($hidden && ! $mine) {
                isset($revealedP[$id]) ? $verdict->revealedPosts[$id] = true : $verdict->hiddenPosts[$id] = true;
            }
        }

        $verdict->positions = $positions;

        return $verdict;
    }

    /**
     * @param int[] $discussionIds
     * @param int[] $postIds
     * @return array{0: array<int, true>, 1: array<int, true>}
     */
    private function reveals(User $actor, ?Session $session, array $discussionIds, array $postIds): array
    {
        $d = $p = [];

        if ($actor->isGuest()) {
            $stored = $session?->get(self::SESSION_KEY);
            foreach (is_array($stored) ? $stored : [] as $item) {
                if (is_string($item) && preg_match('/^([dp]):(\d+)$/', $item, $m)) {
                    $m[1] === 'd' ? $d[(int) $m[2]] = true : $p[(int) $m[2]] = true;
                }
            }

            return [$d, $p];
        }

        if (! $discussionIds && ! $postIds) {
            return [$d, $p];
        }

        $rows = $this->revealQuery($discussionIds, $postIds)->where('user_id', $actor->id)->get(['type', 'item_id']);

        foreach ($rows as $row) {
            $row->type === 'd' ? $d[(int) $row->item_id] = true : $p[(int) $row->item_id] = true;
        }

        return [$d, $p];
    }

    /**
     * @param int[] $discussionIds
     * @param int[] $postIds
     */
    private function revealQuery(array $discussionIds, array $postIds): \Illuminate\Database\Query\Builder
    {
        return $this->db->table('spoiler_reveals')->where(function ($q) use ($discussionIds, $postIds) {
            $q->where(fn ($q) => $q->where('type', 'd')->whereIn('item_id', $discussionIds ?: [0]))
                ->orWhere(fn ($q) => $q->where('type', 'p')->whereIn('item_id', $postIds ?: [0]));
        });
    }
}
