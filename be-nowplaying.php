<?php

/*
 * Belgian now-playing endpoint for rc045.nl
 *
 * One same-origin endpoint for all Belgian stations in radio.html.
 *
 * VRT stations:
 *   - program/artwork: official VRT MAX GraphQL API
 *   - track: server-rendered VRT playlist mirror (nuopstubru.appspot.com)
 *   - De Tijdloze: radio.menu playlist (no App Engine page available)
 *
 * DPG stations:
 *   - Qmusic BE / Willy / JOE BE: official DPG track APIs
 *
 * The endpoint is allowlisted by station id and is not a general proxy.
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

$station = isset($_GET['station']) ? strtolower(trim((string) $_GET['station'])) : '';
$debug = isset($_GET['debug']) && $_GET['debug'] === '1';

$stations = array(
    'stubru' => array(
        'kind' => 'vrt-html',
        'page' => '/kanalen/studio-brussel',
        'track_url' => 'https://nuopstubru.appspot.com/'
    ),
    'tijdloze' => array(
        'kind' => 'vrt-radiomenu',
        'page' => '/kanalen/de-tijdloze',
        'track_url' => 'https://radio.menu/stations/stubru-be-studio-brussel-de-tijdloze/playlist/'
    ),
    'mnm' => array(
        'kind' => 'vrt-html',
        'page' => '/kanalen/mnm',
        'track_url' => 'https://nuopstubru.appspot.com/mnm/'
    ),
    'qmusicbe' => array(
        'kind' => 'dpg',
        'track_url' => 'https://api.qmusic.be/2.4/tracks/plays?limit=1&next=true'
    ),
    'willy' => array(
        'kind' => 'dpg',
        'track_url' => 'https://api.willy.radio/2.4/tracks/plays?limit=1&next=true'
    ),
    'klara' => array(
        'kind' => 'vrt-html',
        'page' => '/kanalen/klara',
        'track_url' => 'https://nuopstubru.appspot.com/klara/'
    ),
    'vrt1' => array(
        'kind' => 'vrt-html',
        'page' => '/radio1',
        'track_url' => 'https://nuopstubru.appspot.com/radio1/'
    ),
    'radio2limburg' => array(
        'kind' => 'vrt-html',
        'page' => '/radio2',
        'track_url' => 'https://nuopstubru.appspot.com/radio2/'
    ),
    'joebe' => array(
        'kind' => 'dpg',
        'track_url' => 'https://api.joe.be/2.4/tracks/plays?limit=1&next=true'
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
        $result = fetchServerRenderedPlaylistTrack($config['track_url']);
    } else {
        $result = fetchRadioMenuTrack($config['track_url']);
    }

    if (isset($result['artist'], $result['title'])) {
        $track = $result;
        $trackSource = $config['kind'] === 'vrt-html' ? 'vrt-playlist' : 'radiomenu';
    } elseif (isset($result['error'])) {
        $errors[] = $result['error'];
    }

    /* Last fallback: some VRT ChannelPage responses still expose Artist - Title. */
    if ($track === null && isset($vrt['track']) && is_array($vrt['track'])) {
        $track = $vrt['track'];
        $trackSource = 'vrt-graphql';
    }
} else {
    $result = fetchDpgTrack($config['track_url']);

    if (isset($result['artist'], $result['title'])) {
        $track = $result;
        $trackSource = 'dpg';
    } elseif (isset($result['error'])) {
        $errors[] = $result['error'];
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
        'trackUrl' => $config['track_url'],
        'errors' => $errors,
        'phpVersion' => PHP_VERSION
    );
}

respond($response);


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

    $text = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text);
    $text = preg_replace('/\s+/u', ' ', $text);

    return trim((string) $text);
}


