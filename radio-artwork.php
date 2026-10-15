<?php
/**
 * Optional album/artist artwork for the radio player.
 * Uses existing artist/title; never performs track recognition.
 *
 * MusicBrainz recording search -> Cover Art Archive front image.
 * When no reliable release has cover art, use a freely licensed Commons
 * artist photograph exposed by Wikipedia's pageimages API.
 * All upstream JSON is queried on the server, not in radio.html.
 *
 * The images themselves remain at their original archive hosts.
 * We intentionally do not copy copyrighted album art to our hosting.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function outputArtwork($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    outputArtwork(array('image' => null), 405);
}

function cleanArtworkInput($value, $limit) {
    if (!is_string($value)) return '';
    $value = trim((string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $value));
    $value = trim((string) preg_replace('/\s+/u', ' ', $value));
    if (strlen($value) > $limit * 4) return '';
    return $value;
}

$artist = cleanArtworkInput($_GET['artist'] ?? '', 120);
$title = cleanArtworkInput($_GET['title'] ?? '', 180);
if ($artist === '' || $title === '' || strlen($artist) < 2 || strlen($title) < 2) {
    outputArtwork(array('image' => null));
}

function normalArtworkName($value) {
    $value = trim((string) $value);
    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($ascii !== false) $value = $ascii;
    }
    return strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '', $value));
}

function matchArtworkName($a, $b) {
    $left = normalArtworkName($a);
    $right = normalArtworkName($b);
    return $left !== '' && $right !== '' &&
        ($left === $right || (strlen($left) > 5 && strlen($right) > 5 &&
            (strpos($left, $right) !== false || strpos($right, $left) !== false)));
}

function jsonArtworkRequest($url, $timeout = 4) {
    if (!function_exists('curl_init')) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => array('Accept: application/json'),
        CURLOPT_USERAGENT => 'RC045-radio/1.0 (https://rc045.nl/radio.html; contact: webmaster@rc045.nl)'
    ));
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false || $code !== 200 || strlen((string)$body) > 800000) {
        return null;
    }
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

function availableCoverArtwork($groupId) {
    if (!preg_match('/^[a-f0-9-]{36}$/i', $groupId) || !function_exists('curl_init')) {
        return false;
    }
    $ch = curl_init('https://coverartarchive.org/release-group/' . $groupId . '/front-250');
    curl_setopt_array($ch, array(
        CURLOPT_NOBODY => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'RC045-radio/1.0 (https://rc045.nl/radio.html; contact: webmaster@rc045.nl)'
    ));
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return in_array($status, array(200, 301, 302, 303, 307, 308), true);
}

/** Respect MusicBrainz shared IP limit even under concurrent visitors. */
function paceArtworkMusicBrainz($cacheDir) {
    if (!is_dir($cacheDir)) return;
    $handle = @fopen($cacheDir . '/musicbrainz-rate.lock', 'c+');
    if (!$handle) return;
    if (flock($handle, LOCK_EX)) {
        rewind($handle);
        $previous = (float) stream_get_contents($handle);
        $wait = (1.15 - (microtime(true) - $previous));
        if ($wait > 0 && $wait < 3) usleep((int)($wait * 1000000));
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string)microtime(true));
        fflush($handle);
        flock($handle, LOCK_UN);
    }
    fclose($handle);
}

require_once __DIR__ . '/radio-artwork-release-select.php';

function findAlbumArtwork($artist, $title, $cacheDir) {
    $search = 'recording:"' . str_replace(array('"', '\\'), ' ', $title) .
        '" AND artist:"' . str_replace(array('"', '\\'), ' ', $artist) . '"';
    paceArtworkMusicBrainz($cacheDir);
    $url = 'https://musicbrainz.org/ws/2/recording/?' . http_build_query(array(
        'query' => $search, 'fmt' => 'json', 'limit' => 15
    ), '', '&', PHP_QUERY_RFC3986);
    $data = jsonArtworkRequest($url, 5);
    if (!isset($data['recordings']) || !is_array($data['recordings'])) {
        return null;
    }

    // Eerst rangschikken: oorspronkelijke artiestrelease vóór losse compilaties.
    $groups = artworkReleaseCandidates($data['recordings'], $artist, $title, 'matchArtworkName');

    // A release group can have no approved cover; test before showing it.
    foreach ($groups as $id) {
        if (availableCoverArtwork($id)) {
            return array(
                'image' => 'https://coverartarchive.org/release-group/' . $id . '/front-250',
                'kind' => 'album',
                'creditUrl' => 'https://musicbrainz.org/release-group/' . $id,
                'creditLabel' => 'Cover Art Archive'
            );
        }
    }
    return null;
}

