<?php

namespace ErnestDefoe\SpoilerShield;

use Flarum\User\User;
use Illuminate\Contracts\Session\Session;

/**
 * Takes the spoilers out of a JSON:API document before it leaves the server.
 *
 * Every discussion and post in it (primary data and included) is checked with
 * the Gate in one go. A hidden one keeps what the page needs to draw it (ids,
 * counts, dates, permissions, its author and tags) and loses everything else:
 * the title becomes "Spoiler for S2E5 (Breaking Bad)", the post body a
 * placeholder, and any other text, list or relationship that isn't on the
 * allow-list (another extension's excerpt, a poll) goes too, along with
 * included records only that relationship pointed at. Links in other posts
 * that spell out a hidden discussion's title are rewritten.
 *
 * Every marked item gets a `spoilerShield` attribute the forum draws from.
 */
final class Redactor
{
    /** What a hidden discussion keeps besides numbers, booleans and permissions (can*). */
    private const DISCUSSION_KEEP = ['createdAt', 'lastPostedAt', 'lastReadAt', 'hiddenAt', 'subscription', 'frontdate'];

    private const DISCUSSION_RELATIONS = [
        'user', 'lastPostedUser', 'firstPost', 'lastPost', 'posts', 'mostRelevantPost', 'tags', 'hiddenUser',
        'marqueeMedia', 'recipientUsers', 'recipientGroups', 'bestAnswerPost', 'bestAnswerUser', 'state',
    ];

    private const POST_KEEP = ['createdAt', 'editedAt', 'hiddenAt', 'contentType'];

    private const POST_RELATIONS = [
        'discussion', 'user', 'editedUser', 'hiddenUser', 'likes', 'mentionedBy', 'mentionsPosts', 'mentionsUsers', 'flags',
    ];

    public function __construct(
        private readonly MarkStore $store,
        private readonly Integrations $integrations,
        private readonly Gate $gate,
        private readonly Works $works,
        private readonly Labels $labels,
    ) {
    }

    /**
     * @param array<string, mixed> $doc
     * @param bool $search the words of a search chose these: hidden discussions are left out
     * @return array<string, mixed>
     */
    public function redact(array $doc, User $actor, ?Session $session, bool $search = false): array
    {
        $single = isset($doc['data']['type']);
        $data = $single ? [$doc['data']] : (is_array($doc['data'] ?? null) ? $doc['data'] : []);
        $included = is_array($doc['included'] ?? null) ? $doc['included'] : [];

        [$discussions, $posts] = $this->marks(array_merge($data, $included));

        $verdict = $this->gate->check($actor, $session, $discussions, $posts);

        $workKeys = array_values(array_unique(array_filter(array_merge(array_column($discussions, 'work'), array_column($posts, 'work')))));
        $works = $workKeys ? $this->works->describe($workKeys, $actor) : [];
        $offers = $verdict->anythingHidden() ? $this->works->inCollection($actor, $works) : [];

        $context = compact('discussions', 'posts', 'verdict', 'works', 'offers');

        foreach ($data as $i => $resource) {
            $data[$i] = $this->resource($resource, $context);
        }
        foreach ($included as $i => $resource) {
            $included[$i] = $this->resource($resource, $context);
        }

        // A search must not confirm a guess: a hidden discussion that matched the words is left out.
        if ($search && ! $single && $verdict->hiddenDiscussions) {
            $data = array_values(array_filter($data, fn ($r) => ($r['type'] ?? '') !== 'discussions' || ! $verdict->discussionHidden((int) $r['id'])));
        }

        if ($verdict->anythingHidden() && $included) {
            $included = $this->reachable($data, $included);
        }

        $doc['data'] = $single ? $data[0] : $data;
        if (isset($doc['included'])) {
            $doc['included'] = $included;
        }

        return $doc;
    }

