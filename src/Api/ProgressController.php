<?php

namespace ErnestDefoe\SpoilerShield\Api;

use Carbon\Carbon;
use ErnestDefoe\SpoilerShield\Labels;
use ErnestDefoe\SpoilerShield\Ladders;
use ErnestDefoe\SpoilerShield\Marks;
use ErnestDefoe\SpoilerShield\Works;
use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET  /api/spoiler-shield/progress: where I'm up to, per work.
 * POST /api/spoiler-shield/progress {work, position}: "I'm up to S2E3"
 *      (a position on the work's ladder, "finished", or null to forget it).
 */
class ProgressController implements RequestHandlerInterface
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Ladders $ladders,
        private readonly Labels $labels,
        private readonly Works $works,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        if ($request->getMethod() === 'GET') {
            $rows = $this->db->table('spoiler_progress')->where('user_id', $actor->id)->orderByDesc('updated_at')->limit(200)->get(['work', 'position']);
            $works = $this->works->describe($rows->pluck('work')->all(), $actor);

            return new JsonResponse(['data' => $rows->filter(fn ($row) => isset($works[$row->work]))->map(fn ($row) => [
                'work' => $row->work,
                'title' => $works[$row->work]['title'],
                'kind' => $works[$row->work]['kind'],
                'position' => (int) $row->position,
                'label' => $this->labels->for($row->work, (int) $row->position, $works[$row->work]['steps'][(int) $row->position - 1] ?? null),
            ])->values()->all()]);
        }

        $body = Json::body($request);
        $work = $body['work'] ?? null;
        $ladder = Marks::parse($work) ? $this->ladders->get($work) : null;

        if (! $ladder) {
            return Json::error(422, 'invalid_work');
        }

        $position = $body['position'] ?? null;

        if ($position === null) {
            $this->db->table('spoiler_progress')->where('user_id', $actor->id)->where('work', $work)->delete();

            return new EmptyResponse(204);
        }

        $position = $position === 'finished' ? Marks::FINISHED : (int) $position;

        if (! Ladders::valid($ladder, $position, true)) {
            return Json::error(422, 'invalid_position');
        }

        $position = Ladders::normalizeProgress($ladder, $position);

        $this->db->table('spoiler_progress')->upsert(
            [['user_id' => $actor->id, 'work' => $work, 'position' => $position, 'updated_at' => Carbon::now()]],
            ['user_id', 'work'],
            ['position', 'updated_at']
        );

        return new JsonResponse(['data' => [
            'work' => $work,
            'position' => $position,
            'label' => $this->labels->for($work, $position, $ladder['steps'][$position - 1] ?? null),
        ]]);
    }
}
