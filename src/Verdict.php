<?php

namespace ErnestDefoe\SpoilerShield;

/** What the gate decided for one viewer. Hidden and revealed never overlap. */
final class Verdict
{
    /** @var array<int, true> */
    public array $hiddenDiscussions = [];

    /** @var array<int, true> */
    public array $hiddenPosts = [];

    /** @var array<int, true> Ahead of the viewer, but they chose to see it. */
    public array $revealedDiscussions = [];

    /** @var array<int, true> */
    public array $revealedPosts = [];

    /** @var array<string, array{read: int, frontier: int, set?: bool}> */
    public array $positions = [];

    public function discussionHidden(int $id): bool
    {
        return isset($this->hiddenDiscussions[$id]);
    }

    public function postHidden(int $id): bool
    {
        return isset($this->hiddenPosts[$id]);
    }

    public function anythingHidden(): bool
    {
        return $this->hiddenDiscussions || $this->hiddenPosts;
    }
}