    /**
     * Anthology's series page: its chapter list names each chapter (a
     * discussion) by title.
     *
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    public function redactChapters(array $doc, User $actor, ?Session $session): array
    {
        $chapters = $doc['data']['chapters'] ?? null;
        if (! is_array($chapters) || ! $chapters) {
            return $doc;
        }

        $discussions = $this->store->discussions(array_map(fn ($c) => (int) ($c['id'] ?? 0), $chapters));
        $verdict = $this->gate->check($actor, $session, $discussions, []);

        if (! $verdict->hiddenDiscussions) {
            return $doc;
        }

        $works = $this->works->describe(array_values(array_unique(array_filter(array_column($discussions, 'work')))), $actor);

        foreach ($chapters as $i => $chapter) {
            $id = (int) ($chapter['id'] ?? 0);
            if ($verdict->discussionHidden($id)) {
                $d = $discussions[$id];
                $label = $this->labels->for((string) $d['work'], (int) $d['position'], $d['label']);
                $chapters[$i]['title'] = $this->placeholder(['label' => $label, 'title' => $works[$d['work']]['title'] ?? '']);
                $chapters[$i]['slug'] = Marks::slugFor((string) $d['work'], (int) $d['position'], $d['label']);
                $chapters[$i]['spoilerShield'] = ['hidden' => true, 'label' => $label];
            }
        }
        $doc['data']['chapters'] = $chapters;

        return $doc;
    }

    /** Words that may sit beside a discussion's link in another extension's JSON (a widget's list). */
    private const LINK_TEXT_KEYS = ['excerpt', 'summary', 'description', 'body', 'content', 'contentHtml', 'text', 'preview', 'snippet', 'firstPost'];

