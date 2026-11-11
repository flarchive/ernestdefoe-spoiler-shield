<?php

namespace ErnestDefoe\SpoilerShield\Api;

use Carbon\Carbon;
use ErnestDefoe\SpoilerShield\Gate;
use Flarum\Api\Client;
use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Flarum\Post\Post;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/spoiler-shield/reveals {type: discussions|posts, id}: "show me
 * this one anyway". Remembered (members: the database; guests: their session),
 * and the item comes back whole, through the API like any other read.
 */
class RevealController implements RequestHandlerInterface
{
    private const GUEST_LIMIT = 300;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Client $api,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $body = Json::body($request);
        $type = $body['type'] ?? null;
        $id = (int) ($body['id'] ?? 0);

        $model = match ($type) {
            'discussions' => Discussion::query()->whereVisibleTo($actor)->find($id),
            'posts' => Post::query()->whereVisibleTo($actor)->find($id),
            default => null,
        };

        if (! $model) {
            return Json::error(404, 'not_found');
        }

        $item = ($type === 'discussions' ? 'd' : 'p');

        if ($actor->isGuest()) {
            $session = $request->getAttribute('session');
            if ($session) {
                $stored = (array) $session->get(Gate::SESSION_KEY, []);
                $stored[] = $item.':'.$id;
                $session->set(Gate::SESSION_KEY, array_slice(array_values(array_unique($stored)), -self::GUEST_LIMIT));
            }
        } else {
            $this->db->table('spoiler_reveals')->insertOrIgnore([
                'user_id' => $actor->id,
                'type' => $item,
                'item_id' => $id,
                'created_at' => Carbon::now(),
            ]);
        }

        $response = $this->api->withParentRequest($request)->get("/$type/$id");

        return new JsonResponse(json_decode((string) $response->getBody(), true), $response->getStatusCode());
    }
}
