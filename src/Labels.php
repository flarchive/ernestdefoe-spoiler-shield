<?php

namespace ErnestDefoe\SpoilerShield;

use Symfony\Contracts\Translation\TranslatorInterface;

/** A position in words. Works defined here store their own step names; the rest are made from the number. */
final class Labels
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function for(string $work, int $position, ?string $stored = null): string
    {
        if ($position === Marks::FINISHED) {
            return $this->translator->trans('ernestdefoe-spoiler-shield.lib.finished');
        }

        $source = Marks::parse($work)[0] ?? 'w';

        if ($source === 'mq') {
            $season = intdiv($position, Marks::SEASON);
            $episode = $position % Marks::SEASON;

            return $episode === Marks::SEASON_END
                ? $this->translator->trans('ernestdefoe-spoiler-shield.lib.season', ['number' => $season])
                : 'S'.$season.'E'.$episode;
        }

        if ($source === 'an' || $source === 'pn') {
            return $this->translator->trans('ernestdefoe-spoiler-shield.lib.chapter', ['number' => $source === 'pn' ? Marks::panelsNumber($position) : $position]);
        }

        return $stored !== null && $stored !== '' ? $stored : '#'.$position;
    }

    /** "Spoiler for S2E5 (Breaking Bad)", or without the name when the viewer may not see it. */
    public function placeholder(?string $label, string $work): string
    {
        $label ??= $this->translator->trans('ernestdefoe-spoiler-shield.lib.later');

        return $work === ''
            ? $this->translator->trans('ernestdefoe-spoiler-shield.lib.placeholder_unnamed', ['label' => $label])
            : $this->translator->trans('ernestdefoe-spoiler-shield.lib.placeholder', ['label' => $label, 'work' => $work]);
    }
}
