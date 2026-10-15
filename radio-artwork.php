<?php
/**
 * Optional album/artist artwork for the radio player.
 * Uses existing artist/title; never performs track recognition.
 *
 * Bounded MusicBrainz recording/release relations -> scored Cover Art Archive front.
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
    // Bounded counters make live audit performance measurable without API keys.
    header('X-Radio-Artwork-MusicBrainz: '.(int)($GLOBALS['artworkCounters']['musicbrainz'] ?? 0));
    header('X-Radio-Artwork-CoverChecks: '.(int)($GLOBALS['artworkCounters']['cover_checks'] ?? 0));
    header('X-Radio-Artwork-Cache: '.(!empty($GLOBALS['artworkCacheHit']) ? 'HIT' : 'MISS'));
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

require_once __DIR__ . '/radio-artwork-release-select.php';

/** Per request work budget; trackinfo and radio playback are independent. */
$GLOBALS['artworkDeadline'] = microtime(true) + 11;
$GLOBALS['artworkTemporaryFailure'] = false;
$GLOBALS['artworkCounters'] = array('musicbrainz'=>0,'cover_checks'=>0);

function artworkWithinBudget($reserve = 0.4) {
    return microtime(true) + $reserve < $GLOBALS['artworkDeadline'];
}
function jsonArtworkRequest($url, $timeout = 3, $musicbrainz = false) {
    if (!function_exists('curl_init') || !artworkWithinBudget()) {
        $GLOBALS['artworkTemporaryFailure'] = true;
        return null;
    }
    $available = $GLOBALS['artworkDeadline'] - microtime(true) - 0.2;
    $timeout = max(1, min((float)$timeout,$available));
    if ($musicbrainz) ++$GLOBALS['artworkCounters']['musicbrainz'];
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => min(1.5, $timeout),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => array('Accept: application/json'),
        CURLOPT_USERAGENT => 'RC045-radio/2.0 (https://rc045.nl/radio.html; contact: webmaster@rc045.nl)'
    ));
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false || $code === 429 || $code >= 500 || $code === 0) {
        $GLOBALS['artworkTemporaryFailure'] = true;
        return null;
    }
    if ($code !== 200 || strlen((string)$body) > 800000) return null;
    $json = json_decode($body,true);
    if (!is_array($json)) $GLOBALS['artworkTemporaryFailure'] = true;
    return is_array($json) ? $json : null;
}

/** One global, cross-visitor MusicBrainz request slot, not one per PHP worker. */
function paceArtworkMusicBrainz($cacheDir) {
    if (!is_dir($cacheDir)) return false;
    $handle = @fopen($cacheDir.'/musicbrainz-rate.lock','c+');
    if (!$handle) return false;
    if (!flock($handle,LOCK_EX | LOCK_NB)) {
        $since = microtime(true);
        while (microtime(true)-$since < 1.5 && !flock($handle,LOCK_EX | LOCK_NB)) usleep(50000);
        if (microtime(true)-$since >= 1.5) {fclose($handle); return false;}
    }
    rewind($handle);
    $previous = (float)stream_get_contents($handle);
    $sleep = 1.2 - (microtime(true)-$previous);
    if ($sleep > 0 && artworkWithinBudget($sleep + 0.5)) usleep((int)($sleep*1000000));
    elseif ($sleep > 0) {flock($handle,LOCK_UN);fclose($handle);return false;}
    ftruncate($handle,0);rewind($handle);
    fwrite($handle,(string)microtime(true));fflush($handle);
    flock($handle,LOCK_UN);fclose($handle);
    return true;
}
function musicBrainzArtwork($endpoint,$args,$cacheDir) {
    if ($GLOBALS['artworkCounters']['musicbrainz'] >= 5 || !artworkWithinBudget(1.5) ||
        !paceArtworkMusicBrainz($cacheDir)) {
        $GLOBALS['artworkTemporaryFailure'] = true;
        return null;
    }
    $url = 'https://musicbrainz.org/ws/2/'.$endpoint.'/?'.
        http_build_query(array_merge($args,array('fmt'=>'json')),'','&',PHP_QUERY_RFC3986);
    return jsonArtworkRequest($url,3.2,true);
}

/** Artist IDs only when an exact normalised name/alias resolves unambiguously. */
function artworkIdentifyArtist($name,$cacheDir) {
    $term = str_replace(array('"','\\'),' ',$name);
    $data = musicBrainzArtwork('artist',array('query'=>'artist:"'.$term.'" OR alias:"'.$term.'"','limit'=>8),$cacheDir);
    if (!isset($data['artists'])) return null;
    $candidates = array();
    foreach ($data['artists'] as $a) {
        if ((int)($a['score'] ?? 0) < 90 || empty($a['id'])) continue;
        $names = array($a['name'] ?? '', $a['sort-name'] ?? '');
        foreach ((array)($a['aliases'] ?? array()) as $alias) $names[] = $alias['name'] ?? '';
        foreach ($names as $candidate) {
            if (matchArtworkName($candidate,$name)) {
                $candidates[$a['id']] = (int)$a['score'];
                break;
            }
        }
    }
    arsort($candidates);
    $ids = array_keys($candidates);
    if (count($ids)>1 && ($candidates[$ids[0]]-$candidates[$ids[1]]) < 8) return null;
    return $ids[0] ?? null;
}

