<?php

namespace ErnestDefoe\SpoilerShield\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\SpoilerShield\Marks;
use Flarum\Discussion\Discussion;
use Flarum\Extend;
use Flarum\Frontend\Document;
use Flarum\Locale\LocaleManager;
use Flarum\Notification\NotificationMailer;
use Flarum\Notification\NotificationSyncer;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The gate on its own: a work defined on the forum, members at different
 * places in it. The proof is in the raw bytes: the hidden words appear in no
 * response a reader who is behind can get.
 */
class GateTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private const TITLE = 'Kael betrays the guild';
    private const BODY = 'Mira was the masked archer all along';
    private const REPLY = 'The ending in the final chapter made me cry';
    private const RENAMED = 'Who is the masked archer';

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-mentions', 'ernestdefoe-spoiler-shield');

        // An SEO extension describing a discussion's page from the database, as fof/seo and og-image do.
        $this->extend((new Extend\Frontend('forum'))->content(function (Document $document, ServerRequestInterface $request) {
            if ($request->getAttribute('routeName') !== 'discussion') {
                return;
            }
            $d = Discussion::query()->find((int) $request->getAttribute('routeParameters')['id']);
            $document->head[] = '<meta property="og:description" content="'.e($d->title).' '.e($d->firstPost?->content).'">';
            $document->head[] = '<script type="application/ld+json">{"headline":"'.e($d->title).'"}</script>';
            $document->meta['description'] = 'About '.$d->title;
        }));

        $now = Carbon::now()->subHour();
        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'bea', 'email' => 'bea@machine.local', 'is_email_confirmed' => 1, 'preferences' => json_encode(['notify_postMentioned_email' => true])],
                ['id' => 4, 'username' => 'ash', 'email' => 'ash@machine.local', 'is_email_confirmed' => 1, 'preferences' => json_encode(['notify_postMentioned_email' => true])],
                ['id' => 5, 'username' => 'una', 'email' => 'una@machine.local', 'is_email_confirmed' => 1],
            ],
            'spoiler_works' => [
                ['id' => 1, 'title' => 'The Long Road', 'kind' => 'book', 'steps' => json_encode(['Ch 1', 'Ch 2', 'Ch 3', 'Ch 4', 'Ch 5']), 'created_at' => $now, 'updated_at' => $now],
            ],
            Discussion::class => [
                ['id' => 1, 'title' => self::TITLE, 'slug' => 'ch-4', 'user_id' => 4, 'created_at' => $now, 'last_posted_at' => $now, 'comment_count' => 1, 'first_post_id' => 1, 'last_post_id' => 1, 'last_post_number' => 1, 'spoiler_work' => 'w:1', 'spoiler_position' => 4, 'spoiler_label' => 'Ch 4'],
                ['id' => 2, 'title' => 'Opening chapters chat', 'slug' => 'ch-1', 'user_id' => 4, 'created_at' => $now, 'last_posted_at' => $now, 'comment_count' => 3, 'first_post_id' => 2, 'last_post_id' => 4, 'last_post_number' => 3, 'spoiler_work' => 'w:1', 'spoiler_position' => 1, 'spoiler_label' => 'Ch 1'],
                ['id' => 3, 'title' => 'Book club general', 'slug' => 'book-club-general', 'user_id' => 2, 'created_at' => $now, 'last_posted_at' => $now, 'comment_count' => 1, 'first_post_id' => 5, 'last_post_id' => 5, 'last_post_number' => 1],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'user_id' => 4, 'type' => 'comment', 'created_at' => $now, 'content' => '<t><p>'.self::BODY.'</p></t>'],
                ['id' => 2, 'discussion_id' => 2, 'number' => 1, 'user_id' => 4, 'type' => 'comment', 'created_at' => $now, 'content' => '<t><p>Who else loved the first chapter?</p></t>'],
                ['id' => 3, 'discussion_id' => 2, 'number' => 2, 'user_id' => 4, 'type' => 'comment', 'created_at' => $now, 'content' => '<t><p>'.self::REPLY.'</p></t>', 'spoiler_position' => 5, 'spoiler_label' => 'Ch 5', 'spoiler_work' => 'w:1'],
                ['id' => 4, 'discussion_id' => 2, 'number' => 3, 'user_id' => 4, 'type' => 'discussionRenamed', 'created_at' => $now, 'content' => json_encode(['Old', self::RENAMED]), 'spoiler_position' => 5, 'spoiler_label' => 'Ch 5', 'spoiler_work' => 'w:1'],
                ['id' => 5, 'discussion_id' => 3, 'number' => 1, 'user_id' => 2, 'type' => 'comment', 'created_at' => $now, 'content' => '<r><p>See <URL url="http://localhost/d/1-ch-4">'.self::TITLE.'</URL></p></r>'],
            ],
            'spoiler_progress' => [
                ['user_id' => 3, 'work' => 'w:1', 'position' => 2, 'updated_at' => $now],
                ['user_id' => 4, 'work' => 'w:1', 'position' => Marks::FINISHED, 'updated_at' => $now],
            ],
        ]);
    }

    /** @return array{int, mixed, string} */
    private function api(string $method, string $path, ?int $as, array $body = [], array $query = []): array
    {
        $options = $as ? ['authenticatedAs' => $as] : [];
        if ($method !== 'GET') {
            $options['json'] = $body;
        }
        $request = $this->request($method, $path, $options);
        if ($query) {
            $request = $request->withQueryParams($query);
        }
        $response = $this->send($request);
        $raw = (string) $response->getBody();

        return [$response->getStatusCode(), json_decode($raw, true), $raw];
    }

    /** Every place a discussion or post can be read, as raw bytes. */
    private function everything(?int $as): string
    {
        $out = '';
        foreach ([
            ['/api/discussions', []],
            ['/api/discussions', ['filter' => ['q' => 'Kael masked archer']]],
            ['/api/discussions/1', []],
            ['/api/discussions/2', []],
            ['/api/discussions/3', []],
            ['/api/posts', ['filter' => ['discussion' => '2']]],
            ['/api/posts/1', []],
            ['/api/posts/3', []],
            ['/api/posts/4', []],
            ['/api/users/4', []],
            ['/api/notifications', []],
            ['/', []],
            ['/d/1', []],
            ['/d/2', []],
            ['/d/3', []],
        ] as [$path, $query]) {
            if ($path === '/api/notifications' && ! $as) {
                continue;
            }
            [$status, , $raw] = $this->api('GET', $path, $as, [], $query);
            $out .= "\n--- $path ($status)\n".$raw;
        }

        return $out;
    }

    #[Test]
    public function hidden_words_never_reach_a_reader_who_is_behind(): void
    {
        // An alert for the reader who is behind, about the spoiling reply.
        $this->app();
        $this->database()->table('notifications')->insert([
            'user_id' => 3, 'from_user_id' => 4, 'type' => 'postMentioned', 'subject_id' => 3,
            'data' => json_encode(['replyNumber' => 2]), 'created_at' => Carbon::now(),
        ]);

        // Bea is at chapter 2; Una never said (members are blurred by default); and a guest.
        foreach ([3, 5, null] as $as) {
            $all = $this->everything($as);

            foreach ([self::TITLE, self::BODY, self::REPLY, self::RENAMED] as $secret) {
                foreach (explode("\n--- ", $all) as $page) {
                    $where = strtok($page, "\n");
                    $this->assertStringNotContainsString($secret, $page, $secret.' leaked to '.($as ?? 'a guest').' on '.$where);
                    $this->assertStringNotContainsString(htmlspecialchars($secret, ENT_QUOTES), $page, $secret.' leaked on '.$where);
                }
            }
            $this->assertStringContainsString('"label":"Ch 4"', $all);
            $this->assertStringNotContainsString('ssMark', $all);
        }

        // Ash finished the book: everything is there for her.
        $all = $this->everything(4);
        foreach ([self::TITLE, self::BODY, self::REPLY, self::RENAMED] as $secret) {
            $this->assertStringContainsString($secret, $all);
        }
    }

    #[Test]
    public function the_api_says_what_is_hidden_and_keeps_the_shape(): void
    {
        [, $doc] = $this->api('GET', '/api/discussions/2', 3);

        $shield = $doc['data']['attributes']['spoilerShield'];
        $this->assertSame('w:1', $shield['work']);
        $this->assertSame('The Long Road', $shield['title']);
        $this->assertFalse($shield['hidden'], 'Bea is past chapter 1');
        $this->assertSame(['position' => 2, 'label' => 'Ch 2'], $shield['viewer']);

        $posts = collect($this->api('GET', '/api/posts', 3, [], ['filter' => ['discussion' => '2']])[1]['data'])->keyBy('id');
        $this->assertTrue($posts['3']['attributes']['spoilerShield']['hidden']);
        $this->assertSame('Ch 5', $posts['3']['attributes']['spoilerShield']['label']);
        $this->assertStringContainsString('SpoilerShield-placeholder', $posts['3']['attributes']['contentHtml']);
        // An event post keeps the shape the page draws it from.
        $this->assertCount(2, $posts['4']['attributes']['content']);
        $this->assertNull($posts['2']['attributes']['spoilerShield'], 'nothing past the discussion (null, so a client drops an old mark)');
        $this->assertSame('1-ch-4', $this->api('GET', '/api/discussions/1', 3)[1]['data']['attributes']['slug']);
    }

    #[Test]
    public function a_hidden_item_cannot_be_edited_or_renamed_until_revealed(): void
    {
        // The admin is behind too (no place set): editing would start from nothing.
        $this->assertFalse($this->api('GET', '/api/posts/3', 1)[1]['data']['attributes']['canEdit']);
        $this->assertFalse($this->api('GET', '/api/discussions/1', 1)[1]['data']['attributes']['canRename']);

        $this->api('POST', '/api/spoiler-shield/reveals', 1, ['type' => 'posts', 'id' => 3]);
        $this->assertTrue($this->api('GET', '/api/posts/3', 1)[1]['data']['attributes']['canEdit']);
    }

    #[Test]
    public function when_the_check_fails_every_discussion_and_post_loses_its_words(): void
    {
        $redactor = $this->app()->getContainer()->make(\ErnestDefoe\SpoilerShield\Redactor::class);
        $doc = $redactor->redactAll(['data' => [
            ['type' => 'discussions', 'id' => '1', 'attributes' => ['title' => self::TITLE, 'commentCount' => 3, 'themeExcerpt' => self::BODY, 'ssMark' => ['w:1', 4, null, 4]]],
        ], 'included' => [
            ['type' => 'posts', 'id' => '3', 'attributes' => ['contentHtml' => '<p>'.self::REPLY.'</p>', 'content' => self::REPLY, 'createdAt' => '2026-11-10', 'extensionSummary' => self::REPLY]],
        ]]);

        $raw = json_encode($doc);
        foreach ([self::TITLE, self::BODY, self::REPLY, 'ssMark'] as $gone) {
            $this->assertStringNotContainsString($gone, $raw);
        }
        $this->assertSame(3, $doc['data'][0]['attributes']['commentCount']);
        $this->assertSame('2026-11-10', $doc['included'][0]['attributes']['createdAt']);
    }

    #[Test]
    public function gdpr_exports_and_erases_a_members_places_and_reveals(): void
    {
        $this->api('POST', '/api/spoiler-shield/reveals', 3, ['type' => 'discussions', 'id' => 1]);
        $data = $this->app()->getContainer()->make(\ErnestDefoe\SpoilerShield\Gdpr\SpoilerData::class, ['user' => User::query()->find(3), 'erasureRequest' => null]);

        $export = json_encode($data->export());
        $this->assertStringContainsString('w:1', $export);
        $this->assertStringContainsString('item_id', $export);

        $data->delete();
        $this->assertSame(0, $this->database()->table('spoiler_progress')->where('user_id', 3)->count());
        $this->assertSame(0, $this->database()->table('spoiler_reveals')->where('user_id', 3)->count());
    }

    #[Test]
    public function the_posts_backfill_reaches_every_row_past_the_first_page(): void
    {
        $this->app();
        $db = $this->database();
        $now = Carbon::now()->subHour();
        foreach (array_chunk(range(1001, 2200), 300) as $ids) {
            $db->table('posts')->insert(array_map(fn ($id) => ['id' => $id, 'discussion_id' => 2, 'number' => $id, 'user_id' => 4, 'type' => 'comment', 'created_at' => $now, 'content' => '<t><p>x</p></t>', 'spoiler_position' => 5], $ids));
        }

        (require __DIR__.'/../../../migrations/2026_11_10_200005_add_spoiler_work_to_posts.php')['up']($db->getSchemaBuilder());

        $this->assertSame(0, $db->table('posts')->whereNotNull('spoiler_position')->whereNull('spoiler_work')->count());
    }

    #[Test]
    public function deleting_a_work_clears_every_discussion_past_the_first_page(): void
    {
        $this->app();
        $db = $this->database();
        $now = Carbon::now()->subHour();
        foreach (array_chunk(range(1001, 2200), 300) as $ids) {
            $db->table('discussions')->insert(array_map(fn ($id) => ['id' => $id, 'title' => 'T'.$id, 'slug' => 'ch-2', 'user_id' => 4, 'created_at' => $now, 'last_posted_at' => $now, 'comment_count' => 1, 'spoiler_work' => 'w:1', 'spoiler_position' => 2, 'spoiler_label' => 'Ch 2'], $ids));
        }

        $this->assertSame(204, $this->api('DELETE', '/api/spoiler-shield/works/1', 1)[0]);
        $this->assertSame(0, $db->table('discussions')->where('spoiler_work', 'w:1')->count());
    }

    #[Test]
    public function the_author_always_sees_their_own(): void
    {
        $this->app();
        $this->database()->table('spoiler_progress')->where('user_id', 4)->delete();

        [, , $raw] = $this->api('GET', '/api/discussions/1', 4);
        $this->assertStringContainsString(self::TITLE, $raw);
        $this->assertStringContainsString(self::BODY, $raw);
    }

    #[Test]
    public function revealing_one_item_shows_that_one_and_is_remembered(): void
    {
        [$status, , $raw] = $this->api('POST', '/api/spoiler-shield/reveals', 3, ['type' => 'discussions', 'id' => 1]);
        $this->assertSame(200, $status);
        $this->assertStringContainsString(self::TITLE, $raw);
        $this->assertStringContainsString(self::BODY, $raw);

        [, , $raw] = $this->api('GET', '/api/discussions', 3);
        $this->assertStringContainsString(self::TITLE, $raw, 'remembered');

        // The reply further on in another discussion is still hidden.
        $this->assertStringNotContainsString(self::REPLY, $this->api('GET', '/api/posts/3', 3)[2]);

        // Una can't reveal what she can't see at all.
        $this->assertSame(404, $this->api('POST', '/api/spoiler-shield/reveals', 5, ['type' => 'posts', 'id' => 999])[0]);
    }

    #[Test]
    public function reaching_the_position_opens_it(): void
    {
        $this->assertSame(422, $this->api('POST', '/api/spoiler-shield/progress', 3, ['work' => 'w:1', 'position' => 6])[0]);
        [$status, $doc] = $this->api('POST', '/api/spoiler-shield/progress', 3, ['work' => 'w:1', 'position' => 4]);
        $this->assertSame(200, $status);
        $this->assertSame('Ch 4', $doc['data']['label']);

        $raw = $this->api('GET', '/api/discussions/1', 3)[2];
        $this->assertStringContainsString(self::TITLE, $raw);
        $this->assertStringNotContainsString(self::REPLY, $this->api('GET', '/api/posts/3', 3)[2], 'chapter 5 still ahead');

        $this->api('POST', '/api/spoiler-shield/progress', 3, ['work' => 'w:1', 'position' => 'finished']);
        $this->assertStringContainsString(self::REPLY, $this->api('GET', '/api/posts/3', 3)[2]);
    }

    #[Test]
    public function admins_can_show_everything_to_members_who_never_said(): void
    {
        $this->setting('ernestdefoe-spoiler-shield.members', 'show');

        $this->assertStringContainsString(self::TITLE, $this->api('GET', '/api/discussions', 5)[2]);
        $this->assertStringNotContainsString(self::TITLE, $this->api('GET', '/api/discussions', null)[2], 'guests are still blurred');
        $this->assertStringNotContainsString(self::TITLE, $this->api('GET', '/api/discussions', 3)[2], 'members who said are gated');
    }

    #[Test]
    public function replies_are_stamped_with_their_authors_position(): void
    {
        [$status] = $this->api('POST', '/api/posts', 4, ['data' => ['type' => 'posts', 'attributes' => ['content' => 'What a twist at the very end'], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '2']]]]]);
        $this->assertSame(201, $status);

        $post = Post::query()->where('discussion_id', 2)->orderByDesc('id')->first();
        $this->assertSame(Marks::FINISHED, (int) $post->spoiler_position);
        $this->assertStringNotContainsString('very end', $this->api('GET', '/api/posts/'.$post->id, 3)[2]);

        // Choosing "nothing past the discussion" in the composer (an hour later: flood control).
        $post->created_at = Carbon::now()->subHour();
        $post->save();
        $this->api('POST', '/api/posts', 4, ['data' => ['type' => 'posts', 'attributes' => ['content' => 'Welcome, new readers', 'spoilerShield' => null], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '2']]]]]);
        $this->assertNull(Post::query()->where('discussion_id', 2)->orderByDesc('id')->first()->spoiler_position);
        $this->assertStringContainsString('Welcome, new readers', $this->api('GET', '/api/discussions/2', 3)[2]);
    }

    #[Test]
    public function quoting_a_spoiler_carries_its_place_even_into_another_discussion(): void
    {
        // Una (no place set) quotes Ash's chapter 5 reply into the unmarked book club discussion.
        [$status] = $this->api('POST', '/api/posts', 5, ['data' => ['type' => 'posts', 'attributes' => ['content' => '@"ash"#p3 '.self::REPLY.', quoted'], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '3']]]]]);
        $this->assertSame(201, $status);

        $quote = Post::query()->where('discussion_id', 3)->orderByDesc('id')->first();
        $this->assertSame(['w:1', 5], [$quote->spoiler_work, (int) $quote->spoiler_position]);
        $this->assertStringNotContainsString(self::REPLY, $this->api('GET', '/api/posts/'.$quote->id, 3)[2], 'Bea is at chapter 2');
        $this->assertStringContainsString(self::REPLY, $this->api('GET', '/api/posts/'.$quote->id, 4)[2]);

        // Choosing "nothing past" in the composer can't undo a quote.
        $quote->created_at = Carbon::now()->subHour();
        $quote->save();
        $this->api('POST', '/api/posts', 5, ['data' => ['type' => 'posts', 'attributes' => ['content' => '@"ash"#p3 again', 'spoilerShield' => null], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '2']]]]]);
        $this->assertSame(5, (int) Post::query()->where('discussion_id', 2)->orderByDesc('id')->value('spoiler_position'));
    }

    #[Test]
    public function a_reply_with_its_own_place_cannot_also_quote_another_works_spoiler(): void
    {
        // A second work, with a discussion at its chapter 2.
        $this->app();
        $now = Carbon::now()->subHour();
        $db = $this->database();
        $db->table('spoiler_works')->insert(['id' => 2, 'title' => 'Other Book', 'kind' => 'book', 'steps' => json_encode(['One', 'Two']), 'created_at' => $now, 'updated_at' => $now]);
        // Discussion, then its post, then the link between them (MySQL and PostgreSQL check the keys).
        $db->table('discussions')->insert(['id' => 6, 'title' => 'Other book ch 2', 'slug' => 'two', 'user_id' => 4, 'created_at' => $now, 'last_posted_at' => $now, 'comment_count' => 1, 'spoiler_work' => 'w:2', 'spoiler_position' => 2, 'spoiler_label' => 'Two']);
        $db->table('posts')->insert(['id' => 6, 'discussion_id' => 6, 'number' => 1, 'user_id' => 4, 'type' => 'comment', 'created_at' => $now, 'content' => '<t><p>The other twist</p></t>']);
        $db->table('discussions')->where('id', 6)->update(['first_post_id' => 6]);

        // Ash (finished The Long Road) replies in its chapter 1 thread, so her reply goes to "Finished"; quoting the other book is refused.
        $reply = fn (string $text) => $this->api('POST', '/api/posts', 4, ['data' => ['type' => 'posts', 'attributes' => ['content' => $text], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '2']]]]]);
        $this->assertSame(422, $reply('@"ash"#p6 and here is the ending of The Long Road too')[0]);
        $this->assertSame(0, Post::query()->where('discussion_id', 2)->where('content', 'like', '%ending of The Long Road%')->count());
    }

    #[Test]
    public function editing_a_reply_stamped_with_another_work_cannot_add_this_works_spoilers(): void
    {
        $this->app();
        $now = Carbon::now()->subHour();
        $db = $this->database();
        $db->table('spoiler_works')->insert(['id' => 2, 'title' => 'Other Book', 'kind' => 'book', 'steps' => json_encode(['One', 'Two']), 'created_at' => $now, 'updated_at' => $now]);
        // Bea's reply in the chapter 1 thread quoted the other book (stamped there), back when she was at chapter 1.
        $db->table('posts')->insert(['id' => 7, 'discussion_id' => 2, 'number' => 4, 'user_id' => 3, 'type' => 'comment', 'created_at' => $now, 'content' => '<t><p>The other book ends at sea</p></t>', 'spoiler_work' => 'w:2', 'spoiler_position' => 2]);

        // She's now at chapter 2 here, past the thread's chapter 1.
        $edit = fn (string $text) => $this->api('PATCH', '/api/posts/7', 3, ['data' => ['type' => 'posts', 'id' => '7', 'attributes' => ['content' => $text]]])[0];

        $this->assertSame(422, $edit('The other book ends at sea. And in chapter two here, the bridge falls'), 'more words: refused');
        $this->assertSame(200, $edit('The other book ends at Sea'), 'a typo fix: fine');
        $this->assertSame(['w:2', 2], [Post::query()->find(7)->spoiler_work, (int) Post::query()->find(7)->spoiler_position]);
    }

    #[Test]
    public function an_edit_raises_the_stamp_to_the_authors_place_now_never_lower(): void
    {
        // Bea (at chapter 2) replies in the chapter 1 thread: stamped at chapter 2.
        $this->api('POST', '/api/posts', 3, ['data' => ['type' => 'posts', 'attributes' => ['content' => 'Loving it so far'], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '2']]]]]);
        $post = Post::query()->where('discussion_id', 2)->where('user_id', 3)->where('type', 'comment')->orderByDesc('id')->first();
        $this->assertSame(2, (int) $post->spoiler_position);

        $edit = function (string $text) use ($post) {
            // Flood control: Flarum won't take a new edit seconds after the last post.
            $this->database()->table('posts')->where('user_id', 3)->update(['created_at' => Carbon::now()->subHour(), 'edited_at' => null]);

            return $this->api('PATCH', '/api/posts/'.$post->id, 3, ['data' => ['type' => 'posts', 'id' => (string) $post->id, 'attributes' => ['content' => $text]]]);
        };

        // She reads on to chapter 4 and adds to her reply: it now goes up to chapter 4.
        $this->api('POST', '/api/spoiler-shield/progress', 3, ['work' => 'w:1', 'position' => 4]);
        $edit('Loving it so far. EDIT: chapter four, wow');
        $this->assertSame(4, (int) $post->fresh()->spoiler_position);

        // Setting her place back doesn't lower it.
        $this->api('POST', '/api/spoiler-shield/progress', 3, ['work' => 'w:1', 'position' => 2]);
        $edit('Loving it so far. EDIT: chapter four, wow. Typo fixed');
        $this->assertSame(4, (int) $post->fresh()->spoiler_position);
    }

    #[Test]
    public function lowering_clearing_or_deleting_a_mark_keeps_unstamped_replies_hidden(): void
    {
        $this->app();
        // Una (no place set, so her reply carries no stamp) replied in the chapter 4 discussion.
        $quiet = 'Kael was always going to break the oath';
        $this->database()->table('posts')->insert(['id' => 7, 'discussion_id' => 1, 'number' => 2, 'user_id' => 5, 'type' => 'comment', 'created_at' => Carbon::now()->subHour(), 'content' => '<t><p>'.$quiet.'</p></t>']);

        $mark = fn ($value) => $this->api('PATCH', '/api/discussions/1', 1, ['data' => ['type' => 'discussions', 'id' => '1', 'attributes' => ['spoilerShield' => $value]]]);

        // Lowered to chapter 2: the reply keeps chapter 4.
        $mark(['work' => 'w:1', 'position' => 2]);
        $this->assertSame(['w:1', 4], [Post::query()->find(7)->spoiler_work, (int) Post::query()->find(7)->spoiler_position]);
        $this->assertStringNotContainsString($quiet, $this->api('GET', '/api/posts/7', 3)[2]);

        // Moved into another discussion (fof/merge, a split): it keeps its place.
        $this->database()->table('posts')->insert(['id' => 8, 'discussion_id' => 1, 'number' => 3, 'user_id' => 5, 'type' => 'comment', 'created_at' => Carbon::now()->subHour(), 'content' => '<t><p>Moved note</p></t>']);
        $this->database()->table('discussions')->where('id', 1)->update(['spoiler_position' => 4]);
        $moved = Post::query()->find(8);
        $moved->discussion_id = 3;
        $moved->number = 2;
        $moved->save();
        $this->assertSame(['w:1', 4], [$moved->fresh()->spoiler_work, (int) $moved->fresh()->spoiler_position]);

        // The work deleted by a moderator: its replies stay blurred (one by one to reveal), not open to all.
        $this->assertSame(204, $this->api('DELETE', '/api/spoiler-shield/works/1', 1)[0]);
        $this->assertStringNotContainsString($quiet, $this->api('GET', '/api/posts/7', 3)[2]);
        $this->assertStringNotContainsString(self::REPLY, $this->api('GET', '/api/posts/3', 3)[2]);
    }

    #[Test]
    public function replies_split_off_with_fof_split_keep_their_discussions_place(): void
    {
        $this->extension('fof-split');
        $this->app();
        $quiet = 'Kael was always going to break the oath';
        $this->database()->table('posts')->insert(['id' => 7, 'discussion_id' => 1, 'number' => 2, 'user_id' => 5, 'type' => 'comment', 'created_at' => Carbon::now()->subHour(), 'content' => '<t><p>'.$quiet.'</p></t>']);
        $this->database()->table('discussions')->where('id', 1)->update(['comment_count' => 2, 'last_post_number' => 2, 'last_post_id' => 7]);

        // The split creates a discussion and a post: on PostgreSQL the fixtures' explicit ids left the sequences behind.
        $db = $this->database();
        if ($db->getDriverName() === 'pgsql') {
            foreach (['posts', 'discussions'] as $table) {
                $name = $db->getTablePrefix().$table;
                $db->statement("select setval(pg_get_serial_sequence('$name', 'id'), (select max(id) from \"$name\"))");
            }
        }

        // The real split (a bulk UPDATE of discussion_id, no model events).
        [$status] = $this->api('POST', '/api/split', 1, ['title' => 'Split off', 'start_post_id' => 7, 'end_post_number' => 2]);
        $this->assertLessThan(300, $status);

        $post = Post::query()->find(7);
        $this->assertNotSame(1, (int) $post->discussion_id, 'moved');
        $this->assertSame(['w:1', 4], [$post->spoiler_work, (int) $post->spoiler_position]);
        $this->assertStringNotContainsString($quiet, $this->api('GET', '/api/posts/7', 3)[2]);
    }

    #[Test]
    public function unmarking_a_discussion_keeps_its_stamped_replies_hidden(): void
    {
        $this->api('PATCH', '/api/discussions/2', 1, ['data' => ['type' => 'discussions', 'id' => '2', 'attributes' => ['spoilerShield' => null]]]);
        $this->assertNull(Discussion::query()->find(2)->spoiler_work);

        $this->assertStringNotContainsString(self::REPLY, $this->api('GET', '/api/posts/3', 3)[2]);
        $this->assertStringContainsString(self::REPLY, $this->api('GET', '/api/posts/3', 4)[2]);
    }

    #[Test]
    public function marks_from_an_extension_that_is_switched_off_count_as_unmarked(): void
    {
        // Core's LIKE search on every database: MySQL's full-text index can't see a test's uncommitted rows.
        $this->setting('search_cjk_mode', '1');
        // Marquee, Anthology and Panels aren't enabled here: their marks are leftovers.
        $this->app();
        $now = Carbon::now()->subHour();
        $this->database()->table('discussions')->insert([
            ['id' => 7, 'title' => 'Dragon episode chat', 'slug' => 's2e5', 'user_id' => 4, 'created_at' => $now, 'last_posted_at' => $now, 'comment_count' => 1, 'spoiler_work' => 'mq:1', 'spoiler_position' => 200005],
            ['id' => 8, 'title' => 'Chapter nine chat', 'slug' => 'chapter-9', 'user_id' => 4, 'created_at' => $now, 'last_posted_at' => $now, 'comment_count' => 1, 'spoiler_work' => 'an:1', 'spoiler_position' => 9],
            ['id' => 9, 'title' => 'Manga chapter six chat', 'slug' => 'chapter-6', 'user_id' => 4, 'created_at' => $now, 'last_posted_at' => $now, 'comment_count' => 1, 'spoiler_work' => 'pn:1', 'spoiler_position' => 600],
        ]);

        $doc = $this->api('GET', '/api/discussions', null)[1];
        foreach (['7' => 'Dragon episode chat', '8' => 'Chapter nine chat', '9' => 'Manga chapter six chat'] as $id => $title) {
            $item = collect($doc['data'])->firstWhere('id', $id);
            $this->assertSame($title, $item['attributes']['title']);
            $this->assertNull($item['attributes']['spoilerShield']);
        }
        $this->assertContains('7', array_column($this->api('GET', '/api/discussions', null, [], ['filter' => ['q' => 'Dragon']])[1]['data'], 'id'));

        // A reply stamped on that switched-off ladder is unmarked too: it doesn't fall back to its
        // discussion's work with a number from another ladder.
        $this->database()->table('posts')->where('id', 3)->update(['spoiler_work' => 'mq:1', 'spoiler_position' => 200005]);
        $this->assertStringContainsString(self::REPLY, $this->api('GET', '/api/posts/3', 3)[2]);
    }

    #[Test]
    public function realtimes_public_push_carries_no_text_of_marked_items(): void
    {
        $generator = $this->app()->getContainer()->make(\Flarum\Realtime\Push\Payload\Generator::class);

        $public = $generator(Post::query()->find(3));
        $this->assertArrayNotHasKey('attributes', collect($public['included'])->firstWhere('id', '3') ?? ['attributes' => 'missing post']);
        $this->assertArrayNotHasKey('attributes', $public['data'], 'the marked discussion too');

        // Built for a member it's gated like any read, and whole for one who may read it.
        $this->assertStringContainsString(self::REPLY, json_encode($generator(Post::query()->find(3), User::query()->find(4))));
        $this->assertArrayHasKey('attributes', $generator(Post::query()->find(5))['data'], 'unmarked: untouched');
    }

    #[Test]
    public function realtimes_public_push_carries_no_text_from_a_work_even_with_guests_on_show(): void
    {
        $this->setting('ernestdefoe-spoiler-shield.guests', 'show');
        $generator = $this->app()->getContainer()->make(\Flarum\Realtime\Push\Payload\Generator::class);

        // The chapter 4 discussion's opening post carries no mark of its own; members on "blur" get the public channel too.
        $raw = json_encode($generator(Post::query()->find(1)));
        $this->assertStringNotContainsString(self::BODY, $raw);
        $this->assertStringNotContainsString(self::TITLE, $raw);
    }

    #[Test]
    public function a_new_discussion_is_marked_from_the_composer_and_its_slug_hides_the_title(): void
    {
        $create = fn (array $mark) => $this->api('POST', '/api/discussions', 2, ['data' => ['type' => 'discussions', 'attributes' => [
            'title' => 'Did the bridge collapse kill him', 'content' => 'I need to know', 'spoilerShield' => $mark,
        ]]]);

        $this->assertSame(422, $create(['work' => 'w:1', 'position' => 9])[0]);
        $this->assertSame(422, $create(['work' => 'w:99', 'position' => 1])[0]);

        [$status, $doc] = $create(['work' => 'w:1', 'position' => 3]);
        $this->assertSame(201, $status);
        $discussion = Discussion::query()->find($doc['data']['id']);
        $this->assertSame('w:1', $discussion->spoiler_work);
        $this->assertSame(3, (int) $discussion->spoiler_position);
        $this->assertSame('ch-3', $discussion->slug);
        $this->assertNull(Post::query()->find($discussion->first_post_id)->spoiler_position, 'the first post shares the discussion\'s mark');

        $this->assertStringNotContainsString('bridge collapse', $this->api('GET', '/api/discussions', 5)[2]);

        // Unmarked later by someone who may rename it: the title's slug is back.
        $this->assertSame(403, $this->api('PATCH', '/api/discussions/'.$discussion->id, 3, ['data' => ['type' => 'discussions', 'id' => (string) $discussion->id, 'attributes' => ['spoilerShield' => null]]])[0]);
        $this->api('PATCH', '/api/discussions/'.$discussion->id, 1, ['data' => ['type' => 'discussions', 'id' => (string) $discussion->id, 'attributes' => ['spoilerShield' => null]]]);
        $this->assertSame('did-the-bridge-collapse-kill-him', $discussion->fresh()->slug);
    }

    #[Test]
    public function emails_skip_the_readers_they_would_spoil(): void
    {
        $sent = [];
        $this->app()->getContainer()->instance(NotificationMailer::class, new class($sent) extends NotificationMailer {
            public function __construct(private array &$sent)
            {
            }

            public function send($blueprint, User $user): void
            {
                $this->sent[] = $user->id;
            }
        });

        // Ash's spoiling reply mentions a post of Bea's and one of Una's... Bea and Ash are told.
        $reply = Post::query()->find(3);
        $mentioned = Post::query()->find(2);
        $this->app()->getContainer()->make(NotificationSyncer::class)->sync(
            new \Flarum\Mentions\Notification\PostMentionedBlueprint($mentioned, $reply),
            [User::query()->find(3), User::query()->find(4)]
        );

        $this->assertSame([4], $sent, 'no email to Bea, who is at chapter 2');
        $this->assertSame(1, $this->database()->table('notifications')->where('user_id', 3)->count(), 'her alert still arrives, gated');
    }

    #[Test]
    public function works_are_defined_by_members_and_edited_by_their_owner(): void
    {
        [$status, $doc] = $this->api('POST', '/api/spoiler-shield/works', 2, ['title' => 'Night Shift', 'kind' => 'show', 'steps' => ['Pilot', 'Episode 2', '']]);
        $this->assertSame(200, $status);
        $this->assertSame(['Pilot', 'Episode 2'], $doc['data']['ladder']['steps']);
        $id = (int) substr($doc['data']['work'], 2);

        $this->assertSame(403, $this->api('PATCH', "/api/spoiler-shield/works/$id", 3, ['title' => 'Mine now', 'steps' => ['A']])[0]);
        $this->assertSame(200, $this->api('PATCH', "/api/spoiler-shield/works/$id", 2, ['title' => 'Night Shift', 'steps' => ['Pilot', 'Episode 2', 'Episode 3']])[0]);

        // A step in use can't disappear.
        $this->assertSame(422, $this->api('PATCH', '/api/spoiler-shield/works/1', 1, ['title' => 'The Long Road', 'steps' => ['Ch 1', 'Ch 2']])[0]);

        $found = $this->api('GET', '/api/spoiler-shield/works', 3, [], ['q' => 'night'])[1]['data'];
        $this->assertSame('Night Shift', $found[0]['title']);

        // Once in use (Bea is at "Episode 2"), the owner can rename and append, nothing else.
        $this->api('POST', '/api/spoiler-shield/progress', 3, ['work' => "w:$id", 'position' => 2]);
        $this->assertSame(422, $this->api('PATCH', "/api/spoiler-shield/works/$id", 2, ['title' => 'Night Shift', 'steps' => ['Episode 2', 'Pilot', 'Episode 3']])[0], 'no reordering');
        $this->assertSame(422, $this->api('PATCH', "/api/spoiler-shield/works/$id", 2, ['title' => 'Night Shift', 'steps' => ['Pilot']])[0], 'no removing');
        $this->assertSame(200, $this->api('PATCH', "/api/spoiler-shield/works/$id", 2, ['title' => 'Night Shift', 'steps' => ['The pilot', 'Episode 2', 'Episode 3', 'Episode 4']])[0], 'rename + append');
        $this->assertSame(403, $this->api('DELETE', "/api/spoiler-shield/works/$id", 2)[0], 'the owner may not delete it now');
        $this->assertSame(204, $this->api('DELETE', "/api/spoiler-shield/works/$id", 1)[0], 'a moderator may');
    }

    #[Test]
    public function a_search_does_not_confirm_a_guess(): void
    {
        // Core's LIKE search on every database: MySQL's full-text index can't see a test's uncommitted rows.
        $this->setting('search_cjk_mode', '1');

        $ids = fn (?int $as) => array_column($this->api('GET', '/api/discussions', $as, [], ['filter' => ['q' => 'Kael']])[1]['data'], 'id');

        $this->assertNotContains('1', $ids(3), 'behind: not even a blurred hit');
        $this->assertNotContains('1', $ids(null));
        $this->assertContains('1', $ids(4));

        // A readable discussion must not turn up because a hidden reply in it matched.
        $words = fn (?int $as) => array_column($this->api('GET', '/api/discussions', $as, [], ['filter' => ['q' => 'final chapter']])[1]['data'], 'id');
        $this->assertNotContains('2', $words(3));
        $this->assertContains('2', $words(4));

        // Searching posts: the hidden reply isn't a match at all.
        $posts = fn (?int $as) => array_column($this->api('GET', '/api/posts', $as, [], ['filter' => ['q' => 'final chapter']])[1]['data'] ?? [], 'id');
        $this->assertNotContains('3', $posts(3));
        $this->assertContains('3', $posts(4));
    }

    #[Test]
    public function a_page_linking_to_a_hidden_discussion_loses_its_descriptions_and_the_link_is_escaped(): void
    {
        $this->app();
        // The harness doesn't load extension locales; the placeholder must carry the work's name here.
        $this->app()->getContainer()->make(LocaleManager::class)->addTranslations('en', __DIR__.'/../../../locale/en.yml');
        $now = Carbon::now()->subHour();
        $this->database()->table('spoiler_works')->insert(['id' => 2, 'title' => '<img src=x onerror=alert(1)>', 'kind' => 'book', 'steps' => json_encode(['One', 'Two']), 'created_at' => $now, 'updated_at' => $now]);
        $this->database()->table('discussions')->where('id', 1)->update(['title' => "Kael's betrayal of the guild", 'spoiler_work' => 'w:2', 'spoiler_position' => 2, 'spoiler_label' => 'Two']);
        $this->database()->table('posts')->where('id', 5)->update(['content' => '<r><p>See <URL url="http://localhost/d/1-two">Kael\'s betrayal of the guild</URL></p></r>']);

        // /d/3 is readable; its first post links to the hidden /d/1, and the fake SEO extension describes it from that post.
        $raw = $this->api('GET', '/d/3', 3)[2];
        $this->assertStringNotContainsString('<img src=x', $raw);
        $this->assertStringNotContainsString('betrayal of the guild', $raw);
        $this->assertStringNotContainsString('og:description', $raw, 'descriptions go whole');
        $this->assertStringNotContainsString('application/ld+json', $raw);
        $this->assertStringContainsString('Spoiler for Two (&lt;img src=x onerror=alert(1)&gt;)', $raw);
    }

    #[Test]
    public function a_readable_discussion_page_loses_descriptions_when_a_reply_in_it_is_hidden(): void
    {
        // Like fof/seo's post crawler: the page's JSON-LD and description carry every reply.
        $this->extend((new Extend\Frontend('forum'))->content(function (Document $document, ServerRequestInterface $request) {
            if ($request->getAttribute('routeName') === 'discussion') {
                $text = Post::query()->where('discussion_id', (int) $request->getAttribute('routeParameters')['id'])->where('type', 'comment')->pluck('content')->implode(' ');
                $document->head[] = '<script type="application/ld+json">'.json_encode(['comment' => strip_tags($text)]).'</script>';
                $document->meta['description'] = strip_tags($text);
            }
        }));

        $this->assertStringNotContainsString(self::REPLY, $this->api('GET', '/d/2', 3)[2]);
        $this->assertStringContainsString(self::REPLY, $this->api('GET', '/d/2', 4)[2], 'Ash may read them all');

        // A reply quoting another work, at a lower number than the furthest stamp here: places in
        // different works don't compare. Ash finished The Long Road, but not the other book.
        $now = Carbon::now()->subHour();
        $this->database()->table('spoiler_works')->insert(['id' => 2, 'title' => 'Other Book', 'kind' => 'book', 'steps' => json_encode(['One', 'Two']), 'created_at' => $now, 'updated_at' => $now]);
        $this->database()->table('posts')->insert([
            ['id' => 9, 'discussion_id' => 2, 'number' => 4, 'user_id' => 3, 'type' => 'comment', 'created_at' => $now, 'content' => '<t><p>The other book ends at sea</p></t>', 'spoiler_work' => 'w:2', 'spoiler_position' => 2],
            ['id' => 10, 'discussion_id' => 2, 'number' => 5, 'user_id' => 5, 'type' => 'comment', 'created_at' => $now, 'content' => '<t><p>Chapter five, finally</p></t>', 'spoiler_work' => 'w:1', 'spoiler_position' => 5],
        ]);
        $this->assertStringNotContainsString('ends at sea', $this->api('GET', '/d/2', 4)[2]);
    }

    #[Test]
    public function an_extensions_whos_reading_what_list_is_gated_too(): void
    {
        // Like Presence: "ash is viewing <title>", with no link, just the discussion's id and title.
        $this->extend((new Extend\Routes('api'))->get('/test-presence', 'test.presence', PresenceController::class));

        $this->assertStringNotContainsString(self::TITLE, $this->api('GET', '/api/test-presence', 3)[2]);
        $this->assertStringNotContainsString(self::TITLE, $this->api('GET', '/api/test-presence', null)[2]);
        $this->assertStringContainsString(self::TITLE, $this->api('GET', '/api/test-presence', 4)[2]);

        // And a plain list of them: { "discussions": [ { "id", "title" } ] }.
        $this->assertStringNotContainsString(self::TITLE, $this->api('GET', '/api/test-presence', 3, [], ['list' => '1'])[2]);
    }

    #[Test]
    public function a_members_own_words_are_never_rewritten_so_nothing_confirms_a_guess(): void
    {
        $this->extend((new Extend\Routes('api'))->get('/test-widgets', 'test.widgets', WidgetController::class));
        $this->app();
        $now = Carbon::now()->subHour();
        $this->database()->table('discussions')->insert(['id' => 9, 'title' => 'Is it true Kael betrays the guild?', 'slug' => 'guess', 'user_id' => 3, 'created_at' => $now, 'last_posted_at' => $now, 'comment_count' => 0]);

        $raw = $this->api('GET', '/api/test-widgets', 3)[2];
        $this->assertStringContainsString('Is it true Kael betrays the guild?', $raw, 'her guess comes back exactly as she wrote it');
    }

    #[Test]
    public function another_extensions_json_listing_discussions_is_gated_too(): void
    {
        // Like Page Builder's widgets: plain JSON from the database, links and titles.
        $this->extend((new Extend\Routes('api'))->get('/test-widgets', 'test.widgets', WidgetController::class));

        foreach ([3, null] as $as) {
            [$status, $doc, $raw] = $this->api('GET', '/api/test-widgets', $as);
            $this->assertSame(200, $status);
            $this->assertStringNotContainsString(self::TITLE, $raw);
            $this->assertStringNotContainsString(self::BODY, $raw);
            $items = collect($doc['widgets'][0]['items'])->keyBy('id');
            $this->assertSame('http://localhost/d/1-ch-4', $items[1]['url']);
            $this->assertSame('Book club general', $items[3]['title'], 'the rest untouched');
        }

        $this->assertStringContainsString(self::TITLE, $this->api('GET', '/api/test-widgets', 4)[2]);
    }

    #[Test]
    public function another_extensions_excerpt_of_a_hidden_last_reply_goes(): void
    {
        // Like the Cascade theme's "last reply" excerpt, on a discussion the reader may open.
        $this->extend((new Extend\ApiResource(\Flarum\Api\Resource\DiscussionResource::class))->fields(fn () => [
            \Flarum\Api\Schema\Str::make('themeLastReply')->get(fn ($d) => (string) Post::query()->where('discussion_id', $d->id)->where('type', 'comment')->orderByDesc('number')->value('content')),
        ]));

        [, $doc, $raw] = $this->api('GET', '/api/discussions', 3);
        $this->assertStringNotContainsString(self::REPLY, $raw);
        $this->assertSame('Opening chapters chat', collect($doc['data'])->firstWhere('id', '2')['attributes']['title'], 'the discussion itself stays readable');
        $this->assertStringContainsString(self::REPLY, $this->api('GET', '/api/discussions', 4)[2]);
    }

    #[Test]
    public function another_extensions_email_driver_is_wrapped_not_replaced(): void
    {
        // Like Courier: an extension that puts its own email driver in core's place.
        RecordingEmailDriver::$sent = [];
        $this->extend((new Extend\Notification())->driver('email', RecordingEmailDriver::class));
        $this->app();

        $this->app()->getContainer()->make(NotificationSyncer::class)->sync(
            new \Flarum\Mentions\Notification\PostMentionedBlueprint(Post::query()->find(2), Post::query()->find(3)),
            [User::query()->find(3), User::query()->find(4)]
        );

        $this->assertSame([4], RecordingEmailDriver::$sent, 'its driver still sends, to Ash only');
    }
}

