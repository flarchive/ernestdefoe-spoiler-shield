# Spoiler Shield: working rules

- **Commit straight to `main` and push. Never open a pull request on this repo.** Commit subjects are conventional (`feat:`, `fix:`); the release drafter bumps from the subject.
- `js/dist` is committed and is what forums load. After any change under `js/src`, run `npm run build` in `js/` (from a fresh `npm ci`) and commit the bundle; CI fails if it is stale.
- Publishing a release is Ernest's click. Nothing is announced until he says so.

## Design rules that exist for a reason

- **The Gate is the only judge** (`Gate::check` / `checkMany`). Everything that shows a discussion or post asks it: `Http\RedactApi` (every API response, and Flarum's internal API client runs the same middleware, so the page preload, `<title>`, no-JS content and realtime pushes are covered), `Http\ScrubDocument` (the page head after SEO extensions, priority -1000), `Notification\GatedEmailDriver` (WRAPS whichever `email` driver is registered, in `Provider::boot` — Courier replaces it too; checks the subject and every Post/Discussion in the blueprint's public properties), `Search\HiddenFromSearch` (visibility scopes active only while a request searches by words: hidden items can't match), `Integration\RealtimePublicPayload` (realtime's public channel gets no text of marked items).
- **Never rewrite by matching words** (that confirms a guess): only link text to /d/{id}; page descriptions/JSON-LD go whole when a page could carry a spoiler.
- **Redaction is an allow-list**: a hidden discussion/post keeps numbers, booleans, `can*`, dates and named relationships; every other string/array attribute and every other relationship goes, and included records only they pointed to are dropped. New core or extension fields are hidden by default.
- **Fail closed**: if the Redactor throws, `redactAll` blanks every title and body in the response.
- **Marks live on the rows** (`discussions.spoiler_work/position/label`, `posts.spoiler_work/position/label` — a stamp carries its own work, so a quote of another work, or unmarking the discussion, keeps it gated; a reply is stamped at least as far as anything it quotes or mentions, and a reply with its own place quoting another work's spoiler is refused; an edit raises the stamp to the author's place now, never lower; before a discussion's mark goes down/away/to another work, its unstamped replies take the old place in one UPDATE (`MarkWriter::keepRepliesGated`); a moved post keeps its source's place (CommentPost saving — Eloquent fires events under the subclass)), so reading them costs nothing; `ssMark` is the internal field the Redactor reads and always removes. Gate queries are constant per response (progress, reveals, work titles, Curio offer).
- **Work keys**: `w:{id}` (spoiler_works, ladder = JSON `steps`, position = 1-based index), `mq:{media id}` (Marquee: season×100000+episode, ×100000+99999 = whole season), `an:{series id}` (Anthology chapter position). `Marks::FINISHED` (2147483647) is past everything.
- **Anthology**: progress is read from core's `discussion_user` (what the member opened). Discussions compare against the *frontier* (the next unread chapter is open); posts against *read*.
- **A positioned discussion's slug is its place** (`Marks::slugFor`, language-independent), so titles never reach URLs or sitemaps; unmarking restores the title slug.
- Marquee links are synced in an Eloquent `saving` hook; an explicit mark from the composer wins (`MarkWriter::$explicit`). Anthology chapters via `Chapter::saved/deleted` and `Series::deleting`.
- Steps of a work can be renamed and appended; a step in use can't be removed or reordered; only manageWorks deletes a work in use.
- Marquee/Anthology marks count as unmarked while that extension is off (`Integrations::live`). Anthology: a chapter opened while hidden isn't counted read (UserState saving); guests and new readers start at chapter 1; the "show" default applies to a series not started.
- Hidden items report canEdit/canRename false (editing would start from the placeholder).
- extend.php uses string literals outside closures, never class constants.
- Backfills page with `chunkById` (a filter on the column being filled makes `chunk()` skip pages).

## Testing

- `DB_DRIVER=sqlite DB_DATABASE=… DB_PREFIX=ss_ composer test:setup && composer test`, and on MySQL with `DB_PREFIX=dev_`. `composer analyse:phpstan`.
- `GateTest::hidden_words_never_reach_a_reader_who_is_behind` is the proof: add any new surface (endpoint, page) to `everything()`.
- Extension locales don't load inside the test harness; assert on data (`"label":"Ch 4"`), not English sentences.
- Screenshots: `~/github/scopes/spoiler-shield/shots.mjs` (also checks for leaked text in every response).
