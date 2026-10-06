<?php

/*
 * Belgian now-playing endpoint for rc045.nl
 *
 * VRT:
 *   - program + artwork: official VRT MAX GraphQL
 *   - track: server-rendered playlist mirrors, parsed defensively
 *
 * DPG:
 *   - JOE België is served here
 *   - Qmusic België and Willy are fetched directly in radio.html because
 *     those APIs work in the browser but may return HTTP 403 to server IPs.
 *
 * This endpoint is station-allowlisted and is not a generic proxy.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    respond(array('error' => 'method_not_allowed'));
}

$station = isset($_GET['station'])
    ? strtolower(trim((string) $_GET['station']))
    : '';

$debug = isset($_GET['debug']) && $_GET['debug'] === '1';

$stations = array(
    'stubru' => array(
        'kind' => 'vrt-html',
        'page' => '/kanalen/studio-brussel',
        'track_urls' => array(
            'https://nuopstubru.appspot.com/',
            'https://www.nuopstubru.appspot.com/',
            'https://nuopderadio.be/stubru/'
        )
    ),

    'tijdloze' => array(
        'kind' => 'vrt-radiomenu',
        'page' => '/kanalen/de-tijdloze',
        'track_urls' => array(
            'https://radio.menu/stations/stubru-be-studio-brussel-de-tijdloze/playlist/'
        )
    ),

    'mnm' => array(
        'kind' => 'vrt-html',
        'page' => '/kanalen/mnm',
        'track_urls' => array(
            'https://nuopstubru.appspot.com/mnm/',
            'https://www.nuopstubru.appspot.com/mnm/',
            'https://nuopderadio.be/mnm/'
        )
    ),

    'klara' => array(
        'kind' => 'vrt-html',
        'page' => '/kanalen/klara',
        'track_urls' => array(
            'https://nuopstubru.appspot.com/klara/',
            'https://www.nuopstubru.appspot.com/klara/',
            'https://nuopderadio.be/klara/'
        )
    ),

    'vrt1' => array(
        'kind' => 'vrt-html',
        'page' => '/radio1',
        'track_urls' => array(
            'https://nuopstubru.appspot.com/radio1/',
            'https://www.nuopstubru.appspot.com/radio1/',
            'https://nuopderadio.be/radio1/'
        )
    ),

    'radio2limburg' => array(
        'kind' => 'vrt-html',
        'page' => '/radio2',
        'track_urls' => array(
            'https://nuopstubru.appspot.com/radio2/',
            'https://www.nuopstubru.appspot.com/radio2/',
            'https://nuopderadio.be/radio2/'
        )
    ),

    'joebe' => array(
        'kind' => 'dpg',
        'track_url' => 'https://api.joe.be/2.4/tracks/plays?limit=1&next=true',
        'referer' => 'https://joe.be/'
    ),

    /* Kept for manual/debug access; radio.html fetches these two directly. */
    'qmusicbe' => array(
        'kind' => 'dpg',
        'track_url' => 'https://api.qmusic.be/2.4/tracks/plays?limit=1&next=true',
        'referer' => 'https://qmusic.be/'
    ),

    'willy' => array(
        'kind' => 'dpg',
        'track_url' => 'https://api.willy.radio/2.4/tracks/plays?limit=1&next=true',
        'referer' => 'https://willy.radio/'
    )
);

if ($station === '' || !isset($stations[$station])) {
    http_response_code(400);
    respond(array('error' => 'invalid_station'));
}

$config = $stations[$station];

$program = '';
$image = '';
$track = null;
$trackSource = '';
$errors = array();
$attempts = array();

if (strpos($config['kind'], 'vrt-') === 0) {
    $vrt = fetchVrtChannel($config['page']);

    if (isset($vrt['program'])) {
        $program = cleanText($vrt['program']);
    }

    if (isset($vrt['image'])) {
        $image = cleanText($vrt['image']);
    }

    if (isset($vrt['error'])) {
        $errors[] = $vrt['error'];
    }

    if ($config['kind'] === 'vrt-html') {
        $result = fetchFirstPlaylistTrack($config['track_urls'], $attempts);
    } else {
        $result = fetchFirstRadioMenuTrack($config['track_urls'], $attempts);
    }

    if (isset($result['artist'], $result['title'])) {
        $track = $result;
        $trackSource = isset($result['_source']) ? $result['_source'] : 'playlist';
        unset($track['_source']);
    } elseif (isset($result['error'])) {
        $errors[] = $result['error'];
    }

    /*
     * Last fallback: VRT ChannelPage sometimes still contains Artist - Title.
     */
    if ($track === null && isset($vrt['track']) && is_array($vrt['track'])) {
        $track = $vrt['track'];
        $trackSource = 'vrt-graphql';
    }

} else {
    $result = fetchDpgTrack(
        $config['track_url'],
        isset($config['referer']) ? $config['referer'] : ''
    );

    if (isset($result['artist'], $result['title'])) {
        $track = $result;
        $trackSource = 'dpg';
    } elseif (isset($result['error'])) {
        $errors[] = $result['error'];
    }

    if (isset($result['_attempt'])) {
        $attempts[] = $result['_attempt'];
    }
}