/** Artist photo only if Wikipedia resolves to the given artist and Commons
 * reports a freely licensed file. Avoid Wikipedia's non-free local images. */
function findArtistArtwork($artist) {
    $url = 'https://en.wikipedia.org/w/api.php?' . http_build_query(array(
        'action' => 'query',
        'format' => 'json',
        'formatversion' => 2,
        'redirects' => 1,
        'titles' => $artist,
        'prop' => 'pageimages',
        'piprop' => 'thumbnail',
        'pithumbsize' => 500
    ), '', '&', PHP_QUERY_RFC3986);
    $data = jsonArtworkRequest($url, 4);
    $page = $data['query']['pages'][0] ?? array();
    $src = $page['thumbnail']['source'] ?? '';
    if (!matchArtworkName($page['title'] ?? '', $artist) ||
        !is_string($src) ||
        strpos($src, 'https://upload.wikimedia.org/wikipedia/commons/') !== 0) {
        return null;
    }
    $path = parse_url($src, PHP_URL_PATH);
    $parts = explode('/', (string)$path);
    $thumb = array_search('thumb', $parts, true);
    if ($thumb === false || count($parts) <= $thumb + 3) return null;
    $filename = rawurldecode($parts[$thumb + 3]);
    if (!preg_match('/\.(?:png|jpe?g|webp)$/i', $filename)) return null;

    $infoUrl = 'https://commons.wikimedia.org/w/api.php?' . http_build_query(array(
        'action' => 'query',
        'format' => 'json',
        'formatversion' => 2,
        'titles' => 'File:' . $filename,
        'prop' => 'imageinfo',
        'iiprop' => 'extmetadata|url'
    ), '', '&', PHP_QUERY_RFC3986);
    $imageData = jsonArtworkRequest($infoUrl, 4);
    $imageInfo = $imageData['query']['pages'][0]['imageinfo'][0] ?? array();
    $licenseName = trim(html_entity_decode(strip_tags((string)(
        $imageInfo['extmetadata']['LicenseShortName']['value'] ?? ''
    )), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $license = strtolower($licenseName);
    if (!preg_match('/^(?:cc ?(?:by|0)|public domain|pdm)/i', $license)) return null;
    $rawArtist = $imageInfo['extmetadata']['Artist']['value'] ??
        ($imageInfo['extmetadata']['Attribution']['value'] ?? '');
    $photoAuthor = trim((string) preg_replace('/\\s+/', ' ',
        html_entity_decode(strip_tags((string)$rawArtist), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    // Bij CC BY-varianten kan verplichte auteursvermelding niet worden weggelaten.
    if (strpos($license, 'cc by') === 0 && $photoAuthor === '') return null;
    $photoAuthor = substr($photoAuthor, 0, 65);
    $description = $imageInfo['descriptionurl'] ?? '';
    if (!is_string($description) ||
        strpos($description, 'https://commons.wikimedia.org/wiki/File:') !== 0) {
        return null;
    }
    return array(
        'image' => $src,
        'kind' => 'artist',
        'creditUrl' => $description,
        'creditLabel' => 'Foto: ' . ($photoAuthor !== '' ? $photoAuthor : 'Wikimedia Commons') .
            ' · ' . $licenseName
    );
}

// Server-side cache: successful results seven days; misses six hours.
$dir = rtrim(sys_get_temp_dir(), '/') . '/rc045-radio-artwork-v2';
if (!is_dir($dir)) @mkdir($dir, 0700, true);
$hash = hash('sha256', normalArtworkName($artist) . '|' . normalArtworkName($title));
$file = $dir . '/' . $hash . '.json';
$lock = @fopen($file . '.lock', 'c');
if ($lock) flock($lock, LOCK_EX);
if (is_file($file)) {
    $cached = json_decode((string)@file_get_contents($file), true);
    if (is_array($cached) && (int)($cached['expires'] ?? 0) > time()) {
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
        outputArtwork($cached['result']);
    }
}
$result = findAlbumArtwork($artist, $title, $dir);
if ($result === null) $result = findArtistArtwork($artist);
if ($result === null) $result = array('image' => null);
$ttl = empty($result['image']) ? 6 * 3600 : 7 * 86400;
if (is_dir($dir)) {
    $payload = json_encode(array('expires' => time() + $ttl, 'result' => $result));
    $temp = @tempnam($dir, 'art-');
    if ($temp && @file_put_contents($temp, $payload, LOCK_EX) !== false) {
        @rename($temp, $file);
    } elseif ($temp) {
        @unlink($temp);
    }
}
if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
outputArtwork($result);
