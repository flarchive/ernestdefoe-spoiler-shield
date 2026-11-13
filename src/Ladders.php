<?php

namespace ErnestDefoe\SpoilerShield;

use Carbon\Carbon;
use Illuminate\Database\ConnectionInterface;

/**
 * Each work's ladder: which positions exist, for checking what someone sends
 * and for drawing the pickers.
 *
 *  - a work defined here: its steps, in order;
 *  - a Marquee show: its seasons and episode counts (Marquee's own data, from
 *    TMDB), so "S2E5" is a real episode; a film or game has one rung, finished;
 *  - an Anthology series: its chapters, read automatically;
 *  - a Panels series: its chapters out now (number × 100), read automatically.
 */
final class Ladders
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Integrations $integrations,
    ) {
    }

    /**
     * The ladder in the shape the forum draws, or null for a work that doesn't exist.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $work): ?array
    {
        [$source, $id] = Marks::parse($work) ?? [null, 0];

        if ($source === 'w') {
            $row = $this->db->table('spoiler_works')->where('id', $id)->first(['steps']);

            return $row ? ['type' => 'steps', 'steps' => self::steps($row->steps)] : null;
        }

        if ($source === 'mq' && $this->integrations->marquee()) {
            $row = $this->db->table('marquee_media')->where('id', $id)->first(['kind', 'data']);
            if (! $row) {
                return null;
            }

            if ($row->kind !== 'tv') {
                return ['type' => 'finished'];
            }

            $data = json_decode((string) $row->data, true);
            $seasons = [];
            foreach ((array) ($data['seasons'] ?? []) as $season) {
                if ((int) ($season['number'] ?? 0) > 0 && (int) ($season['episodes'] ?? 0) > 0) {
                    $seasons[] = ['number' => (int) $season['number'], 'episodes' => (int) $season['episodes']];
                }
            }

            return ['type' => 'episodes', 'seasons' => $seasons];
        }

        if ($source === 'an' && $this->integrations->anthology()) {
            $chapters = $this->db->table('anthology_chapters')->where('series_id', $id)->orderBy('position')->pluck('position')->all();

            return $this->db->table('anthology_series')->where('id', $id)->exists()
                ? ['type' => 'chapters', 'chapters' => array_map('intval', $chapters)]
                : null;
        }

        if ($source === 'pn' && $this->integrations->panels()) {
            if (! $this->db->table('panel_series')->where('id', $id)->exists()) {
                return null;
            }
            $numbers = $this->db->table('panel_chapters')->where('series_id', $id)->where('state', 'published')
                ->whereNull('removed_at')->where('published_at', '<=', Carbon::now())->orderBy('number')->pluck('number')->all();

            return ['type' => 'chapters', 'tracker' => 'panels', 'scale' => 100, 'chapters' => array_map(Marks::panelsPosition(...), $numbers)];
        }

        return null;
    }

    /**
     * Whether a position is on the ladder. A member's progress may be on any
     * rung; marking a discussion or post at a chapter is Anthology's job, so
     * only "finished" is accepted there.
     *
     * @param array<string, mixed> $ladder
     */
    public static function valid(array $ladder, int $position, bool $progress): bool
    {
        if ($position === Marks::FINISHED) {
            return true;
        }

        return match ($ladder['type']) {
            'steps' => $position >= 1 && $position <= count($ladder['steps']),
            'episodes' => self::episodeExists($ladder['seasons'], $position),
            'chapters' => $progress && in_array($position, $ladder['chapters'], true),
            default => false,
        };
    }

    /**
     * A member who reached a season's last episode has finished the season
     * (so "Season 2" discussions open for them).
     *
     * @param array<string, mixed> $ladder
     */
    public static function normalizeProgress(array $ladder, int $position): int
    {
        if ($ladder['type'] === 'episodes') {
            foreach ($ladder['seasons'] as $season) {
                if ($season['number'] === intdiv($position, Marks::SEASON) && $season['episodes'] === $position % Marks::SEASON) {
                    return $season['number'] * Marks::SEASON + Marks::SEASON_END;
                }
            }
        }

        return $position;
    }

    /** @return list<string> */
    public static function steps(mixed $json): array
    {
        $steps = is_string($json) ? json_decode($json, true) : $json;

        return is_array($steps) ? array_values(array_map('strval', array_filter($steps, 'is_scalar'))) : [];
    }

    /** @param array<int, array{number: int, episodes: int}> $seasons */
    private static function episodeExists(array $seasons, int $position): bool
    {
        $episode = $position % Marks::SEASON;

        foreach ($seasons as $season) {
            if ($season['number'] === intdiv($position, Marks::SEASON)) {
                return $episode === Marks::SEASON_END || ($episode >= 1 && $episode <= $season['episodes']);
            }
        }

        return false;
    }
}