function splitTrack($value)
{
    $text = cleanText($value);

    if ($text === '') {
        return null;
    }

    foreach (array(' – ', ' — ', ' - ') as $separator) {
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
            'X-VRT-CLIENT-NAME: WEB'
        ),
        $payload,
        6
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


function fetchServerRenderedPlaylistTrack($url)
{
    $request = httpRequest(
        $url,
        'GET',
        array(
            'Accept: text/html,application/xhtml+xml',
            'Accept-Language: nl-NL,nl;q=0.9,en;q=0.8'
        ),
        null,
        6
    );

    if (!$request['ok'] || $request['body'] === '') {
        return array('error' => 'playlist_request_failed_' . $request['status']);
    }

    $html = $request['body'];

    /*
     * App Engine emits repeated pairs:
     * <p>14:32</p>
     * <p>Artist – Title</p>
     *
     * Parse the first pair only. If that row is news/non-music we deliberately
     * return no track instead of pretending the previous song is current.
     */
    if (preg_match(
        '#<p[^>]*>\s*(\d{1,2}:\d{2})\s*</p>\s*<p[^>]*>(.*?)</p>#isu',
        $html,
        $match
    )) {
        $candidate = cleanText(strip_tags($match[2]));
        $track = splitTrack($candidate);

        if ($track !== null) {
            return $track;
        }

        return array('error' => 'playlist_current_item_not_track');
    }

    /* DOM fallback in case markup whitespace changes. */
    if (class_exists('DOMDocument')) {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded) {
            $nodes = $dom->getElementsByTagName('p');

            for ($i = 0; $i < $nodes->length - 1; $i++) {
                $time = cleanText($nodes->item($i)->textContent);

                if (!preg_match('/^\d{1,2}:\d{2}$/', $time)) {
                    continue;
                }

                $candidate = cleanText($nodes->item($i + 1)->textContent);
                $track = splitTrack($candidate);

                if ($track !== null) {
                    return $track;
                }

                return array('error' => 'playlist_current_item_not_track');
            }
        }
    }

    return array('error' => 'playlist_current_item_missing');
}


function fetchRadioMenuTrack($url)
{
    $request = httpRequest(
        $url,
        'GET',
        array(
            'Accept: text/html,application/xhtml+xml',
            'Accept-Language: en-US,en;q=0.9,nl;q=0.8'
        ),
        null,
        6
    );

    if (!$request['ok'] || $request['body'] === '') {
        return array('error' => 'radiomenu_request_failed_' . $request['status']);
    }

    $html = $request['body'];

    if (preg_match_all('#<li[^>]*>(.*?)</li>#isu', $html, $matches)) {
        $limit = min(8, count($matches[1]));

        for ($i = 0; $i < $limit; $i++) {
            $candidate = preg_replace('#<time[^>]*>.*?</time>#isu', '', $matches[1][$i]);
            $candidate = cleanText(strip_tags($candidate));

            if ($candidate === '' || preg_match('/^(De Tijdloze|VRT Studio Brussel De Tijdloze)$/i', $candidate)) {
                continue;
            }

            $track = splitTrack($candidate);

            if ($track !== null) {
                return $track;
            }
        }
    }

    return array('error' => 'radiomenu_current_track_missing');
}


function fetchDpgTrack($url)
{
    $request = httpRequest(
        $url,
        'GET',
        array('Accept: application/json'),
        null,
        6
    );

    if (!$request['ok']) {
        return array('error' => 'dpg_request_failed_' . $request['status']);
    }

    $data = json_decode($request['body'], true);
    $track = isset($data['played_tracks'][0]) && is_array($data['played_tracks'][0])
        ? $data['played_tracks'][0]
        : null;

    if ($track === null) {
        return array('error' => 'dpg_track_missing');
    }

    $artist = isset($track['artist']['name']) ? cleanText($track['artist']['name']) : '';
    $title = isset($track['title']) ? cleanText($track['title']) : '';

    if ($artist === '' || $title === '') {
        return array('error' => 'dpg_track_invalid');
    }

    /*
     * DPG's latest played track is authoritative enough for display. Do not use
     * browser-local clock comparisons here: they were the source of false negatives.
     */
    return array(
        'artist' => $artist,
        'title' => $title
    );
}


function httpRequest($url, $method, $headers, $body, $timeout)
{
    $userAgent = 'Mozilla/5.0 (compatible; rc045-radio/1.0; +https://rc045.nl/radio.html)';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);

        if ($ch === false) {
            return array('ok' => false, 'body' => '', 'status' => 0);
        }

        $options = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_ENCODING => ''
        );

        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);

        return array(
            'ok' => $response !== false && $errno === 0 && $status >= 200 && $status < 300,
            'body' => $response === false ? '' : $response,
            'status' => $status
        );
    }

    $headerText = implode("\r\n", $headers) . "\r\nUser-Agent: " . $userAgent;
    $contextOptions = array(
        'http' => array(
            'method' => $method,
            'header' => $headerText,
            'timeout' => $timeout,
            'ignore_errors' => true
        )
    );

    if ($method === 'POST') {
        $contextOptions['http']['content'] = $body;
    }

    $context = stream_context_create($contextOptions);
    $response = @file_get_contents($url, false, $context);
    $status = 0;

    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $headerLine) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $headerLine, $match)) {
                $status = (int) $match[1];
                break;
            }
        }
    }

    return array(
        'ok' => $response !== false && $status >= 200 && $status < 300,
        'body' => $response === false ? '' : $response,
        'status' => $status
    );
}
