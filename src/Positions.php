<?php

namespace ErnestDefoe\SpoilerShield;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Where a member is up to, per work. Two numbers each:
 *  - read: how far they've got (replies stamped up to here are safe);
 *  - frontier: how far they may look (a discussion up to here is safe).
 * They're the same, except in an Anthology series, where the next unread
 * chapter is open (you have to be able to read it) though nobody's
 * discussion of it is.
 *
 * A work missing from the answer is one the member never set.
 */
final class Positions
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Integrations $integrations,
    ) {
    }

    /**
     * @param string[] $works
     * @return array<string, array{read: int, frontier: int, set?: bool}>
     */
    public function for(User $actor, array $works): array
    {
        // Guests have no rows, but an Anthology series still starts them at chapter 1.
        return $this->forMany([(int) $actor->id], $works)[(int) $actor->id] ?? [];
    }

    /**
     * Several members at once (an email going out): the same queries as one.
     *
     * @param int[] $userIds
     * @param string[] $works
     * @return array<int, array<string, array{read: int, frontier: int, set?: bool}>>
     */
    public function forMany(array $userIds, array $works): array
    {
        if (! $userIds || ! $works) {
            return [];
        }

        $works = array_values(array_unique($works));
        $out = [];

        foreach ($this->db->table('spoiler_progress')->whereIn('user_id', $userIds)->whereIn('work', $works)->get(['user_id', 'work', 'position']) as $row) {
            $out[(int) $row->user_id][$row->work] = ['read' => (int) $row->position, 'frontier' => (int) $row->position, 'set' => true];
        }

        $series = [];
        foreach ($works as $work) {
            if (str_starts_with($work, 'an:')) {
                $series[] = (int) substr($work, 3);
            }
        }

        if ($series && $this->integrations->anthology()) {
            foreach ($this->anthology($userIds, $series) as $userId => $positions) {
                foreach ($positions as $work => $position) {
                    // A member who marked the series finished stays finished.
                    if (($out[$userId][$work]['read'] ?? -1) < $position['read']) {
                        $out[$userId][$work] = $position;
                    }
                }
            }
        }

        return $out;
    }

    /** How far the member has got in one work, or null if they never said. */
    public function read(User $actor, string $work): ?int
    {
        return $this->for($actor, [$work])[$work]['read'] ?? null;
    }

    /**
     * Anthology keeps no progress of its own: a chapter is read when the member
     * opened its discussion (core's discussion_user). Two queries for every
     * series and member: the chapters, and which ones each member opened.
     *
     * @param int[] $userIds
     * @param int[] $series
     * @return array<int, array<string, array{read: int, frontier: int, set?: bool}>>
     */
    private function anthology(array $userIds, array $series): array
    {
        $positions = [];
        // A chapter a moderator hid isn't one anybody has to get through.
        $chapters = $this->db->table('anthology_chapters')
            ->join('discussions', 'discussions.id', '=', 'anthology_chapters.discussion_id')
            ->whereIn('anthology_chapters.series_id', $series)
            ->whereNull('discussions.hidden_at')
            ->orderBy('anthology_chapters.position')
            ->get(['anthology_chapters.series_id', 'anthology_chapters.position']);
        foreach ($chapters as $row) {
            $positions[(int) $row->series_id][] = (int) $row->position;
        }

        // Chapters each member opened. Only an unbroken run from the first chapter counts as
        // read: following a link to chapter 9 (and seeing it blurred) doesn't unlock it.
        $opened = [];
        $rows = $this->db->table('discussion_user')
            ->join('anthology_chapters', 'anthology_chapters.discussion_id', '=', 'discussion_user.discussion_id')
            ->whereIn('discussion_user.user_id', $userIds)
            ->whereIn('anthology_chapters.series_id', $series)
            ->where('discussion_user.last_read_post_number', '>', 0)
            ->get(['discussion_user.user_id', 'anthology_chapters.series_id', 'anthology_chapters.position']);
        foreach ($rows as $row) {
            $opened[(int) $row->user_id][(int) $row->series_id][(int) $row->position] = true;
        }

        $read = [];
        foreach ($opened as $userId => $bySeries) {
            foreach ($bySeries as $id => $set) {
                $got = 0;
                foreach ($positions[$id] ?? [] as $position) {
                    if (! isset($set[$position])) {
                        break;
                    }
                    $got = $position;
                }
                $read[$userId][$id] = $got;
            }
        }

        $out = [];
        foreach ($userIds as $userId) {
            foreach ($positions as $id => $list) {
                $got = $read[$userId][$id] ?? 0;
                $next = $got;
                foreach ($list as $position) {
                    if ($position > $got) {
                        $next = $position;
                        break;
                    }
                }
                // Nothing read yet counts as never said (the admin's default can open it all).
                $out[$userId]['an:'.$id] = ['read' => $got, 'frontier' => $next, 'set' => $got > 0];
            }
        }

        return $out;
    }
}