$response = array(
    'station' => $station,
    'program' => $program,
    'image' => $image
);

if ($track !== null) {
    $response['artist'] = $track['artist'];
    $response['title'] = $track['title'];
    $response['source'] = $trackSource;
} else {
    $response['noTrack'] = true;
}

if ($debug) {
    $response['debug'] = array(
        'kind' => $config['kind'],
        'errors' => $errors,
        'attempts' => $attempts,
        'phpVersion' => PHP_VERSION
    );
}

respond($response);


/* =========================================================
 * RESPONSE / TEXT HELPERS
 * ========================================================= */

function respond($data)
{
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}


function cleanText($value)
{
    if ($value === null) {
        return '';
    }

    $text = html_entity_decode(
        (string) $value,
        ENT_QUOTES | ENT_HTML5,
        'UTF-8'
    );

    /* Byte-safe: do not let one invalid upstream byte kill the whole parse. */
    $text = preg_replace('/[\x00-\x1F\x7F]/', ' ', $text);
    $text = preg_replace('/\s+/', ' ', $text);

    return trim((string) $text);
}


function splitTrack($value)
{
    $text = cleanText($value);

    if ($text === '') {
        return null;
    }

    /*
     * Common mojibake forms are deliberately included because some older
     * playlist pages do not declare/serve their encoding consistently.
     */
    $separators = array(
        ' – ',
        ' — ',
        ' - ',
        ' â ',
        ' â€” '
    );

    foreach ($separators as $separator) {
        $position = strpos($text, $separator);

        if ($position === false || $position <= 0) {
            continue;
        }

        $artist = cleanText(substr($text, 0, $position));
        $title = cleanText(substr($text, $position + strlen($separator)));

        if ($artist !== '' && $title !== '') {
            return array(
                'artist' => $artist,
                'title' => $title
            );
        }
    }

    return null;
}


function responsePreview($body)
{
    if (!is_string($body) || $body === '') {
        return '';
    }

    $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $body);
    $text = str_ireplace(
        array('</p>', '</li>', '<br>', '<br/>', '<br />'),
        "\n",
        $text
    );
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\r\n?/', "\n", $text);
    $text = preg_replace('/\n{2,}/', "\n", $text);

    return substr(trim((string) $text), 0, 500);
}


/* =========================================================
 * VRT GRAPHQL
 * ========================================================= */

function fetchVrtChannel($pageId)
{
    $query = <<<'GRAPHQL'
query RadioNowPlaying($pageId: ID!) {
  page(id: $pageId) {
    __typename
    ... on ChannelPage {
      brand
      heading {
        __typename
        ... on Banner {
          title
          description
          image { templateUrl }
        }
      }
    }
  }
}
GRAPHQL;

    $payload = json_encode(array(
        'operationName' => 'RadioNowPlaying',
        'query' => $query,
        'variables' => array('pageId' => $pageId)
    ));

    if ($payload === false) {
        return array('error' => 'vrt_payload_failed');
    }

    $request = httpRequest(
        'https://www.vrt.be/vrtnu-api/graphql/public/v1',
        'POST',
        array(
            'Accept: application/json',
            'Content-Type: application/json',
            'X-VRT-CLIENT-NAME: WEB',
            'Origin: https://www.vrt.be',
            'Referer: https://www.vrt.be/'
        ),
        $payload,
        7
    );

    if (!$request['ok']) {
        return array('error' => 'vrt_request_failed_' . $request['status']);
    }

    $data = json_decode($request['body'], true);

    if (!is_array($data) || !empty($data['errors'])) {
        return array('error' => 'vrt_invalid_response');
    }

    $page = isset($data['data']['page']) && is_array($data['data']['page'])
        ? $data['data']['page']
        : array();

    $heading = isset($page['heading']) && is_array($page['heading'])
        ? $page['heading']
        : array();

    $program = isset($heading['title']) ? cleanText($heading['title']) : '';
    $description = isset($heading['description']) ? cleanText($heading['description']) : '';
    $image = isset($heading['image']['templateUrl'])
        ? cleanText($heading['image']['templateUrl'])
        : '';

    return array(
        'program' => $program,
        'image' => $image,
        'track' => splitTrack($description)
    );
}


