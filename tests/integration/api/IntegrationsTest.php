<?php

namespace ErnestDefoe\SpoilerShield\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\Anthology\Chapter;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * The works other extensions bring: a Marquee show (episodes from its data),
 * an Anthology series (progress from what the member opened) and Curio's
 * "finished: unlock everything" offer.
 */
class IntegrationsTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private const EPISODE = 'The dragon burns the harbor city';
    private const SEASON = 'Queen Alys dies in the finale';
    private const CHAPTER = 'Starfall chapter three: the comet hits';
    private const REPLY = 'Chapter three made me gasp at the comet';

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'ernestdefoe-marquee', 'ernestdefoe-anthology', 'ernestdefoe-curio', 'ernestdefoe-spoiler-shield');

        $then = Carbon::now()->subHour();
        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'bea', 'email' => 'bea@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'ash', 'email' => 'ash@machine.local', 'is_email_confirmed' => 1],
            ],
            'marquee_media' => [
                ['id' => 1, 'kind' => 'tv', 'external_id' => '1399', 'title' => 'Dragon Throne', 'year' => 2011, 'fetched_at' => $then,
                    'data' => json_encode(['seasons' => [['number' => 1, 'episodes' => 10], ['number' => 2, 'episodes' => 10]]])],
            ],
            'anthology_series' => [
                ['id' => 1, 'user_id' => 4, 'title' => 'Starfall', 'slug' => 'starfall', 'last_chapter_at' => $then, 'created_at' => $then, 'updated_at' => $then],
            ],
            Discussion::class => [
                ['id' => 20, 'title' => 'Starfall chapter one', 'slug' => 'starfall-1', 'user_id' => 4, 'created_at' => $then, 'last_posted_at' => $then, 'comment_count' => 1, 'first_post_id' => 20, 'last_post_number' => 1],
                ['id' => 21, 'title' => 'Starfall chapter two', 'slug' => 'starfall-2', 'user_id' => 4, 'created_at' => $then, 'last_posted_at' => $then, 'comment_count' => 2, 'first_post_id' => 21, 'last_post_number' => 2],
                ['id' => 22, 'title' => self::CHAPTER, 'slug' => 'starfall-3', 'user_id' => 4, 'created_at' => $then, 'last_posted_at' => $then, 'comment_count' => 1, 'first_post_id' => 22, 'last_post_number' => 1],
            ],
            Post::class => [
                ['id' => 20, 'discussion_id' => 20, 'number' => 1, 'user_id' => 4, 'type' => 'comment', 'created_at' => $then, 'content' => '<t><p>One</p></t>'],
                ['id' => 21, 'discussion_id' => 21, 'number' => 1, 'user_id' => 4, 'type' => 'comment', 'created_at' => $then, 'content' => '<t><p>Two</p></t>'],
                ['id' => 22, 'discussion_id' => 22, 'number' => 1, 'user_id' => 4, 'type' => 'comment', 'created_at' => $then, 'content' => '<t><p>Three</p></t>'],
            ],
            // Bea opened chapter one; Ash read all three.
            'discussion_user' => [
                ['user_id' => 3, 'discussion_id' => 20, 'last_read_post_number' => 1],
                ['user_id' => 4, 'discussion_id' => 20, 'last_read_post_number' => 1],
                ['user_id' => 4, 'discussion_id' => 21, 'last_read_post_number' => 1],
                ['user_id' => 4, 'discussion_id' => 22, 'last_read_post_number' => 1],
            ],
        ]);
    }

    /** @return array{int, mixed, string} */
    private function api(string $method, string $path, ?int $as, array $body = []): array
    {
        $options = $as ? ['authenticatedAs' => $as] : [];
        if ($method !== 'GET') {
            $options['json'] = $body;
        }
        $response = $this->send($this->request($method, $path, $options));
        $raw = (string) $response->getBody();

        return [$response->getStatusCode(), json_decode($raw, true), $raw];
    }

    private function marqueeDiscussion(int $id, string $title, int $season, ?int $episode): Discussion
    {
        $discussion = new Discussion();
        $discussion->id = $id;
        $discussion->title = $title;
        $discussion->user_id = 4;
        $discussion->created_at = $discussion->last_posted_at = Carbon::now()->subHour();
        $discussion->setAttribute('marquee_media_id', 1);
        $discussion->setAttribute('marquee_season', $season);
        $discussion->setAttribute('marquee_episode', $episode);
        $discussion->save();

        return $discussion;
    }

    #[Test]
    public function marquee_episodes_are_the_ladder(): void
    {
        $this->app();
        $episode = $this->marqueeDiscussion(10, self::EPISODE, 2, 5);
        $season = $this->marqueeDiscussion(11, self::SEASON, 2, null);
        $early = $this->marqueeDiscussion(12, 'Pilot thoughts', 1, 2);

        $this->assertSame(['mq:1', 200005, 's2e5'], [$episode->spoiler_work, (int) $episode->spoiler_position, $episode->slug]);
        $this->assertSame(299999, (int) $season->spoiler_position, 'the whole season');

        // Bea finished season 1 (its last episode reaches every season 1 discussion).
        $this->assertSame(422, $this->api('POST', '/api/spoiler-shield/progress', 3, ['work' => 'mq:1', 'position' => 100011])[0]);
        [, $doc] = $this->api('POST', '/api/spoiler-shield/progress', 3, ['work' => 'mq:1', 'position' => 100010]);
        $this->assertSame(199999, $doc['data']['position']);

        $raw = $this->api('GET', '/api/discussions', 3)[2];
        $this->assertStringContainsString('Pilot thoughts', $raw);
        $this->assertStringNotContainsString(self::EPISODE, $raw);
        $this->assertStringNotContainsString(self::SEASON, $raw);
        $this->assertStringContainsString('"label":"S2E5"', $raw);

        // Then the whole of season 2.
        $this->api('POST', '/api/spoiler-shield/progress', 3, ['work' => 'mq:1', 'position' => 200010]);
        $raw = $this->api('GET', '/api/discussions', 3)[2];
        $this->assertStringContainsString(self::EPISODE, $raw);
        $this->assertStringContainsString(self::SEASON, $raw);

        // Relinked to an earlier episode in Marquee: a reply without a stamp keeps S2E5.
        $this->database()->table('posts')->insert(['id' => 90, 'discussion_id' => 10, 'number' => 2, 'user_id' => 4, 'type' => 'comment', 'created_at' => Carbon::now()->subHour(), 'content' => '<t><p>The harbor burns</p></t>']);
        $episode->setAttribute('marquee_episode', 1);
        $episode->save();
        $this->assertSame(['mq:1', 200005], [Post::query()->find(90)->spoiler_work, (int) Post::query()->find(90)->spoiler_position]);
        $episode->setAttribute('marquee_episode', 5);
        $episode->save();

        // Unlinked in Marquee: the mark goes, and the title comes back to the slug.
        $episode->setAttribute('marquee_media_id', null);
        $episode->save();
        $this->assertNull($episode->fresh()->spoiler_work);
        $this->assertSame('the-dragon-burns-the-harbor-city', $episode->fresh()->slug);
        $this->assertNotNull($early->fresh()->spoiler_work);
    }

    #[Test]
    public function an_anthology_series_name_is_only_for_those_who_may_see_anthology(): void
    {
        $this->app();
        foreach ([20 => 1, 21 => 2, 22 => 3] as $discussion => $position) {
            $chapter = new Chapter();
            $chapter->discussion_id = $discussion;
            $chapter->series_id = 1;
            $chapter->position = $position;
            $chapter->created_at = Carbon::now();
            $chapter->save();
        }
        $this->database()->table('group_permission')->where('permission', 'anthology.view')->delete();

        $raw = $this->api('GET', '/api/discussions', 3)[2];
        $this->assertStringNotContainsString('Starfall"', $raw, 'no series name in the marks');
        $this->assertNotContains('Starfall', array_column($this->api('GET', '/api/spoiler-shield/works', 3)[1]['data'], 'title'));
        // The harness parses a URL string strictly ("an:1" reads as a port); set the path itself.
        $work = function (string $key) {
            $request = $this->request('GET', '/api/spoiler-shield/works', ['authenticatedAs' => 3]);

            return $this->send($request->withUri($request->getUri()->withPath('/api/spoiler-shield/works/'.$key)))->getStatusCode();
        };
        $this->assertSame(404, $work('an:1'));
        $this->assertSame(200, $work('mq:1'), 'the same address works for a work she may see');
    }

    #[Test]
    public function the_marquee_backfill_reaches_every_row_past_the_first_page(): void
    {
        $this->app();
        $db = $this->database();
        $now = Carbon::now()->subHour();
        foreach (array_chunk(range(1001, 2200), 300) as $ids) {
            $db->table('discussions')->insert(array_map(fn ($id) => ['id' => $id, 'title' => 'T'.$id, 'slug' => 't', 'user_id' => 4, 'created_at' => $now, 'last_posted_at' => $now, 'comment_count' => 1, 'marquee_media_id' => 1, 'marquee_season' => 1, 'marquee_episode' => 2], $ids));
        }

        (require __DIR__.'/../../../migrations/2026_11_10_200004_mark_existing_marquee_and_anthology_discussions.php')['up']($db->getSchemaBuilder());

        $this->assertSame(1200, $db->table('discussions')->where('spoiler_work', 'mq:1')->where('spoiler_position', 100002)->count());
    }

    #[Test]
    public function long_seasons_keep_their_episodes_in_order(): void
    {
        // A daily show: 1,200 episodes in season one.
        $ladder = ['type' => 'episodes', 'seasons' => [['number' => 1, 'episodes' => 1200], ['number' => 2, 'episodes' => 5]]];
        $late = \ErnestDefoe\SpoilerShield\Marks::marqueePosition(1, 1100);

        $this->assertTrue(\ErnestDefoe\SpoilerShield\Ladders::valid($ladder, $late, true));
        $this->assertLessThan(\ErnestDefoe\SpoilerShield\Marks::marqueePosition(2, 1), $late);
        $this->assertLessThan(\ErnestDefoe\SpoilerShield\Marks::marqueePosition(1, null), $late, 'still inside season one');
        $this->assertSame('s1e1100', \ErnestDefoe\SpoilerShield\Marks::slugFor('mq:1', $late, null));
    }

    #[Test]
    public function anthology_chapters_open_as_you_read(): void
    {
        $this->app();
        foreach ([20 => 1, 21 => 2, 22 => 3] as $discussion => $position) {
            $chapter = new Chapter();
            $chapter->discussion_id = $discussion;
            $chapter->series_id = 1;
            $chapter->position = $position;
            $chapter->created_at = Carbon::now();
            $chapter->save();
        }
        $this->assertSame(['an:1', 3, 'chapter-3'], [Discussion::query()->find(22)->spoiler_work, (int) Discussion::query()->find(22)->spoiler_position, Discussion::query()->find(22)->slug]);

        // Ash read everything: her reply in chapter two is stamped at chapter three.
        [$status] = $this->api('POST', '/api/posts', 4, ['data' => ['type' => 'posts', 'attributes' => ['content' => self::REPLY], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '21']]]]]);
        $this->assertSame(201, $status);
        $reply = Post::query()->where('discussion_id', 21)->orderByDesc('id')->first();
        $this->assertSame(3, (int) $reply->spoiler_position);

        // Bea read chapter one: chapter two is open to her (she has to be able to read it), three isn't.
        $raw = $this->api('GET', '/api/discussions', 3)[2];
        $this->assertStringContainsString('Starfall chapter two', $raw);
        $this->assertStringNotContainsString(self::CHAPTER, $raw);
        $this->assertStringNotContainsString(self::REPLY, $this->api('GET', '/api/posts/'.$reply->id, 3)[2]);
        $this->assertStringNotContainsString(self::CHAPTER, $this->api('GET', '/api/anthology/series/1', 3)[2], "Anthology's chapter list");
        $this->assertStringNotContainsString(self::CHAPTER, $this->api('GET', '/api/discussions/21', 3)[2], 'the next-chapter link');
        $this->assertStringNotContainsString(self::CHAPTER, $this->api('GET', '/api/anthology/series/1', null)[2]);

        // Following a link straight to chapter three (and seeing it blurred) doesn't count as reading it.
        $read = fn (int $discussion) => $this->api('PATCH', '/api/discussions/'.$discussion, 3, ['data' => ['type' => 'discussions', 'id' => (string) $discussion, 'attributes' => ['lastReadPostNumber' => 1]]]);
        $read(22);
        $this->assertSame(0, (int) $this->database()->table('discussion_user')->where(['user_id' => 3, 'discussion_id' => 22])->value('last_read_post_number'));
        $this->assertStringNotContainsString(self::CHAPTER, $this->api('GET', '/api/discussions', 3)[2]);

        // She reads chapter two: chapter three opens, but she hasn't read it, so Ash's reply stamped there stays hidden.
        $read(21);
        $this->assertStringContainsString(self::CHAPTER, $this->api('GET', '/api/discussions', 3)[2]);
        $this->assertStringNotContainsString(self::REPLY, $this->api('GET', '/api/posts/'.$reply->id, 3)[2]);

        // Then she reads it.
        $read(22);
        $this->assertStringContainsString(self::REPLY, $this->api('GET', '/api/posts/'.$reply->id, 3)[2]);

        // Guests start at chapter one like anyone who hasn't read any: it's open, the rest aren't.
        $guest = $this->api('GET', '/api/discussions', null)[2];
        $this->assertStringContainsString('Starfall chapter one', $guest);
        $this->assertStringNotContainsString(self::CHAPTER, $guest);

        // And "show everything" for members who never said applies to a series they haven't started.
        $this->app()->getContainer()->make(\Flarum\Settings\SettingsRepositoryInterface::class)->set('ernestdefoe-spoiler-shield.members', 'show');
        $this->assertStringContainsString(self::CHAPTER, $this->api('GET', '/api/discussions', 2)[2]);

        // A chapter taken out of the series keeps its replies' places; a deleted series takes its stamps with it.
        Chapter::query()->find(21)->delete();
        $this->assertSame('an:1', $reply->fresh()->spoiler_work);
        \Ernestdefoe\Anthology\Series::query()->find(1)->delete();
        $this->assertNull($reply->fresh()->spoiler_work);
    }

    #[Test]
    public function a_finished_title_in_curio_offers_to_unlock_everything(): void
    {
        $this->app();
        $this->marqueeDiscussion(10, self::EPISODE, 2, 5);
        $db = $this->database();
        $now = Carbon::now();
        $workId = $db->table('curio_works')->insertGetId(['source' => 'marquee-tv', 'source_id' => '1399', 'title' => 'Dragon Throne', 'refreshed_at' => $now, 'created_at' => $now]);
        $collection = $db->table('curio_collections')->insertGetId(['user_id' => 3, 'type_id' => $db->table('curio_types')->value('id'), 'name' => 'Shows', 'slug' => 'shows', 'created_at' => $now, 'updated_at' => $now]);
        $item = ['collection_id' => $collection, 'user_id' => 3, 'work_id' => $workId, 'title' => 'Dragon Throne', 'status' => 'wishlist', 'created_at' => $now, 'updated_at' => $now];

        $find = fn () => collect($this->api('GET', '/api/discussions', 3)[1]['data'])->firstWhere('id', '10')['attributes']['spoilerShield'];

        $db->table('curio_items')->insert($item + ['progress' => 'in_progress']);
        $this->assertFalse($find()['offer'], 'wishlist, still watching');

        $db->table('curio_items')->update(['progress' => 'completed']);
        $shield = $find();
        $this->assertTrue($shield['hidden']);
        $this->assertTrue($shield['offer']);

        $this->api('POST', '/api/spoiler-shield/progress', 3, ['work' => 'mq:1', 'position' => 'finished']);
        $this->assertStringContainsString(self::EPISODE, $this->api('GET', '/api/discussions', 3)[2]);
    }

    #[Test]
    public function the_discussion_list_costs_the_same_queries_for_3_or_30_gated_discussions(): void
    {
        $this->app();
        $count = function () {
            $db = $this->database();
            $db->flushQueryLog();
            $db->enableQueryLog();
            $this->api('GET', '/api/discussions', 3);
            $db->disableQueryLog();


            return count($db->getQueryLog());
        };

        for ($i = 0; $i < 3; $i++) {
            $this->marqueeDiscussion(100 + $i, 'Episode talk '.$i, 2, $i + 1);
        }
        $count(); // the first request of a session also records the visit and loads permissions
        $few = $count();

        for ($i = 3; $i < 30; $i++) {
            $this->marqueeDiscussion(100 + $i, 'Episode talk '.$i, 2, $i % 10 + 1);
        }
        $many = $count();

        $this->assertSame($few, $many, "$few queries for 3, $many for 30");
    }
}