/** CAA returns redirects for real covers. No protected image bytes are cached locally. */
function availableCoverArtwork($groupId) {
    if (!preg_match('/^[a-f0-9-]{36}$/i',(string)$groupId) ||
        !function_exists('curl_init') || !artworkWithinBudget(1.3)) return null;
    // One 500px HEAD is normally enough; the browser can fall back to 250px.
    ++$GLOBALS['artworkCounters']['cover_checks'];
    $url = 'https://coverartarchive.org/release-group/'.$groupId.'/front-500';
    $ch = curl_init($url);
    curl_setopt_array($ch,array(
        CURLOPT_NOBODY=>true,CURLOPT_CONNECTTIMEOUT=>1,
        CURLOPT_TIMEOUT=>min(2.0,max(1.0,$GLOBALS['artworkDeadline']-microtime(true)-0.2)),
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_USERAGENT=>'RC045-radio/2.0 (https://rc045.nl/radio.html; contact: webmaster@rc045.nl)'
    ));
    $success = curl_exec($ch);
    $code = (int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($success === false || $code === 429 || $code >= 500) {
        $GLOBALS['artworkTemporaryFailure'] = true;
        return null;
    }
    if (in_array($code,array(200,301,302,303,307,308),true)) return $url;
    // Some covers exist only as 250px; use a bounded second HEAD.
    if ($code !== 404 || !artworkWithinBudget(1.3)) return null;
    ++$GLOBALS['artworkCounters']['cover_checks'];
    $ch = curl_init(str_replace('/front-500','/front-250',$url));
    curl_setopt_array($ch,array(
        CURLOPT_NOBODY=>true,CURLOPT_CONNECTTIMEOUT=>1,CURLOPT_TIMEOUT=>1.8,
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_USERAGENT=>'RC045-radio/2.0 (https://rc045.nl/radio.html; contact: webmaster@rc045.nl)'
    ));
    $success = curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
    if ($success === false || $code >= 500 || $code === 429) $GLOBALS['artworkTemporaryFailure']=true;
    return in_array($code,array(200,301,302,303,307,308),true)
        ? str_replace('/front-500','/front-250',$url) : null;
}

/** Bounded, verified candidates; prioritise official artist albums over reissues. */
function artworkCheckCandidates($candidates,&$seen,$maxChecks = 7) {
    foreach ($candidates as $item) {
        if (count($seen) >= $maxChecks || !artworkWithinBudget(1.4)) break;
        $id = $item['id'] ?? '';
        if (!preg_match('/^[a-f0-9-]{36}$/i',(string)$id) || isset($seen[$id])) continue;
        $seen[$id]=true;
        if (($item['score'] ?? 0) < 83) continue;
        $cover = availableCoverArtwork($id);
        if ($cover !== null) return array(
            'image'=>$cover,
            'imageFallback'=>str_replace('/front-500','/front-250',$cover),
            'kind'=>'album',
            'creditUrl'=>'https://musicbrainz.org/release-group/'.$id,
            'creditLabel'=>'Cover Art Archive'
        );
    }
    return null;
}
function artworkOnlyAlbums($candidates) {
    return array_values(array_filter($candidates,function($row) {
        return ($row['primary'] ?? '') === 'album';
    }));
}
function artworkOnlySingles($candidates) {
    return array_values(array_filter($candidates,function($row) {
        return in_array($row['primary'] ?? '',array('single','ep'),true);
    }));
}
function findAlbumArtwork($artist,$title,$cacheDir) {
    $safeTitle = str_replace(array('"','\\'),' ',$title);
    $safeArtist = str_replace(array('"','\\'),' ',$artist);
    $query = 'recording:"'.$safeTitle.'" AND artist:"'.$safeArtist.'"';
    $data = musicBrainzArtwork('recording',array('query'=>$query,'limit'=>25),$cacheDir);
    $recordings = $data['recordings'] ?? array();
    $seen = array();
    $candidates = artworkRankedReleases($recordings,$artist,$title);
    // A studio album is preferred to a same-song single. Do NOT return the
    // first available single while its canonical album may be one browse away.
    $result = artworkCheckCandidates(artworkOnlyAlbums($candidates),$seen,3);
    if ($result) return $result;

    // Browse releases attached to one confirmed recording; the search index
    // often lists the single but omits the original studio album.
    foreach ($recordings as $recording) {
        if (!is_array($recording) || !isset($recording['id']) ||
            artworkBaseTitle($recording['title'] ?? '') !== artworkBaseTitle($title) ||
            !artworkVersionCompatible($title,$recording['title'] ?? '') ||
            !artworkArtistConfidence($recording['artist-credit'] ?? array(),$artist)) continue;
        $browse = musicBrainzArtwork('release',array('recording'=>$recording['id'],
            'inc'=>'release-groups+artist-credits','limit'=>50),$cacheDir);
        $expanded = $recording;
        $expanded['releases'] = $browse['releases'] ?? array();
        $related = artworkRankedReleases(array($expanded),$artist,$title);
        $result = artworkCheckCandidates(artworkOnlyAlbums($related),$seen,5);
        if ($result) return $result;
        $candidates = array_merge($candidates,$related);
        break;
    }

    $artistId = null;
    // Only spend additional MBID/alias queries if text search failed
    // to identify a candidate; avoid 2 needless API calls for known songs.
    if (!$candidates) {
        $artistId = artworkIdentifyArtist($artist,$cacheDir);
        if ($artistId) {
            $byId = musicBrainzArtwork('recording',array('query'=>'recording:"'.$safeTitle.
                '" AND arid:'.$artistId,'limit'=>25),$cacheDir);
            $extra = artworkRankedReleases($byId['recordings'] ?? array(),
                $artist,$title,$artistId);
            $result = artworkCheckCandidates(artworkOnlyAlbums($extra),$seen,5);
            if ($result) return $result;
            $candidates = array_merge($candidates,$extra);
        }
    }
    // Only now consider a same-recording single or EP. Three album checks,
    // then two via relations, reserve space for the strict single fallback.
    $result = artworkCheckCandidates(artworkOnlySingles($candidates),$seen,7);
    if ($result) return $result;

    $search = 'releasegroup:"'.$safeTitle.'" AND '.
        ($artistId ? 'arid:'.$artistId : 'artist:"'.$safeArtist.'"');
    $singles = musicBrainzArtwork('release-group',array('query'=>$search,'limit'=>15),$cacheDir);
    return artworkCheckCandidates(artworkRankedSingles($singles['release-groups'] ?? array(),
        $artist,$title,$artistId),$seen,7);
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

// Shared, server-side cache. Key is independent of radio station and visitor.
// Bump the namespace to invalidate older incorrect matches and six-hour errors.
$dir = rtrim(sys_get_temp_dir(),'/').'/rc045-radio-artwork-v5';
if (!is_dir($dir)) @mkdir($dir,0700,true);
$hash=hash('sha256',normalArtworkName($artist).'|'.normalArtworkName($title).
    '|'.artworkVersion($title));
$file=$dir.'/'.$hash.'.json';
$lock=@fopen($file.'.lock','c');
$cached=is_file($file) ? json_decode((string)@file_get_contents($file),true) : null;
if (is_array($cached) && (int)($cached['expires'] ?? 0)>time()) {
    $GLOBALS['artworkCacheHit']=true;
    if ($lock) fclose($lock);
    outputArtwork($cached['result']);
}
// Don't leave every request queued behind a slow MusicBrainz lookup.
// Concurrent visitors share the first result or an existing stale result.
$owned=false;
if ($lock) {
    $waitStart=microtime(true);
    do {
        $owned=flock($lock,LOCK_EX|LOCK_NB);
        if ($owned) break;
        usleep(50000);
    } while (microtime(true)-$waitStart<0.85);
    if (!$owned) {
        fclose($lock);
        if (is_array($cached) && !empty($cached['result']['image'])) {
            $GLOBALS['artworkCacheHit']=true;
            outputArtwork($cached['result']);
        }
        outputArtwork(array('image'=>null,'status'=>'temporary_error'));
    }
}
$cached=is_file($file) ? json_decode((string)@file_get_contents($file),true) : null;
if (is_array($cached) && (int)($cached['expires'] ?? 0)>time()) {
    $GLOBALS['artworkCacheHit']=true;
    if ($lock) {flock($lock,LOCK_UN);fclose($lock);}
    outputArtwork($cached['result']);
}
$result=findAlbumArtwork($artist,$title,$dir);
if (!$result && artworkWithinBudget(1.5)) $result=findArtistArtwork($artist);
if (!$result) $result=array('image'=>null,
    'status'=>$GLOBALS['artworkTemporaryFailure'] ? 'temporary_error' : 'not_found');
else $result['status']='ok';
// Positive covers: week; definitive miss: 45 min; transient failure: 30 sec.
$ttl= !empty($result['image'])
    ? (($result['kind'] ?? '') === 'artist'
        ? ($GLOBALS['artworkTemporaryFailure'] ? 3600 : 12 * 3600)
        : 7 * 86400)
    : (($result['status'] === 'temporary_error') ? 30 : 45 * 60);
if (is_dir($dir)) {
    $payload=json_encode(array('expires'=>time()+$ttl,'result'=>$result));
    $temp=@tempnam($dir,'art-');
    if ($temp && @file_put_contents($temp,$payload,LOCK_EX)!==false) @rename($temp,$file);
    elseif ($temp) @unlink($temp);
}
if ($lock) {flock($lock,LOCK_UN);fclose($lock);}
outputArtwork($result);