    /**
     * Any JSON, from any extension: an object that links to a discussion
     * (`url`/`href`/`link` to /d/{id}) and names it (`title`/`name`) is a
     * reference to that discussion. Hidden ones get the placeholder for a
     * name, their position for a slug, and lose excerpt-like text. JSON:API
     * discussions and posts themselves are the other method's job.
     *
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    public function redactLinks(array $doc, User $actor, ?Session $session): array
    {
        $ids = [];
        $collect = function ($node, $key = null) use (&$collect, &$ids) {
            if (! is_array($node)) {
                return;
            }
            if (($id = $this->linkedId($node, $key)) !== null) {
                $ids[$id] = true;
            }
            foreach ($node as $k => $value) {
                $collect($value, is_int($k) ? $key : $k);
            }
        };
        $collect($doc);

        if (! $ids) {
            return $doc;
        }

        $discussions = $this->store->discussions(array_keys($ids));
        $verdict = $this->gate->check($actor, $session, $discussions, []);

        if (! $verdict->hiddenDiscussions) {
            return $doc;
        }

        $works = $this->works->describe(array_values(array_unique(array_filter(array_column($discussions, 'work')))), $actor);
        $replace = function ($node, $parent = null) use (&$replace, $verdict, $discussions, $works) {
            if (! is_array($node)) {
                return $node;
            }
            $id = $this->linkedId($node, $parent);
            if ($id !== null && $verdict->discussionHidden($id)) {
                $d = $discussions[$id];
                $label = $this->labels->for((string) $d['work'], (int) $d['position'], $d['label']);
                $slug = Marks::slugFor((string) $d['work'], (int) $d['position'], $d['label']);
                foreach (['title', 'name', 'discussionTitle'] as $key) {
                    if (isset($node[$key]) && is_string($node[$key])) {
                        $node[$key] = $this->placeholder(['label' => $label, 'title' => $works[$d['work']]['title'] ?? '']);
                    }
                }
                foreach (['url', 'href', 'link', 'permalink'] as $key) {
                    if (isset($node[$key]) && is_string($node[$key])) {
                        $node[$key] = (string) preg_replace('#/d/'.$id.'(-[^/?\#"]*)?#', '/d/'.$id.'-'.$slug, $node[$key]);
                    }
                }
                if (isset($node['slug']) && is_string($node['slug'])) {
                    $node['slug'] = $id.'-'.$slug;
                }
                foreach (self::LINK_TEXT_KEYS as $key) {
                    if (array_key_exists($key, $node)) {
                        $node[$key] = null;
                    }
                }
                $node['spoilerShield'] = ['hidden' => true, 'label' => $label];
            }
            foreach ($node as $key => $value) {
                if (is_array($value)) {
                    $node[$key] = $replace($value, is_int($key) ? $parent : $key);
                }
            }

            return $node;
        };

        $doc = $replace($doc);

        // A listed discussion whose own posts link to a hidden one: its excerpt may quote that
        // link's words. Decided by the links, never by matching words (that would test a guess).
        $visible = array_diff_key($ids, $verdict->hiddenDiscussions);
        $quoting = [];
        foreach ($this->store->linksFromEnds(array_keys($visible)) as $id => $linked) {
            if (array_intersect_key(array_flip($linked), $verdict->hiddenDiscussions)) {
                $quoting[$id] = true;
            }
        }
        if ($quoting) {
            $blank = function ($node) use (&$blank, $quoting) {
                if (! is_array($node)) {
                    return $node;
                }
                $id = $this->linkedId($node);
                if ($id !== null && isset($quoting[$id])) {
                    foreach (self::LINK_TEXT_KEYS as $key) {
                        if (array_key_exists($key, $node)) {
                            $node[$key] = null;
                        }
                    }
                }

                return array_map($blank, $node);
            };
            $doc = $blank($doc);
        }

        return $doc;
    }

    /**
     * The discussion an object in some JSON links to and names, or null.
     *
     * @param array<mixed> $node
     */
    private function linkedId(array $node, int|string|null $parent = null): ?int
    {
        if (in_array($node['type'] ?? null, ['discussions', 'posts'], true)) {
            return null;
        }

        // { "discussionId": 12, "discussionTitle": "…" }
        if (isset($node['discussionId'], $node['discussionTitle']) && is_numeric($node['discussionId'])) {
            return (int) $node['discussionId'];
        }

        if (! isset($node['title']) && ! isset($node['name'])) {
            return null;
        }

        // { "discussion": { "id": 12, "title": "…" } } (Presence's "viewing" line)
        if (in_array($parent, ['discussion', 'discussions'], true) && isset($node['id']) && is_numeric($node['id'])) {
            return (int) $node['id'];
        }

        foreach (['url', 'href', 'link', 'permalink'] as $key) {
            if (isset($node[$key]) && is_string($node[$key]) && preg_match('#/d/(\d+)(?:[-/?\#]|$)#', $node[$key], $m)) {
                return (int) $m[1];
            }
        }

        return null;
    }

