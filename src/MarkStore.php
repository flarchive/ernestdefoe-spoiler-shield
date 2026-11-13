<?php

namespace ErnestDefoe\SpoilerShield;

use Illuminate\Database\ConnectionInterface;

/** Marks read straight from the rows, for things that don't come from the API (one query per call). */
final class MarkStore
{
    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    /**
     * @param int[] $ids
     * @return array<int, array{work: ?string, position: ?int, label: ?string, user: ?int, first: ?int, last: ?int}>
     */
    public function discussions(array $ids): array
    {
        $out = [];
        if (! $ids) {
            return $out;
        }

        foreach ($this->db->table('discussions')->whereIn('id', array_values(array_unique($ids)))->get(['id', 'user_id', 'spoiler_work', 'spoiler_position', 'spoiler_label', 'first_post_id', 'last_post_id']) as $row) {
            $out[(int) $row->id] = [
                'work' => $row->spoiler_work,
                'position' => $row->spoiler_position === null ? null : (int) $row->spoiler_position,
                'label' => $row->spoiler_label,
                'user' => $row->user_id === null ? null : (int) $row->user_id,
                'first' => $row->first_post_id === null ? null : (int) $row->first_post_id,
                'last' => $row->last_post_id === null ? null : (int) $row->last_post_id,
            ];
        }

        return $out;
    }

    /**
     * Panels chapters in the shape of their discussions' marks (work pn:{series}, position
     * number × 100, the uploader as author), keyed by discussion id, or by the negated
     * chapter id for one that has no discussion yet (scheduled).
     *
     * @param int[] $chapterIds
     * @return array<int, array{work: string, position: int, label: null, user: ?int, first: null, last: null, chapter: int}>
     */
    public function panelsChapters(array $chapterIds): array
    {
        $out = [];
        if (! $chapterIds) {
            return $out;
        }

        foreach ($this->db->table('panel_chapters')->whereIn('id', array_values(array_unique($chapterIds)))->get(['id', 'series_id', 'number', 'discussion_id', 'user_id']) as $row) {
            $out[$row->discussion_id ? (int) $row->discussion_id : -(int) $row->id] = [
                'work' => 'pn:'.(int) $row->series_id,
                'position' => max(1, Marks::panelsPosition($row->number)),
                'label' => null,
                'user' => $row->user_id === null ? null : (int) $row->user_id,
                'first' => null,
                'last' => null,
                'chapter' => (int) $row->id,
            ];
        }

        return $out;
    }

    /**
     * @param int[] $ids
     * @return array<int, array{discussion: int, position: ?int, label: ?string, user: ?int, work: ?string}>
     */
    public function posts(array $ids): array
    {
        $out = [];
        if (! $ids) {
            return $out;
        }

        foreach ($this->db->table('posts')->whereIn('id', array_values(array_unique($ids)))->get(['id', 'discussion_id', 'user_id', 'spoiler_position', 'spoiler_label', 'spoiler_work']) as $row) {
            $out[(int) $row->id] = [
                'discussion' => (int) $row->discussion_id,
                'position' => $row->spoiler_position === null ? null : (int) $row->spoiler_position,
                'label' => $row->spoiler_label,
                'user' => $row->user_id === null ? null : (int) $row->user_id,
                'work' => $row->spoiler_work,
            ];
        }

        return $out;
    }

    /**
     * The discussions each discussion's first and last posts link to (/d/{id} in their stored content). One query.
     *
     * @param int[] $ids
     * @return array<int, int[]>
     */
    public function linksFromEnds(array $ids): array
    {
        if (! $ids) {
            return [];
        }

        $rows = $this->db->table('posts')
            ->join('discussions', fn ($j) => $j->on('discussions.first_post_id', '=', 'posts.id')->orOn('discussions.last_post_id', '=', 'posts.id'))
            ->whereIn('discussions.id', $ids)
            ->where('posts.content', 'like', '%/d/%')
            ->get(['discussions.id', 'posts.content']);

        $out = [];
        foreach ($rows as $row) {
            preg_match_all('#/d/(\d+)#', (string) $row->content, $m);
            $out[(int) $row->id] = array_merge($out[(int) $row->id] ?? [], array_map('intval', $m[1]));
        }

        return $out;
    }
}
