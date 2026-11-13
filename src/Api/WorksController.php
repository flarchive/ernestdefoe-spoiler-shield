<?php

namespace ErnestDefoe\SpoilerShield\Api;

use ErnestDefoe\SpoilerShield\Integrations;
use ErnestDefoe\SpoilerShield\Ladders;
use ErnestDefoe\SpoilerShield\Labels;
use ErnestDefoe\SpoilerShield\MarkWriter;
use ErnestDefoe\SpoilerShield\Marks;
use ErnestDefoe\SpoilerShield\Positions;
use ErnestDefoe\SpoilerShield\Work;
use ErnestDefoe\SpoilerShield\Works;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET    /api/spoiler-shield/works?q=          works to pick from (here, Marquee, Anthology)
 * GET    /api/spoiler-shield/works/{key}       one, with its ladder and where I'm up to
 * POST   /api/spoiler-shield/works             define a work here (members, by default)
 * PATCH  /api/spoiler-shield/works/{id}        its owner, or a moderator
 * DELETE /api/spoiler-shield/works/{id}        the same; its marks go with it
 */
class WorksController implements RequestHandlerInterface
{
    public const KINDS = ['book', 'show', 'movie', 'game', 'other'];

    private const MAX_STEPS = 500;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Works $works,
        private readonly Ladders $ladders,
        private readonly Labels $labels,
        private readonly Positions $positions,
        private readonly Integrations $integrations,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $key = Json::param($request, 'key');