    /**
     * Everything marked, as if no viewer could see any of it: the fallback
     * when something goes wrong, so a failure never shows a spoiler.
     *
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    public function redactAll(array $doc): array
    {
        // The same allow-list as a hidden item: numbers, booleans, permissions and dates stay.
        $blank = function (array $resource): array {
            unset($resource['attributes']['ssMark']);
            $type = $resource['type'] ?? '';
            if ($type === 'discussions') {
                $resource['attributes'] = ['title' => '', 'slug' => (string) ($resource['id'] ?? '')] + $this->strip($resource['attributes'] ?? [], self::DISCUSSION_KEEP);
            }
            if ($type === 'posts') {
                $before = $resource['attributes'] ?? [];
                $resource['attributes'] = $this->strip($before, self::POST_KEEP);
                $resource['attributes']['content'] = is_array($before['content'] ?? null) ? array_map(fn ($v) => is_string($v) ? '' : $v, $before['content']) : null;
                $resource['attributes']['contentHtml'] = '';
            }

            return $resource;
        };

        if (isset($doc['data']['type'])) {
            $doc['data'] = $blank($doc['data']);
        } elseif (is_array($doc['data'] ?? null)) {
            $doc['data'] = array_map($blank, $doc['data']);
        }
        if (is_array($doc['included'] ?? null)) {
            $doc['included'] = array_map($blank, $doc['included']);
        }
        // Other extensions' lists of discussions: no names.
        $links = function ($node, $parent = null) use (&$links) {
            if (! is_array($node)) {
                return $node;
            }
            if ($this->linkedId($node, $parent) !== null) {
                foreach (['title', 'name', 'discussionTitle', ...self::LINK_TEXT_KEYS] as $key) {
                    if (isset($node[$key])) {
                        $node[$key] = '';
                    }
                }
            }
            foreach ($node as $key => $value) {
                $node[$key] = $links($value, is_int($key) ? $parent : $key);
            }

            return $node;
        };
        $doc = $links($doc);

        if (is_array($doc['data']['chapters'] ?? null)) {
            $doc['data']['chapters'] = array_map(fn ($c) => ['title' => '', 'slug' => ''] + (array) $c, $doc['data']['chapters']);
        }

        return $doc;
    }

    /**
     * The marks of every discussion and post in the document, and of the
     * discussions its posts belong to or link to. Read from the `ssMark`
     * attribute (free: the columns are on the row) and from the database for
     * whatever the document doesn't carry (a sparse fieldset, a post whose
     * discussion isn't included): one query each for discussions and posts.
     *
     * @param array<int, array<string, mixed>> $resources
     * @return array{0: array<int, array{work: ?string, position: ?int, label: ?string, user: ?int, first?: ?int, last?: ?int}>, 1: array<int, array{discussion: int, position: ?int, label: ?string, user: ?int, work: ?string}>}
     */
    private function marks(array $resources): array
    {
        $discussions = $posts = [];
        $needD = $needP = [];

        foreach ($resources as $resource) {
            $id = (int) ($resource['id'] ?? 0);
            $mark = $resource['attributes']['ssMark'] ?? null;

            if (($resource['type'] ?? '') === 'discussions') {
                if (is_array($mark)) {
                    $discussions[$id] = ['work' => $mark[0], 'position' => $mark[1], 'label' => $mark[2], 'user' => $mark[3], 'first' => $mark[4] ?? null, 'last' => $mark[5] ?? null];
                } else {
                    $needD[$id] = true;
                }
                foreach ($this->linkedDiscussions($resource['attributes'] ?? []) as $linked) {
                    $needD[$linked] = true;
                }
            } elseif (($resource['type'] ?? '') === 'posts') {
                if (is_array($mark)) {
                    $posts[$id] = ['discussion' => $mark[3], 'position' => $mark[0], 'label' => $mark[1], 'user' => $mark[2], 'work' => $mark[4] ?? null];
                    $needD[$mark[3]] = true;
                } else {
                    $needP[$id] = true;
                }
                foreach ($this->linkedDiscussions($resource['attributes'] ?? []) as $linked) {
                    $needD[$linked] = true;
                }
            }
        }

        if ($needP) {
            foreach ($this->store->posts(array_keys($needP)) as $id => $mark) {
                $posts[$id] = $mark;
                $needD[$mark['discussion']] = true;
            }
        }

        $needD = array_diff_key($needD, $discussions);
        $discussions += $this->store->discussions(array_keys($needD));

        // A marked discussion's first and last posts, wherever they are: other extensions put
        // their words on the discussion itself (a theme's "last reply" excerpt).
        $ends = [];
        foreach ($discussions as $d) {
            if ($d['work'] !== null) {
                foreach ([$d['first'] ?? null, $d['last'] ?? null] as $postId) {
                    if ($postId && ! isset($posts[$postId])) {
                        $ends[] = $postId;
                    }
                }
            }
        }
        $posts += $this->store->posts($ends);

        // A work whose extension is switched off counts as unmarked.
        foreach ($discussions as $id => $d) {
            if ($d['work'] !== null && ! $this->integrations->live($d['work'])) {
                $discussions[$id]['work'] = null;
            }
        }
        foreach ($posts as $id => $p) {
            // Its place was on that work's ladder: it can't fall back to the discussion's work.
            if (($p['work'] ?? null) !== null && ! $this->integrations->live($p['work'])) {
                $posts[$id]['work'] = null;
                $posts[$id]['position'] = null;
                $posts[$id]['label'] = null;
            }
        }

        // A post found nowhere (deleted under us) is treated like its discussion.
        foreach ($needP as $id => $_) {
            $posts[$id] ??= ['discussion' => 0, 'position' => null, 'label' => null, 'user' => null, 'work' => null];
        }

        return [$discussions, $posts];
    }

