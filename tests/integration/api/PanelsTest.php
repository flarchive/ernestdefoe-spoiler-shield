<?php

namespace ErnestDefoe\SpoilerShield\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Panels\Model\Chapter;
use ErnestDefoe\Panels\Publisher;
use Flarum\Discussion\Discussion;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * A Panels series is a work: its chapters (number × 100) are the ladder,
 * each chapter's discussion sits at its chapter, and a member's place is
 * the highest chapter they finished in Panels' reader.
 */
class PanelsTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private const SECRET = 'Kaito betrays the moon guard';
    private const EXTRA = 'Beach episode of the moon guard';

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'ernestdefoe-panels', 'ernestdefoe-spoiler-shield');

        $then = Carbon::now()->subDay();
        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'bea', 'email' => 'bea@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'ash', 'email' => 'ash@machine.local', 'is_email_confirmed' => 1],
            ],
            'panel_series' => [
                ['id' => 1, 'slug' => 'moonlit-blades', 'title' => 'Moonlit Blades', 'user_id' => 4, 'created_at' => $then, 'updated_at' => $then],
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

    /** A chapter put out the way Panels does it: saved, then launched (its discussion starts). */
    private function chapter(string $number, ?string $title = null, ?Carbon $at = null): Chapter
    {
        $chapter = new Chapter();
        $chapter->series_id = 1;
        $chapter->user_id = 4;
        $chapter->number = $number;
        $chapter->title = $title;
        $chapter->state = 'published';
        $chapter->published_at = $at ?? Carbon::now()->subHour();
        $chapter->save();
        resolve(Publisher::class)->launch($chapter);

        return $chapter->refresh();
    }

    private function read(int $userId, Chapter $chapter): void
    {
        $this->database()->table('panel_reads')->insert(['user_id' => $userId, 'chapter_id' => $chapter->id, 'read_at' => Carbon::now()]);
    }

    /** @return array{0: string, 1: int|null, 2: string}|null */
    private function mark(Chapter $chapter): ?array
    {
        $d = Discussion::query()->find($chapter->discussion_id);

        return $d ? [(string) $d->spoiler_work, $d->spoiler_position === null ? null : (int) $d->spoiler_position, (string) $d->slug] : null;
    }

    #[Test]
    public function a_chapter_coming_out_marks_its_discussion_at_its_number(): void
    {
        $this->app();
        $this->chapter('1');
        $extra = $this->chapter('6.5', self::EXTRA);
        $this->assertSame(['pn:1', 650, 'chapter-6-5'], $this->mark($extra));

        // Renumbered: the mark follows.
        $extra->number = '7';
        $extra->save();
        $this->assertSame(['pn:1', 700, 'chapter-7'], $this->mark($extra));

        // Scheduled: no discussion until its time comes, then it's marked.
        $later = $this->chapter('8', null, Carbon::now()->addDay());
        $this->assertNull($later->discussion_id);
        $later->published_at = Carbon::now()->subMinute();
        $later->save();
        resolve(Publisher::class)->launch($later);
        $this->assertSame(['pn:1', 800, 'chapter-8'], $this->mark($later->refresh()));

        // A discussion marked by hand at a work defined here keeps that.
        $this->database()->table('spoiler_works')->insert(['id' => 1, 'title' => 'Moon book', 'kind' => 'book', 'steps' => json_encode(['One']), 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()]);
        $this->database()->table('discussions')->where('id', $later->discussion_id)->update(['spoiler_work' => 'w:1', 'spoiler_position' => 1]);
        $later->title = 'Retitled';
        $later->save();
        $this->assertSame('w:1', $this->mark($later)[0]);

        // Deleted: the mark goes with it.
        $extra->delete();
        $this->assertSame(['', null], array_slice($this->mark($extra), 0, 2));
    }

    #[Test]
    public function the_next_chapter_out_is_open_and_later_ones_are_blurred(): void
    {
        $this->app();
        $one = $this->chapter('1');
        $two = $this->chapter('2');
        $this->chapter('2.5', self::EXTRA);
        $three = $this->chapter('3', self::SECRET);

        // A guest starts at chapter one: two and the extra are past it.
        $raw = $this->api('GET', '/api/discussions', null)[2];
        $this->assertStringNotContainsString(self::SECRET, $raw);
        $this->assertStringNotContainsString(self::EXTRA, $raw);

        // Bea finished chapter one: chapter two's talk is open, the extra and three aren't.
        $this->read(3, $one);
        [, $doc, $raw] = $this->api('GET', '/api/discussions', 3);
        $hidden = array_column(array_map(fn ($d) => ['id' => (int) $d['id'], 'hidden' => (bool) ($d['attributes']['spoilerShield']['hidden'] ?? false)], $doc['data']), 'hidden', 'id');
        $this->assertFalse($hidden[$two->discussion_id]);
        $this->assertTrue($hidden[$three->discussion_id]);
        $this->assertStringNotContainsString(self::SECRET, $raw);

        // Her place is the highest chapter she finished (Panels' "read up to here" fills the rest).
        $this->read(3, $three);
        $this->assertStringContainsString(self::SECRET, $this->api('GET', '/api/discussions', 3)[2]);

        // A taken-down chapter isn't one anybody has to get through.
        $this->database()->table('panel_reads')->where('user_id', 3)->where('chapter_id', $three->id)->delete();
        $this->database()->table('panel_chapters')->whereIn('number', ['2', '2.5'])->update(['state' => 'removed', 'removed_at' => Carbon::now()]);
        $this->assertStringContainsString(self::SECRET, $this->api('GET', '/api/discussions', 3)[2]);
    }

    #[Test]
    public function replies_are_stamped_with_their_authors_chapter(): void
    {
        $this->app();
        $one = $this->chapter('1');
        $three = $this->chapter('3');
        $this->read(2, $three);
        $this->read(3, $one);

        $reply = 'Wait until chapter three, the moon guard falls';
        [$status, $doc] = $this->api('POST', '/api/posts', 2, ['data' => ['type' => 'posts', 'attributes' => ['content' => $reply], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => (string) $one->discussion_id]]]]]);
        $this->assertSame(201, $status);
        $this->assertSame(['pn:1', 300], [
            $this->database()->table('posts')->where('id', $doc['data']['id'])->value('spoiler_work'),
            (int) $this->database()->table('posts')->where('id', $doc['data']['id'])->value('spoiler_position'),
        ]);
        $this->assertStringNotContainsString('moon guard falls', $this->api('GET', '/api/posts/'.$doc['data']['id'], 3)[2]);
    }

    #[Test]
    public function panels_own_pages_drop_the_titles_of_chapters_ahead(): void
    {
        $this->app();
        $one = $this->chapter('1', 'Arrival');
        $this->chapter('2', 'The guard');
        $this->chapter('3', self::SECRET);
        // Scheduled: listed with its title before it's out, and before it has a discussion.
        $this->chapter('4', 'The moon guard rises again', Carbon::now()->addDay());
        $this->read(3, $one);

        foreach ([3, null] as $as) {
            // The series page's chapter list.
            [$status, $doc, $raw] = $this->api('GET', '/api/panels/series/moonlit-blades', $as);
            $this->assertSame(200, $status, $raw);
            $this->assertStringNotContainsString(self::SECRET, $raw);
            $this->assertStringNotContainsString('rises again', $raw);
            $three = array_values(array_filter($doc['data']['chapters'], fn ($c) => $c['number'] === '3'))[0];
            $this->assertSame([null, true], [$three['title'], $three['spoilerShield']['hidden']]);

            // The new-chapters feed.
            $this->assertStringNotContainsString(self::SECRET, $this->api('GET', '/api/panels/feed', $as)[2]);
        }

        // The reader keeps the title of the chapter being read; the next one's goes when it's ahead.
        [$status, $doc, $raw] = $this->api('GET', '/api/panels/series/moonlit-blades/chapters/2', 3);
        $this->assertSame(200, $status, $raw);
        $this->assertSame('The guard', $doc['chapter']['title']);
        $this->assertStringNotContainsString(self::SECRET, $raw);
        // Bea finished chapter one: two, the next one out, is open to her (a guest's place is before it).
        [, $doc] = $this->api('GET', '/api/panels/series/moonlit-blades/chapters/1', 3);
        $this->assertSame('The guard', $doc['next']['title']);
        $this->assertNull($this->api('GET', '/api/panels/series/moonlit-blades/chapters/1', null)[1]['next']['title']);

        // The ladder and the series' name.
        // withPath: "pn:1" read as a whole URI looks like a scheme.
        $request = $this->request('GET', '/api/spoiler-shield/works', ['authenticatedAs' => 3]);
        $response = $this->send($request->withUri($request->getUri()->withPath('/api/spoiler-shield/works/pn:1')));
        $doc = json_decode((string) $response->getBody(), true);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('"chapters":[100,200,300]', json_encode($doc));
        $this->assertStringContainsString('Moonlit Blades', json_encode($doc));
    }

    #[Test]
    public function the_series_page_costs_the_same_queries_for_1_or_20_chapters_ahead(): void
    {
        $this->app();
        $db = $this->database();
        $this->read(3, $this->chapter('1'));
        $count = function (?int $as) use ($db) {
            $db->flushQueryLog();
            $db->enableQueryLog();
            [$status] = $this->api('GET', '/api/panels/series/moonlit-blades', $as);
            $db->disableQueryLog();
            $this->assertSame(200, $status);

            return count($db->getQueryLog());
        };

        $this->chapter('3', self::SECRET);
        foreach ([null, 3] as $as) {
            $count($as);
        }
        $one = [$count(null), $count(3)];
        for ($i = 4; $i <= 22; $i++) {
            $this->chapter((string) $i, 'Later '.$i);
        }
        $twenty = [$count(null), $count(3)];

        $this->assertSame($one, $twenty, 'guest, member: queries for 1 chapter ahead vs 20');
    }
}
