<?php

namespace ErnestDefoe\SpoilerShield\Http;

use ErnestDefoe\SpoilerShield\Integrations;
use ErnestDefoe\SpoilerShield\Redactor;
use ErnestDefoe\SpoilerShield\Search\HiddenFromSearch;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\StreamFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Every API response with a discussion or a post in it passes through the
 * Redactor on its way out. Flarum's internal API client runs this stack too,
 * so the forum's own page (its title, the preloaded data, the no-JS content)
 * and realtime pushes are covered by the same check.
 *
 * Other responses pass untouched: a cheap look at the body first.
 */
class RedactApi implements MiddlewareInterface
{
    public function __construct(
        private readonly Redactor $redactor,
        private readonly Integrations $integrations,
        private readonly LoggerInterface $log,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // A word search leaves out, inside the query, what the searcher may not read.
        $response = $this->searching($request)
            ? HiddenFromSearch::during(RequestUtil::getActor($request), $request->getAttribute('session'), fn () => $handler->handle($request))
            : $handler->handle($request);

        if (! str_contains($response->getHeaderLine('Content-Type'), 'json')) {
            return $response;
        }

        $body = (string) $response->getBody();

        // Anthology's series page lists its chapters (discussions) in plain JSON.
        $series = $request->getAttribute('routeName') === 'anthology.series.show';

        // Panels' own pages name chapters, whose titles are in their discussions' titles.
        $panels = in_array($request->getAttribute('routeName'), ['panels.series.show', 'panels.read.chapter', 'panels.feed'], true)
            && $this->integrations->panels();

        $jsonApi = (bool) preg_match('/"type"\s*:\s*"(discussions|posts)"/', $body);
        // Other extensions' JSON (a widget's list of discussions) links to /d/{id}, escaped or not.
        $links = str_contains($body, '/d/') || str_contains($body, '\/d\/')
            || (bool) preg_match('/"discussion"\s*:\s*\{|"discussions"\s*:\s*\[|"discussionTitle"/', $body);

        if (! $series && ! $panels && ! $jsonApi && ! $links) {
            return $response;
        }

        $doc = json_decode($body, true);

        if (! is_array($doc)) {
            return $response;
        }

        $before = $doc;

        try {
            $actor = RequestUtil::getActor($request);
            $session = $request->getAttribute('session');
            if ($series) {
                $doc = $this->redactor->redactChapters($doc, $actor, $session);
            } elseif ($panels) {
                $doc = $this->redactor->redactPanels($doc, $actor, $session, $request->getAttribute('routeName') === 'panels.read.chapter');
            } elseif ($jsonApi && array_key_exists('data', $doc)) {
                $doc = $this->redactor->redact($doc, $actor, $session, $this->searching($request));
            }
            if ($links) {
                $doc = $this->redactor->redactLinks($doc, $actor, $session);
            }
        } catch (Throwable $e) {
            // Fail closed: a broken check hides every title and post rather than showing one.
            $this->log->error('[spoiler-shield] '.$e->getMessage(), ['exception' => $e]);
            $doc = $panels ? $this->redactor->blankPanels($doc) : $this->redactor->redactAll($doc);
        }

        // Nothing gated: the response leaves exactly as it came (empty JSON objects stay objects).
        if ($doc === $before) {
            return $response->withBody((new StreamFactory())->createStream($body));
        }

        return $response
            ->withBody((new StreamFactory())->createStream((string) json_encode($doc, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES)))
            ->withoutHeader('Content-Length');
    }

    /** A search by words: the API's own `filter[q]`, which every search driver answers. */
    private function searching(ServerRequestInterface $request): bool
    {
        $filter = $request->getQueryParams()['filter'] ?? null;

        return is_array($filter) && trim((string) ($filter['q'] ?? '')) !== '';
    }
}