    /**
     * Discussions a resource's text points at: links to /d/{id} in a post, and
     * Anthology's previous/next chapter on a discussion.
     *
     * @param array<string, mixed> $attributes
     * @return int[]
     */
    private function linkedDiscussions(array $attributes): array
    {
        $ids = [];

        if (is_string($attributes['contentHtml'] ?? null) && str_contains($attributes['contentHtml'], '/d/')) {
            preg_match_all('#<a\b[^>]*\bhref="[^"]*/d/(\d+)[^"]*"#i', $attributes['contentHtml'], $m);
            $ids = array_map('intval', $m[1]);
        }

        foreach (['prev', 'next'] as $side) {
            if (isset($attributes['anthologySeries'][$side]['id'])) {
                $ids[] = (int) $attributes['anthologySeries'][$side]['id'];
            }
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $resource
     * @param array<string, mixed> $c
     * @return array<string, mixed>
     */
    private function resource(array $resource, array $c): array
    {
        $type = $resource['type'] ?? '';
        $id = (int) ($resource['id'] ?? 0);
        unset($resource['attributes']['ssMark']);

        // Always present, null when unmarked: the forum merges attributes, so a revealed item
        // would otherwise keep its old mark.
        if ($type === 'discussions' || $type === 'posts') {
            $resource['attributes']['spoilerShield'] = null;
        }

        if ($type === 'discussions') {
            $mark = $c['discussions'][$id] ?? null;
            $hidden = $c['verdict']->discussionHidden($id);

            if ($mark && $mark['work'] !== null) {
                $resource['attributes']['spoilerShield'] = $this->describe($mark['work'], $mark['position'], $mark['label'], $hidden, isset($c['verdict']->revealedDiscussions[$id]), $c);
            }

            if ($hidden) {
                $resource = $this->hideDiscussion($resource, $resource['attributes']['spoilerShield']);
            } else {
                // Readable, but its first or last post isn't: other extensions' excerpts of them go.
                if ($mark && ($c['verdict']->postHidden((int) ($mark['first'] ?? 0)) || $c['verdict']->postHidden((int) ($mark['last'] ?? 0)))) {
                    $resource['attributes'] = $this->strip($resource['attributes'] ?? [], [...self::DISCUSSION_KEEP, 'title', 'slug', 'spoilerShield']);
                }
                $resource['attributes'] = $this->unlinkHidden($resource['attributes'] ?? [], $c);
            }
        } elseif ($type === 'posts') {
            $mark = $c['posts'][$id] ?? null;
            $discussion = $mark ? ($c['discussions'][$mark['discussion']] ?? null) : null;
            $hidden = $c['verdict']->postHidden($id);

            // A stamped post carries its own work (a quote can bring another work's spoiler in).
            $postWork = $mark['work'] ?? null;
            $postWork ??= $discussion['work'] ?? null;
            if ($mark && $postWork !== null && ($mark['position'] !== null || $hidden)) {
                // A post hidden with its discussion shows the discussion's mark.
                $own = $mark['position'] !== null && ! $c['verdict']->discussionHidden($mark['discussion']);
                $resource['attributes']['spoilerShield'] = $this->describe(
                    $own ? $postWork : (string) $discussion['work'],
                    $own ? $mark['position'] : $discussion['position'],
                    $own ? $mark['label'] : $discussion['label'],
                    $hidden,
                    isset($c['verdict']->revealedPosts[$id]),
                    $c
                );
            }

            if ($hidden) {
                $resource = $this->hidePost($resource, $resource['attributes']['spoilerShield'] ?? null);
            } else {
                $resource['attributes'] = $this->unlinkHidden($resource['attributes'] ?? [], $c);
            }
        }

        return $resource;
    }

    /**
     * @param array<string, mixed> $c
     * @return array<string, mixed>
     */
    private function describe(string $work, ?int $position, ?string $stored, bool $hidden, bool $revealed, array $c): array
    {
        $place = $c['verdict']->positions[$work] ?? null;
        // A series nobody started reading has no place to show yet.
        $viewer = $place && ($place['set'] ?? true) ? $place['read'] : null;

        return [
            'work' => $work,
            'title' => $c['works'][$work]['title'] ?? '',
            'kind' => $c['works'][$work]['kind'] ?? 'other',
            'position' => $position,
            'label' => $position === null ? null : $this->labels->for($work, $position, $stored),
            'hidden' => $hidden,
            'revealed' => $revealed,
            'viewer' => $viewer === null ? null : ['position' => $viewer, 'label' => $this->labels->for($work, $viewer, $c['works'][$work]['steps'][$viewer - 1] ?? null)],
            'offer' => $hidden && isset($c['offers'][$work]),
        ];
    }

    /** "Spoiler for S2E5 (Breaking Bad)" */
    private function placeholder(?array $shield): string
    {
        return $this->labels->placeholder($shield['label'] ?? null, (string) ($shield['title'] ?? ''));
    }

    /**
     * @param array<string, mixed> $resource
     * @param array<string, mixed> $shield
     * @return array<string, mixed>
     */
    private function hideDiscussion(array $resource, array $shield): array
    {
        $attributes = $this->strip($resource['attributes'] ?? [], self::DISCUSSION_KEEP);
        $attributes['title'] = $this->placeholder($shield);
        // Renaming starts from the title shown, the placeholder: it would replace the real one. Reveal first.
        if (array_key_exists('canRename', $attributes)) {
            $attributes['canRename'] = false;
        }
        // Core's slug is "id-words"; the words become the position.
        $attributes['slug'] = $resource['id'].'-'.Marks::slugFor($shield['work'], (int) $shield['position'], $shield['label']);
        $attributes['spoilerShield'] = $shield;
        $resource['attributes'] = $attributes;
        $resource['relationships'] = array_intersect_key($resource['relationships'] ?? [], array_flip(self::DISCUSSION_RELATIONS));

        return $resource;
    }

    /**
     * @param array<string, mixed> $resource
     * @param array<string, mixed>|null $shield
     * @return array<string, mixed>
     */
    private function hidePost(array $resource, ?array $shield): array
    {
        $before = $resource['attributes'] ?? [];
        $attributes = $this->strip($before, self::POST_KEEP);
        $text = $this->placeholder($shield);

        if (is_array($before['content'] ?? null)) {
            // Event posts (a rename's old and new title) keep their shape, or the page can't draw them.
            $attributes['content'] = array_map(fn ($v) => is_string($v) ? $text : $v, $before['content']);
        } else {
            $attributes['content'] = null;
        }
        if (array_key_exists('contentHtml', $before)) {
            $attributes['contentHtml'] = '<p class="SpoilerShield-placeholder">'.e($text).'</p>';
        }
        if ($shield) {
            $attributes['spoilerShield'] = $shield;
        }
        // Editing starts from the words shown, none: saving would wipe the post. Reveal first.
        if (array_key_exists('canEdit', $attributes)) {
            $attributes['canEdit'] = false;
        }

        $resource['attributes'] = $attributes;
        $resource['relationships'] = array_intersect_key($resource['relationships'] ?? [], array_flip(self::POST_RELATIONS));

        return $resource;
    }

    /**
     * Numbers, booleans, permissions and the named fields stay; any other text
     * or structure (whatever another extension added) goes.
     *
     * @param array<string, mixed> $attributes
     * @param string[] $keep
     * @return array<string, mixed>
     */
    private function strip(array $attributes, array $keep): array
    {
        foreach ($attributes as $key => $value) {
            if ($value === null || is_bool($value) || is_int($value) || is_float($value)
                || in_array($key, $keep, true) || preg_match('/^can[A-Z]/', $key)) {
                continue;
            }
            $attributes[$key] = null;
        }

        return $attributes;
    }

    /**
     * In something the viewer may read, links that spell out a hidden
     * discussion's title say "Spoiler for …" instead.
     *
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $c
     * @return array<string, mixed>
     */
    private function unlinkHidden(array $attributes, array $c): array
    {
        $verdict = $c['verdict'];

        if (! $verdict->hiddenDiscussions) {
            return $attributes;
        }

        $label = function (int $id) use ($c): string {
            $d = $c['discussions'][$id];

            return $this->placeholder([
                'label' => $d['position'] === null ? null : $this->labels->for((string) $d['work'], $d['position'], $d['label']),
                'title' => $c['works'][$d['work']]['title'] ?? '',
            ]);
        };

        if (is_string($attributes['contentHtml'] ?? null) && str_contains($attributes['contentHtml'], '/d/')) {
            $attributes['contentHtml'] = (string) preg_replace_callback(
                '#(<a\b[^>]*\bhref="[^"]*/d/(\d+)[^"]*"[^>]*>)(.*?)(</a>)#is',
                function ($m) use ($verdict, $label) {
                    if (! $verdict->discussionHidden((int) $m[2])) {
                        return $m[0];
                    }
                    // A bare URL as the link text names nothing; anything else might.
                    $text = preg_match('#^\s*https?://\S+\s*$#', strip_tags($m[3])) ? $m[3] : e($label((int) $m[2]));

                    return $m[1].$text.$m[4];
                },
                $attributes['contentHtml']
            );
        }

        foreach (['prev', 'next'] as $side) {
            $id = (int) ($attributes['anthologySeries'][$side]['id'] ?? 0);
            if ($id && $verdict->discussionHidden($id)) {
                $attributes['anthologySeries'][$side]['title'] = $label($id);
                $attributes['anthologySeries'][$side]['slug'] = (string) $id;
            }
        }

        return $attributes;
    }

    /**
     * Included records still reachable from the primary data, once hidden
     * items lost their relationships.
     *
     * @param array<int, array<string, mixed>> $data
     * @param array<int, array<string, mixed>> $included
     * @return array<int, array<string, mixed>>
     */
    private function reachable(array $data, array $included): array
    {
        $byKey = [];
        foreach ($included as $resource) {
            $byKey[($resource['type'] ?? '').':'.($resource['id'] ?? '')] = $resource;
        }

        $seen = [];
        $queue = $data;
        while ($resource = array_shift($queue)) {
            foreach ($resource['relationships'] ?? [] as $relationship) {
                $linkage = $relationship['data'] ?? null;
                if (! is_array($linkage)) {
                    continue;
                }
                foreach (isset($linkage['type']) ? [$linkage] : $linkage as $ref) {
                    $key = ($ref['type'] ?? '').':'.($ref['id'] ?? '');
                    if (isset($byKey[$key]) && ! isset($seen[$key])) {
                        $seen[$key] = true;
                        $queue[] = $byKey[$key];
                    }
                }
            }
        }

        return array_values(array_filter($included, fn ($r) => isset($seen[($r['type'] ?? '').':'.($r['id'] ?? '')])));
    }
}