/* =========================================================
 * VRT SERVER-RENDERED PLAYLIST
 * ========================================================= */

function fetchFirstPlaylistTrack($urls, &$attempts)
{
    $lastError = 'playlist_all_sources_failed';

    foreach ($urls as $url) {
        $request = fetchHtmlPage($url);

        $attempt = array(
            'url' => $url,
            'status' => $request['status'],
            'finalUrl' => $request['final_url'],
            'contentType' => $request['content_type']
        );

        if (!$request['ok'] || $request['body'] === '') {
            $attempt['result'] = 'request_failed';
            $attempt['preview'] = responsePreview($request['body']);
            $attempts[] = $attempt;
            $lastError = 'playlist_request_failed_' . $request['status'];
            continue;
        }

        $parsed = parseVrtPlaylistHtml($request['body']);

        if (isset($parsed['artist'], $parsed['title'])) {
            $attempt['result'] = 'track';
            $attempts[] = $attempt;
            $parsed['_source'] = $url;
            return $parsed;
        }

        $attempt['result'] = isset($parsed['error']) ? $parsed['error'] : 'parse_failed';
        $attempt['preview'] = responsePreview($request['body']);
        $attempts[] = $attempt;

        if (isset($parsed['error'])) {
            $lastError = $parsed['error'];
        }
    }

    return array('error' => $lastError);
}


function parseVrtPlaylistHtml($html)
{
    /*
     * Fast path: first time <p> followed by the current item <p>.
     * No /u modifier: upstream encoding must not invalidate the whole regex.
     */
    if (preg_match(
        '#<p\b[^>]*>\s*(\d{1,2}:\d{2})\s*</p>\s*<p\b[^>]*>(.*?)</p>#is',
        $html,
        $match
    )) {
        $candidate = cleanText(strip_tags($match[2]));
        $track = splitTrack($candidate);

        if ($track !== null) {
            return $track;
        }

        /*
         * If the current row is clearly news/non-music, do not lie by showing
         * the previous song as current.
         */
        if ($candidate !== '') {
            return array('error' => 'playlist_current_item_not_track');
        }
    }

    /*
     * DOM path: handles extra elements/attributes between the <p> nodes.
     */
    if (class_exists('DOMDocument')) {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded) {
            $xpath = new DOMXPath($dom);
            $nodes = $xpath->query('//main//p | //body//p');

            if ($nodes) {
                for ($i = 0; $i < $nodes->length; $i++) {
                    $time = cleanText($nodes->item($i)->textContent);

                    if (!preg_match('/^\d{1,2}:\d{2}$/', $time)) {
                        continue;
                    }

                    for ($j = $i + 1; $j < min($nodes->length, $i + 4); $j++) {
                        $candidate = cleanText($nodes->item($j)->textContent);

                        if ($candidate === '' || stripos($candidate, 'Album:') === 0) {
                            continue;
                        }

                        $track = splitTrack($candidate);

                        if ($track !== null) {
                            return $track;
                        }

                        return array('error' => 'playlist_current_item_not_track');
                    }
                }
            }
        }
    }

    /*
     * Last-resort text parser. This intentionally ignores HTML structure.
     */
    $text = str_ireplace(
        array('</p>', '</li>', '<br>', '<br/>', '<br />'),
        "\n",
        $html
    );

    $text = html_entity_decode(
        strip_tags($text),
        ENT_QUOTES | ENT_HTML5,
        'UTF-8'
    );

    $lines = preg_split(
        '/\r?\n/',
        $text
    );

    $cleanLines = array();

    foreach ($lines as $line) {
        $line = cleanText($line);

        if ($line !== '') {
            $cleanLines[] = $line;
        }
    }

    for ($i = 0; $i < count($cleanLines); $i++) {
        if (!preg_match('/^\d{1,2}:\d{2}$/', $cleanLines[$i])) {
            continue;
        }

        $parts = array();

        for ($j = $i + 1; $j < min(count($cleanLines), $i + 7); $j++) {
            $line = $cleanLines[$j];

            if (
                preg_match('/^\d{1,2}:\d{2}$/', $line) ||
                stripos($line, 'Album:') === 0 ||
                strcasecmp($line, 'Meer...') === 0
            ) {
                break;
            }

            $parts[] = $line;

            $track = splitTrack(
                implode(
                    ' ',
                    $parts
                )
            );

            if ($track !== null) {
                return $track;
            }
        }

        if (!empty($parts)) {
            return array(
                'error' => 'playlist_current_item_not_track'
            );
        }
    }

    return array(
        'error' => 'playlist_current_item_missing'
    );
}