class PresenceController implements \Psr\Http\Server\RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        $d = Discussion::query()->find(1);

        if ($request->getQueryParams()['list'] ?? false) {
            return new \Laminas\Diactoros\Response\JsonResponse(['discussions' => [['id' => $d->id, 'title' => $d->title]]]);
        }

        return new \Laminas\Diactoros\Response\JsonResponse(['members' => [
            ['user' => ['id' => 4, 'displayName' => 'ash'], 'activity' => ['kind' => 'discussion', 'discussion' => ['id' => $d->id, 'title' => $d->title, 'slug' => $d->slug]]],
            ['user' => ['id' => 2], 'activity' => ['kind' => 'reply', 'discussionId' => $d->id, 'discussionTitle' => $d->title]],
        ]]);
    }
}

class WidgetController implements \Psr\Http\Server\RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        $items = Discussion::query()->orderBy('id')->get()->map(fn ($d) => [
            'id' => $d->id, 'title' => $d->title, 'url' => 'http://localhost/d/'.$d->id.'-'.$d->slug, 'excerpt' => $d->firstPost?->content,
        ]);

        return new \Laminas\Diactoros\Response\JsonResponse(['widgets' => [['type' => 'recent', 'items' => $items->all()]]]);
    }
}

class RecordingEmailDriver implements \Flarum\Notification\Driver\NotificationDriverInterface
{
    /** @var int[] */
    public static array $sent = [];

    public function send(\Flarum\Notification\Blueprint\BlueprintInterface $blueprint, array $users): void
    {
        foreach ($users as $user) {
            self::$sent[] = (int) $user->id;
        }
    }

    public function registerType(string $blueprintClass, array $driversEnabledByDefault): void
    {
    }
}
