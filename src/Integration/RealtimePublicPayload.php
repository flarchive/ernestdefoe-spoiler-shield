<?php

namespace ErnestDefoe\SpoilerShield\Integration;

use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Realtime\Push\Payload\Generator;

/**
 * flarum/realtime pushes each change twice: to every connected member, built
 * for them (gated like any API read), and on the public channel, built as a
 * guest. Members receive both, so the guest's veiled copy would overwrite
 * what they may read. On the public channel a marked discussion or post goes
 * out as identifiers and relationships only: no text to show or to overwrite,
 * and the client fetches what it needs as itself.
 */
class RealtimePublicPayload extends Generator
{
    public function __invoke(\Flarum\Database\AbstractModel $subject, ?\Flarum\User\User $recipient = null, ?array $includes = null): ?array
    {
        $payload = parent::__invoke($subject, $recipient, $includes);

        if ($recipient !== null || ! $payload) {
            return $payload;
        }

        // Anything from a discussion with a work: the guest's copy (even with guests on "show")
        // isn't what every member may read, so none of its words go out here.
        $discussion = $subject instanceof Discussion ? $subject : ($subject instanceof Post ? $subject->discussion : null);
        $inWork = ($discussion?->spoiler_work !== null) || ($subject instanceof Post && $subject->spoiler_work !== null);

        $strip = function (array $resource) use ($inWork): array {
            if (in_array($resource['type'] ?? null, ['discussions', 'posts'], true)
                && ($inWork || ($resource['attributes']['spoilerShield'] ?? null) !== null)) {
                unset($resource['attributes']);
            }

            return $resource;
        };

        if (isset($payload['data']['type'])) {
            $payload['data'] = $strip($payload['data']);
        }
        if (is_array($payload['included'] ?? null)) {
            $payload['included'] = array_map($strip, $payload['included']);
        }

        return $payload;
    }
}
