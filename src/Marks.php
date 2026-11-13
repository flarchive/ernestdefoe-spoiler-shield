<?php

namespace ErnestDefoe\SpoilerShield;

/**
 * What a mark means. A work is a short key: "w:12" (defined here), "mq:345"
 * (a Marquee title), "an:7" (an Anthology series) or "pn:3" (a Panels series).
 * A position is a number
 * on that work's ladder, compared with `<=`:
 *
 *  - here: the step's place in the work's list, 1-based;
 *  - Marquee TV: season × 100000 + episode, and season × 100000 + 99999 for "the
 *    whole season" (so finishing a season reaches every episode of it);
 *  - Anthology: the chapter's position in its series;
 *  - Panels: the chapter's number × 100 (chapter 6.5 is 650), so an extra slotted
 *    in later, or a chapter renumbered, never moves anyone else's marks.
 *
 * FINISHED is past everything: a member who finished the work, or a mark that
 * spoils the whole of it (a film, a game).
 */
final class Marks
{
    public const FINISHED = 2147483647;

    public const SEASON = 100000;

    public const SEASON_END = 99999;

    /** @return array{0: string, 1: int}|null */
    public static function parse(mixed $key): ?array
    {
        if (! is_string($key) || ! preg_match('/^(w|mq|an|pn):([1-9]\d{0,9})$/', $key, $m)) {
            return null;
        }

        return [$m[1], (int) $m[2]];
    }

    public static function key(string $source, int $id): string
    {
        return $source.':'.$id;
    }

    public static function marqueePosition(?int $season, ?int $episode): ?int
    {
        if (! $season) {
            return null;
        }

        return $season * self::SEASON + ($episode ?: self::SEASON_END);
    }

    /** A Panels chapter number ("6.5", from a decimal column) as a position: 650. */
    public static function panelsPosition(mixed $number): int
    {
        return (int) round((float) $number * 100);
    }

    /** A Panels position in the chapter's own words: 650 is "6.5", 1200 is "12". */
    public static function panelsNumber(int $position): string
    {
        $cents = $position % 100;

        return intdiv($position, 100).($cents ? '.'.rtrim(sprintf('%02d', $cents), '0') : '');
    }

    /**
     * A positioned discussion's slug, the same in every language: "s2e5",
     * "season-2", "chapter-7", or the step's name for a work defined here.
     */
    public static function slugFor(string $work, int $position, ?string $stored): string
    {
        $source = self::parse($work)[0] ?? 'w';

        return match (true) {
            $position === self::FINISHED => 'finished',
            $source === 'mq' && $position % self::SEASON === self::SEASON_END => 'season-'.intdiv($position, self::SEASON),
            $source === 'mq' => 's'.intdiv($position, self::SEASON).'e'.($position % self::SEASON),
            $source === 'an' => 'chapter-'.$position,
            $source === 'pn' => 'chapter-'.str_replace('.', '-', self::panelsNumber($position)),
            default => self::slug((string) $stored),
        };
    }

    /**
     * The slug a positioned discussion gets, so its title never reaches a URL
     * (sitemaps, links, emails): "s2e5", "chapter-7".
     */
    public static function slug(string $label): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($label)), '-') ?: 'spoiler';
    }
}
