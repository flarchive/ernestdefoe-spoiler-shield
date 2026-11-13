<?php

namespace ErnestDefoe\SpoilerShield\Api;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ServerRequestInterface;

/** Reading what the browser sends, and errors in Flarum's shape (the browser turns each code into a sentence). */
final class Json
{
    /** @return array<string, mixed> */
    public static function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    public static function param(ServerRequestInterface $request, string $key): string
    {
        return (string) ($request->getAttribute('routeParameters')[$key] ?? '');
    }

    public static function error(int $status, string $code): JsonResponse
    {
        return new JsonResponse(['errors' => [['status' => (string) $status, 'code' => $code]]], $status);
    }

    /** One line of plain text: control characters out, single spaces, trimmed, cut to length. */
    public static function line(mixed $text, int $max): string
    {
        $text = (string) preg_replace('/[^\P{C}]/u', ' ', is_string($text) ? $text : '');

        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $text)), 0, $max);
    }
}
