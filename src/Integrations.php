<?php

namespace ErnestDefoe\SpoilerShield;

use Flarum\Extension\ExtensionManager;

/** Which of the optional extensions are here and switched on. */
final class Integrations
{
    public function __construct(private readonly ExtensionManager $extensions)
    {
    }

    public function marquee(): bool
    {
        return class_exists(\Ernestdefoe\Marquee\Media::class) && $this->extensions->isEnabled('ernestdefoe-marquee');
    }

    public function anthology(): bool
    {
        return class_exists(\Ernestdefoe\Anthology\Chapter::class) && $this->extensions->isEnabled('ernestdefoe-anthology');
    }

    public function curio(): bool
    {
        return class_exists(\Ernestdefoe\Curio\Item::class) && $this->extensions->isEnabled('ernestdefoe-curio');
    }

    public function tags(): bool
    {
        return class_exists(\Flarum\Tags\Tag::class) && $this->extensions->isEnabled('flarum-tags');
    }

    /**
     * Whether a work's source is here: a Marquee or Anthology work whose
     * extension was switched off counts as unmarked (nothing to set a place
     * in, so nothing may stay blurred for good).
     */
    public function live(?string $work): bool
    {
        return match (true) {
            $work === null => false,
            str_starts_with($work, 'mq:') => $this->marquee(),
            str_starts_with($work, 'an:') => $this->anthology(),
            default => true,
        };
    }
}