        return match ($request->getMethod()) {
            'GET' => $key === '' ? $this->search($request, $actor) : $this->show($actor, $key),
            'POST' => $this->save($actor, null, Json::body($request)),
            'PATCH' => $this->save($actor, Work::query()->find((int) $key), Json::body($request)),
            'DELETE' => $this->delete($actor, Work::query()->find((int) $key)),
            default => Json::error(405, 'method_not_allowed'),
        };
    }

    private function search(ServerRequestInterface $request, User $actor): ResponseInterface
    {
        $q = Json::line($request->getQueryParams()['q'] ?? '', 100);
        // whereLike: case-insensitive on every database (plain LIKE is case-sensitive on PostgreSQL).
        $like = '%'.addcslashes($q, '%_\\').'%';
        $out = [];

        foreach ($this->db->table('spoiler_works')->when($q !== '', fn ($w) => $w->whereLike('title', $like))->orderBy('title')->limit(20)->get(['id', 'title', 'kind', 'user_id']) as $row) {
            $out[] = ['work' => 'w:'.$row->id, 'title' => $row->title, 'kind' => $row->kind, 'canEdit' => $this->canEdit($actor, new Work(['user_id' => $row->user_id]))];
        }

        if ($this->integrations->marquee()) {
            foreach ($this->db->table('marquee_media')->whereIn('kind', ['tv', 'movie', 'game'])->when($q !== '', fn ($w) => $w->whereLike('title', $like))->orderBy('title')->limit(10)->get(['id', 'title', 'kind', 'year']) as $row) {
                $out[] = ['work' => 'mq:'.$row->id, 'title' => $row->title.($row->year ? ' ('.$row->year.')' : ''), 'kind' => $row->kind === 'tv' ? 'show' : $row->kind];
            }
        }

        if ($this->integrations->anthology() && $actor->hasPermission('anthology.view')) {
            foreach ($this->db->table('anthology_series')->when($q !== '', fn ($w) => $w->whereLike('title', $like))->orderBy('title')->limit(10)->get(['id', 'title']) as $row) {
                $out[] = ['work' => 'an:'.$row->id, 'title' => $row->title, 'kind' => 'book'];
            }
        }

        if ($this->integrations->panels() && $actor->hasPermission('panels.view')) {
            // Panels' own scope: only series this member may browse (hidden, taken down and rating-limited ones stay out).
            $series = \ErnestDefoe\Panels\Model\Series::query()->select(['panel_series.id', 'panel_series.title']);
            resolve(\ErnestDefoe\Panels\Access::class)->scopeSeries($series, $actor);
            foreach ($series->when($q !== '', fn ($w) => $w->whereLike('panel_series.title', $like))->orderBy('panel_series.title')->limit(10)->toBase()->get() as $row) {
                $out[] = ['work' => 'pn:'.$row->id, 'title' => $row->title, 'kind' => 'book'];
            }
        }

        return new JsonResponse(['data' => $out]);
    }

    private function show(User $actor, string $key): ResponseInterface
    {
        $ladder = Marks::parse($key) ? $this->ladders->get($key) : null;
        $work = $ladder ? ($this->works->describe([$key], $actor)[$key] ?? null) : null;

        if (! $work) {
            return Json::error(404, 'not_found');
        }

        $read = $this->positions->read($actor, $key);
        $row = str_starts_with($key, 'w:') ? Work::query()->find((int) substr($key, 2)) : null;

        return new JsonResponse(['data' => [
            'work' => $key,
            'title' => $work['title'],
            'kind' => $work['kind'],
            'catalog' => $row ? $work['catalog'] : null,
            'ladder' => $ladder,
            'viewer' => $read === null ? null : ['position' => $read, 'label' => $this->labels->for($key, $read, $ladder['steps'][$read - 1] ?? null)],
            'offer' => isset($this->works->inCollection($actor, [$key => $work])[$key]),
            'canEdit' => $row !== null && $this->canEdit($actor, $row),
            'tagIds' => $row ? $this->db->table('spoiler_work_tags')->where('work_id', $row->id)->pluck('tag_id')->map(fn ($id) => (int) $id)->all() : [],
        ]]);
    }

    /** @param array<string, mixed> $body */
    private function save(User $actor, ?Work $work, array $body): ResponseInterface
    {
        if ($work ? ! $this->canEdit($actor, $work) : ! $actor->hasPermission('spoiler-shield.createWorks')) {
            return Json::error(403, 'permission_denied');
        }

        $title = Json::line($body['title'] ?? '', 150);
        $kind = in_array($body['kind'] ?? null, self::KINDS, true) ? $body['kind'] : 'other';
        $steps = array_values(array_filter(array_map(fn ($s) => Json::line($s, 64), (array) ($body['steps'] ?? [])), fn ($s) => $s !== ''));
        $catalog = Json::line($body['catalog'] ?? '', 120);

        if (mb_strlen($title) < 2) {
            return Json::error(422, 'invalid_title');
        }
        if (! $steps || count($steps) > self::MAX_STEPS) {
            return Json::error(422, 'invalid_steps');
        }
        if ($catalog !== '' && ! preg_match('/^[a-z][a-z0-9-]{1,29}:[A-Za-z0-9._-]{1,80}$/', $catalog)) {
            return Json::error(422, 'invalid_catalog');
        }
        // Positions are places in the list: a step already in use can't disappear or move
        // (renaming one is fine; so is adding to the end).
        if ($work) {
            $inUse = $this->highestInUse($work);
            $before = Ladders::steps($work->steps);
            if (count($steps) < $inUse) {
                return Json::error(422, 'steps_in_use');
            }
            for ($i = 0; $i < $inUse; $i++) {
                $moved = array_search($steps[$i], $before, true);
                if ($steps[$i] !== ($before[$i] ?? null) && $moved !== false && $moved !== $i) {
                    return Json::error(422, 'steps_reordered');
                }
            }
        }

        $before = $work ? Ladders::steps($work->steps) : [];
        $work ??= new Work(['user_id' => $actor->id]);
        $work->title = $title;
        $work->kind = $kind;
        $work->steps = json_encode($steps, JSON_UNESCAPED_UNICODE);
        $work->external = $catalog === '' ? null : $catalog;
        $work->save();

        // Marks already at a renamed step take its new name.
        foreach ($steps as $i => $label) {
            if (isset($before[$i]) && $before[$i] !== $label) {
                $this->db->table('discussions')->where('spoiler_work', 'w:'.$work->id)->where('spoiler_position', $i + 1)->update(['spoiler_label' => $label]);
                $this->db->table('posts')->where('spoiler_work', 'w:'.$work->id)->where('spoiler_position', $i + 1)->update(['spoiler_label' => $label]);
            }
        }

        if (array_key_exists('tagIds', $body) && $actor->hasPermission('spoiler-shield.manageWorks') && $this->integrations->tags()) {
            $tagIds = array_values(array_unique(array_filter(array_map('intval', (array) $body['tagIds']))));
            $tagIds = $tagIds ? $this->db->table('tags')->whereIn('id', $tagIds)->pluck('id')->map(fn ($id) => (int) $id)->all() : [];
            $this->db->table('spoiler_work_tags')->where('work_id', $work->id)->delete();
            $this->db->table('spoiler_work_tags')->whereIn('tag_id', $tagIds ?: [0])->delete();
            $this->db->table('spoiler_work_tags')->insert(array_map(fn ($id) => ['tag_id' => $id, 'work_id' => $work->id], $tagIds));
        }

        return $this->show($actor, 'w:'.$work->id);
    }

    private function delete(User $actor, ?Work $work): ResponseInterface
    {
        if (! $work) {
            return Json::error(404, 'not_found');
        }
        if (! $this->canEdit($actor, $work)) {
            return Json::error(403, 'permission_denied');
        }

        $key = 'w:'.$work->id;

        // A work in use carries everyone's marks and places: only a moderator may take it away.
        if (! $actor->hasPermission('spoiler-shield.manageWorks') && $this->inUse($key)) {
            return Json::error(403, 'work_in_use');
        }

        // In bulk: its replies take their discussion's place first (they stay blurred, revealable
        // one by one, rather than open to everyone at once), then the discussions are unmarked.
        resolve(MarkWriter::class)->clearWork($key);
        $this->db->table('spoiler_progress')->where('work', $key)->delete();
        $work->delete();

        return new EmptyResponse(204);
    }

    private function canEdit(User $actor, Work $work): bool
    {
        return $actor->hasPermission('spoiler-shield.manageWorks')
            || ($actor->exists && (int) $work->user_id === (int) $actor->id && $actor->hasPermission('spoiler-shield.createWorks'));
    }

    private function inUse(string $key): bool
    {
        return $this->db->table('discussions')->where('spoiler_work', $key)->exists()
            || $this->db->table('posts')->where('spoiler_work', $key)->exists()
            || $this->db->table('spoiler_progress')->where('work', $key)->exists();
    }

    private function highestInUse(Work $work): int
    {
        $key = 'w:'.$work->id;
        $below = fn ($q) => $q->where('spoiler_position', '<', Marks::FINISHED);

        return (int) max(
            $this->db->table('discussions')->where('spoiler_work', $key)->where($below)->max('spoiler_position'),
            $this->db->table('posts')->where('spoiler_work', $key)->where($below)->max('spoiler_position'),
            $this->db->table('spoiler_progress')->where('work', $key)->where('position', '<', Marks::FINISHED)->max('position'),
        );
    }
}