/* =========================================================
 * DE TIJDLOZE / RADIO.MENU
 * ========================================================= */

function fetchFirstRadioMenuTrack($urls, &$attempts)
{
    $lastError = 'radiomenu_all_sources_failed';

    foreach ($urls as $url) {
        $request = fetchHtmlPage($url);

        $attempt = array(
            'url' => $url,
            'status' => $request['status'],
            'finalUrl' => $request['final_url'],
            'contentType' => $request['content_type']
        );

        if (!$request['ok'] || $request['body'] === '') {
            $attempt['result'] = 'request_failed';
            $attempt['preview'] = responsePreview($request['body']);
            $attempts[] = $attempt;
            $lastError = 'radiomenu_request_failed_' . $request['status'];
            continue;
        }

        $parsed = parseRadioMenuHtml($request['body']);

        if (isset($parsed['artist'], $parsed['title'])) {
            $attempt['result'] = 'track';
            $attempts[] = $attempt;
            $parsed['_source'] = $url;
            return $parsed;
        }

        $attempt['result'] = isset($parsed['error']) ? $parsed['error'] : 'parse_failed';
        $attempt['preview'] = responsePreview($request['body']);
        $attempts[] = $attempt;

        if (isset($parsed['error'])) {
            $lastError = $parsed['error'];
        }
    }

    return array(
        'error' => $lastError
    );
}


function parseRadioMenuHtml($html)
{
    if (
        preg_match_all(
            '#<li\b[^>]*>(.*?)</li>#is',
            $html,
            $matches
        )
    ) {
        $limit = min(
            12,
            count($matches[1])
        );

        for ($i = 0; $i < $limit; $i++) {
            $candidate = preg_replace(
                '#<time\b[^>]*>.*?</time>#is',
                '',
                $matches[1][$i]
            );

            $candidate = cleanText(
                strip_tags($candidate)
            );

            if (
                $candidate === '' ||
                preg_match(
                    '/^(De Tijdloze|VRT Studio Brussel De Tijdloze)$/i',
                    $candidate
                )
            ) {
                continue;
            }

            $track = splitTrack($candidate);

            if ($track !== null) {
                return $track;
            }
        }
    }

    /*
     * Text fallback.
     */
    $text = str_ireplace(
        array(
            '</li>',
            '<br>',
            '<br/>',
            '<br />'
        ),
        "\n",
        $html
    );

    $text = html_entity_decode(
        strip_tags($text),
        ENT_QUOTES | ENT_HTML5,
        'UTF-8'
    );

    $lines = preg_split(
        '/\r?\n/',
        $text
    );

    $limit = 0;

    foreach ($lines as $line) {
        $line = cleanText($line);

        if ($line === '') {
            continue;
        }

        $line = preg_replace(
            '/^\d{1,2}:\d{2}\s*/',
            '',
            $line
        );

        $line = cleanText($line);

        if (
            $line === '' ||
            preg_match(
                '/^(De Tijdloze|VRT Studio Brussel De Tijdloze)$/i',
                $line
            )
        ) {
            continue;
        }

        $track = splitTrack($line);

        if ($track !== null) {
            return $track;
        }

        $limit++;

        if ($limit >= 20) {
            break;
        }
    }

    return array(
        'error' => 'radiomenu_current_track_missing'
    );
}


/* =========================================================
 * DPG
 * ========================================================= */

