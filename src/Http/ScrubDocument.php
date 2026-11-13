<?php

namespace ErnestDefoe\SpoilerShield\Http;

use ErnestDefoe\SpoilerShield\Gate;
use ErnestDefoe\SpoilerShield\Labels;
use ErnestDefoe\SpoilerShield\MarkStore;
use ErnestDefoe\SpoilerShield\Works;
use Flarum\Frontend\Document;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The forum page's own HTML, after every other extension wrote into it (this
 * runs last). The API already gated the title, the preloaded data and the
 * no-JS content; SEO and link-preview extensions read the database directly.
 *
 * Their descriptions are built from text we can't see into, so on a page
 * that could carry a spoiler they go, whole: a hidden discussion, a marked
 * discussion with a reply hidden from this viewer (fof/seo describes replies
 * and best answers too), and any page linking to a hidden discussion. Never
 * by matching words, which would let anyone test a guess. Links that name a
 * hidden discussion say "Spoiler for …" instead (Anthology's series pages
 * list chapters that way).
 */
class ScrubDocument
{
    public function __construct(
        private readonly MarkStore $store,
        private readonly Gate $gate,
        private readonly Works $works,
        private readonly Labels $labels,
        private readonly ConnectionInterface $db,
    ) {
    }

    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        $actor = RequestUtil::getActor($request);
        $session = $request->getAttribute('session');

        $current = $request->getAttribute('routeName') === 'discussion'
            ? (int) ($request->getAttribute('routeParameters')['id'] ?? 0) : 0;

        if ($document->content instanceof Renderable) {
            $document->content = $document->content->render();
        }

        $html = implode("\n", array_merge([(string) $document->content], $document->head, $document->meta));
        preg_match_all('#/d/(\d+)#', $html, $m);
        $ids = array_map('intval', $m[1]);
        if ($current) {
            $ids[] = $current;
        }
        if (! $ids) {
            return;
        }

        $discussions = $this->store->discussions($ids);
        $verdict = $this->gate->check($actor, $session, $discussions, []);

        $currentHidden = $current && $verdict->discussionHidden($current);
        $replyHidden = $current && ! $currentHidden && ($discussions[$current]['work'] ?? null) !== null
            && $this->replyHidden($actor, $session, $current, $discussions[$current]);
        $linksHidden = array_diff_key($verdict->hiddenDiscussions, [$current => true]) !== [];

        if (! $currentHidden && ! $replyHidden && ! $linksHidden) {
            return;
        }

        $works = $this->works->describe(array_values(array_unique(array_filter(array_column($discussions, 'work')))), $actor);
        $placeholder = function (int $id) use ($discussions, $works): string {
            $d = $discussions[$id];

            return $this->labels->placeholder($this->labels->for((string) $d['work'], (int) $d['position'], $d['label']), $works[$d['work']]['title'] ?? '');
        };

        if ($currentHidden) {
            $document->title = $placeholder($current);
        }

        $document->meta = array_filter(
            $document->meta,
            fn ($key) => ! preg_match('/^(description|keywords|og:|twitter:|article:)/i', (string) $key),
            ARRAY_FILTER_USE_KEY
        );
        $document->head = array_values(array_filter(
            $document->head,
            fn ($tag) => ! preg_match('/(property|name)="(description|keywords|og:|twitter:|article:)|application\/ld\+json/i', (string) $tag)
        ));

        $rewrite = fn (string $markup) => (string) preg_replace_callback(
            '#(<a\b[^>]*\bhref="[^"]*/d/(\d+)[^"]*"[^>]*>)(.*?)(</a>)#is',
            fn ($m) => $verdict->discussionHidden((int) $m[2]) ? $m[1].e($placeholder((int) $m[2])).$m[4] : $m[0],
            $markup
        );

        $document->content = $rewrite((string) $document->content);
        $document->head = array_map(fn ($tag) => $rewrite((string) $tag), $document->head);
    }

    /**
     * Whether some reply in this marked discussion is hidden from the viewer:
     * the furthest stamp by someone else in each work decides (places in
     * different works don't compare; reveals aside, so this errs on hiding).
     * One query.
     *
     * @param array{work: ?string, position: ?int, label: ?string, user: ?int} $discussion
     */
    private function replyHidden(User $actor, ?Session $session, int $id, array $discussion): bool
    {
        $g = $this->db->getQueryGrammar();
        $furthest = $this->db->table('posts')->where('discussion_id', $id)->whereNotNull('spoiler_position')
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '!=', (int) $actor->id))
            ->groupBy('spoiler_work')
            ->selectRaw($g->wrap('spoiler_work').' as work, max('.$g->wrap('spoiler_position').') as furthest')
            ->get();

        if ($furthest->isEmpty()) {
            return false;
        }

        $posts = [];
        foreach ($furthest as $i => $row) {
            $posts[$i] = ['discussion' => $id, 'position' => (int) $row->furthest, 'user' => null, 'work' => $row->work];
        }

        return $this->gate->check($actor, $session, [$id => $discussion], $posts)->hiddenPosts !== [];
    }
}
