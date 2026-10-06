<?php

declare(strict_types=1);

/*
 * =========================================================
 * VRT GENERIC NOW PLAYING
 * =========================================================
 *
 * Eén endpoint voor VRT-radio:
 *
 * - programma + fallback-track via VRT MAX GraphQL;
 * - actuele track primair via ICY metadata uit de officiële
 *   VRT MP3-stream zelf.
 *
 * De frontend stuurt twee waarden mee:
 *
 *   ?page=/kanalen/studio-brussel
 *   &stream=https://icecast.vrtcdn.be/stubru-high.mp3
 *
 * Dit endpoint is GEEN algemene proxy:
 *
 * - page moet /radio1, /radio2 of /kanalen/<slug> zijn;
 * - stream moet HTTPS gebruiken;
 * - host moet exact icecast.vrtcdn.be zijn;
 * - stream moet een .mp3-pad zijn;
 * - de response bevat alleen metadata, nooit audiobytes.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    outputJson(['error' => 'method_not_allowed']);
}

$pageId = normalizePageId((string) ($_GET['page'] ?? ''));
$streamUrl = trim((string) ($_GET['stream'] ?? ''));

if ($pageId === '') {
    http_response_code(400);
    outputJson(['error' => 'missing_or_invalid_page']);
}

if (!isAllowedVrtStream($streamUrl)) {
    http_response_code(400);
    outputJson(['error' => 'missing_or_invalid_stream']);
}

$graph = fetchVrtGraphql($pageId);
$icyTitle = fetchIcyStreamTitle($streamUrl);

$track = null;
$trackSource = null;

if ($icyTitle !== null && $icyTitle !== '') {
    $track = splitTrack($icyTitle);

    if ($track !== null) {
        $trackSource = 'icy';
    }
}

/*
 * Fallback: VRT zet bij sommige kanalen op sommige momenten
 * ook "Artist - Title" in heading.description.
 */
if ($track === null) {
    $description = cleanText((string) ($graph['description'] ?? ''));

    if ($description !== '') {
        $track = splitTrack($description);

        if ($track !== null) {
            $trackSource = 'graphql';
        }
    }
}

/*
 * Beide bronnen onbereikbaar: echte upstream-fout.
 * Als ten minste één bron bereikbaar is, geven we een normale
 * noTrack-response terug in plaats van een HTTP-fout.
 */
if ($graph === null && $icyTitle === null) {
    http_response_code(502);

    outputJson([
        'error' => 'vrt_sources_unavailable',
        'page' => $pageId,
    ]);
}