function fetchDpgTrack($url, $referer)
{
    $origin = '';

    if ($referer !== '') {
        $parts = parse_url($referer);

        if (
            isset($parts['scheme']) &&
            isset($parts['host'])
        ) {
            $origin =
                $parts['scheme'] .
                '://' .
                $parts['host'];
        }
    }

    $headers = array(
        'Accept: application/json, text/plain, */*',
        'Accept-Language: nl-NL,nl;q=0.9,en;q=0.8'
    );

    if ($origin !== '') {
        $headers[] =
            'Origin: ' .
            $origin;
    }

    if ($referer !== '') {
        $headers[] =
            'Referer: ' .
            $referer;
    }

    $request = httpRequest(
        $url,
        'GET',
        $headers,
        null,
        7
    );

    $attempt = array(
        'url' => $url,
        'status' => $request['status'],
        'finalUrl' => $request['final_url'],
        'contentType' => $request['content_type'],
        'preview' => responsePreview($request['body'])
    );

    if (!$request['ok']) {
        return array(
            'error' => 'dpg_request_failed_' . $request['status'],
            '_attempt' => $attempt
        );
    }

    $data = json_decode(
        $request['body'],
        true
    );

    $item =
        isset($data['played_tracks'][0]) &&
        is_array($data['played_tracks'][0])
            ? $data['played_tracks'][0]
            : null;

    if ($item === null) {
        return array(
            'error' => 'dpg_track_missing',
            '_attempt' => $attempt
        );
    }

    $artist =
        isset($item['artist']['name'])
            ? cleanText(
                $item['artist']['name']
            )
            : '';

    $title =
        isset($item['title'])
            ? cleanText(
                $item['title']
            )
            : '';

    if (
        $artist === '' ||
        $title === ''
    ) {
        return array(
            'error' => 'dpg_track_invalid',
            '_attempt' => $attempt
        );
    }

    return array(
        'artist' => $artist,
        'title' => $title,
        '_attempt' => $attempt
    );
}


/* =========================================================
 * HTTP
 * ========================================================= */

function fetchHtmlPage($url)
{
    return httpRequest(
        $url,
        'GET',
        array(
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language: nl-NL,nl;q=0.9,en-US;q=0.8,en;q=0.7',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
            'Upgrade-Insecure-Requests: 1'
        ),
        null,
        8
    );
}


function httpRequest($url, $method, $headers, $body, $timeout)
{
    $userAgent =
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) ' .
        'AppleWebKit/537.36 (KHTML, like Gecko) ' .
        'Chrome/154.0.0.0 Safari/537.36';

    if (
        function_exists(
            'curl_init'
        )
    ) {
        $ch =
            curl_init(
                $url
            );

        if ($ch === false) {
            return array(
                'ok' => false,
                'body' => '',
                'status' => 0,
                'final_url' => '',
                'content_type' => ''
            );
        }

        $options = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        );

        if ($method === 'POST') {
            $options[
                CURLOPT_POST
            ] = true;

            $options[
                CURLOPT_POSTFIELDS
            ] = $body;
        }

        curl_setopt_array(
            $ch,
            $options
        );

        $response =
            curl_exec(
                $ch
            );

        $status =
            (int)
            curl_getinfo(
                $ch,
                CURLINFO_HTTP_CODE
            );

        $finalUrl =
            (string)
            curl_getinfo(
                $ch,
                CURLINFO_EFFECTIVE_URL
            );

        $contentType =
            (string)
            curl_getinfo(
                $ch,
                CURLINFO_CONTENT_TYPE
            );

        $errno =
            curl_errno(
                $ch
            );

        curl_close(
            $ch
        );

        return array(
            'ok' =>
                $response !== false &&
                $errno === 0 &&
                $status >= 200 &&
                $status < 300,

            'body' =>
                $response === false
                    ? ''
                    : $response,

            'status' =>
                $status,

            'final_url' =>
                $finalUrl,

            'content_type' =>
                $contentType
        );
    }

    $headerText =
        implode(
            "\r\n",
            $headers
        ) .
        "\r\nUser-Agent: " .
        $userAgent;

    $contextOptions = array(
        'http' => array(
            'method' => $method,
            'header' => $headerText,
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 1,
            'max_redirects' => 5
        )
    );

    if ($method === 'POST') {
        $contextOptions[
            'http'
        ][
            'content'
        ] = $body;
    }

    $context =
        stream_context_create(
            $contextOptions
        );

    $response =
        @file_get_contents(
            $url,
            false,
            $context
        );

    $status = 0;
    $contentType = '';

    if (
        isset(
            $http_response_header
        ) &&
        is_array(
            $http_response_header
        )
    ) {
        foreach (
            $http_response_header
            as $headerLine
        ) {
            if (
                preg_match(
                    '#^HTTP/\S+\s+(\d{3})#',
                    $headerLine,
                    $match
                )
            ) {
                $status =
                    (int)
                    $match[1];
            }

            if (
                stripos(
                    $headerLine,
                    'Content-Type:'
                ) === 0
            ) {
                $contentType =
                    trim(
                        substr(
                            $headerLine,
                            strlen(
                                'Content-Type:'
                            )
                        )
                    );
            }
        }
    }

    return array(
        'ok' =>
            $response !== false &&
            $status >= 200 &&
            $status < 300,

        'body' =>
            $response === false
                ? ''
                : $response,

        'status' =>
            $status,

        'final_url' =>
            $url,

        'content_type' =>
            $contentType
    );
}
