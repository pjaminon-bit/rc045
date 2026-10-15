<?php
/**
 * Deterministic release identity and confidence scoring for radio artwork.
 * All rules are generic: never special-case a station, artist or recording.
 * This module has no network or filesystem side effects.
 */

function artworkNormalize($value) {
    $value = (string)$value;
    if (class_exists('Normalizer')) $value = Normalizer::normalize($value, Normalizer::FORM_KC) ?: $value;
    $value = str_replace(array('–','—','−','‑','‐','’','‘','＇','＆'), array('-','-','-','-','-',"'", "'", "'",'&'), $value);
    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$value);
        if ($ascii !== false) $value = $ascii;
    }
    return trim(strtolower((string)preg_replace('/[^a-zA-Z0-9]+/',' ', $value)));
}

function normalArtworkName($value) {
    return str_replace(' ', '', artworkNormalize($value));
}

/** Strict: punctuation/transliteration and spacing, never a substring match. */
function matchArtworkName($a, $b) {
    return normalArtworkName($a) !== '' && normalArtworkName($a) === normalArtworkName($b);
}

/** Version labels matter: a remix or live take must not borrow the studio sleeve. */
function artworkVersion($title) {
    $text = artworkNormalize($title);
    if (preg_match('/\b(?:live|acoustic|unplugged|concert|session)\b/', $text)) return 'live';
    if (preg_match('/\b(?:remix|rework|bootleg|mashup|club mix|dance mix|vip mix)\b/', $text)) return 'remix';
    if (preg_match('/\b(?:demo|instrumental|karaoke)\b/', $text)) return 'alternate';
    if (preg_match('/\b(?:extended|long version)\b/', $text)) return 'extended';
    if (preg_match('/\b(?:radio edit|single edit|edit version|clean edit)\b/', $text)) return 'edit';
    return 'standard';
}
function artworkBaseTitle($title) {
    $title = (string)$title;
    // Strip ONLY recognised edition qualifiers; meaningful parenthesised titles stay.
    $pattern = '/\s*[\(\[\{][^\)\]\}]{0,90}\b(?:remix|rework|bootleg|mix|edit|live|acoustic|unplugged|remaster(?:ed)?|version|extended|instrumental|demo)\b[^\)\]\}]*[\)\]\}]/iu';
    $title = preg_replace($pattern, '', $title);
    $title = preg_replace('/\s*[-–—]\s*(?:\d{4}\s+)?(?:remaster(?:ed)?|radio edit|live|acoustic|extended version).*$/iu','',$title);
    return normalArtworkName($title);
}
function artworkVersionCompatible($wanted, $found) {
    $a = artworkVersion($wanted); $b = artworkVersion($found);
    if ($a === $b) return true;
    // Radio edits and remasters are eligible for the original release when
    // the underlying title matches; remix/live/instrumental are NOT.
    return in_array($a,array('standard','edit'),true) &&
        in_array($b,array('standard','edit'),true);
}

function artworkNamesFromCredit($credits) {
    $out = array();
    foreach ((array)$credits as $credit) {
        if (!is_array($credit)) continue;
        $name = $credit['artist']['name'] ?? ($credit['name'] ?? '');
        $alias = $credit['name'] ?? '';
        $id = $credit['artist']['id'] ?? '';
        if (normalArtworkName($name) !== '') $out[] = array('name'=>$name,'alias'=>$alias,'id'=>$id);
    }
    return $out;
}
function artworkArtistConfidence($credit, $artist, $artistId = null) {
    $artists = artworkNamesFromCredit($credit);
    if (!$artists) return 0;
    $requested = normalArtworkName($artist);
    if ($requested === '') return 0;
    $all = array();
    foreach ($artists as $a) {
        if ($artistId && $a['id'] === $artistId) return 30;
        foreach (array($a['name'],$a['alias']) as $name) {
            if (normalArtworkName($name) === $requested) return 30;
        }
        $all[] = $a['alias'] ?: $a['name'];
    }
    if (normalArtworkName(implode(' ', $all)) === $requested) return 29;
    // Radioplaylists can credit a lead artist while recordings credit features.
    $pieces = preg_split('/\s+(?:feat\.?|featuring|ft\.?|with|x)\s+|\s*[,&+]\s*/i', $artist);
    $lead = normalArtworkName($pieces[0] ?? '');
    if ($lead !== '' && $lead === normalArtworkName($artists[0]['name'])) return 26;
    if ($lead !== '' && $lead === normalArtworkName($artists[0]['alias'])) return 26;
    return 0;
}

/** Returns a ranked explanation, or null for unsafe candidates. */
function artworkScoreRelease($recording, $release, $artist, $title, $artistId = null) {
    if (!is_array($recording) || !is_array($release)) return null;
    $recordingScore = (int)($recording['score'] ?? 100);
    if ($recordingScore < 75 ||
        artworkBaseTitle($recording['title'] ?? '') !== artworkBaseTitle($title) ||
        !artworkVersionCompatible($title, $recording['title'] ?? '')) return null;
    $identity = artworkArtistConfidence($recording['artist-credit'] ?? array(), $artist, $artistId);
    if (!$identity) return null;

    if (strtolower((string)($release['status'] ?? '')) !== 'official') return null;
    $group = $release['release-group'] ?? array();
    $id = $group['id'] ?? '';
    if (!is_string($id) || !preg_match('/^[a-f0-9-]{36}$/i', $id)) return null;
    $primary = strtolower((string)($group['primary-type'] ?? ($group['type'] ?? '')));
    if (!in_array($primary,array('album','single','ep'),true)) return null;

    $secondaries = array_map('strtolower', (array)($group['secondary-types'] ?? array()));
    if (array_intersect($secondaries,array('compilation','live','remix','mixtape','dj-mix','spokenword'))) return null;
    if (in_array('soundtrack',$secondaries,true) && $primary !== 'single') return null;

    $releaseCredit = $release['artist-credit'] ?? ($group['artist-credit'] ?? array());
    $releaseIdentity = artworkArtistConfidence($releaseCredit, $artist, $artistId);
    // MusicBrainz recording search sometimes omits release artist-credit.
    // No unverifiable compilations should outrank a verified artist release.
    if ($releaseCredit && !$releaseIdentity) return null;
    // Omitted credit in a search result is unknown, not proof of a mismatch.
    // Keep it lower ranked; the recording artist and release type remain verified.
    if (!$releaseCredit) $releaseIdentity = 10;

    $groupTitle = (string)($group['title'] ?? ($release['title'] ?? ''));
    if (preg_match('/\b(?:playlist|compilation|mixtape)\b/i', $groupTitle)) return null;
    $version = artworkVersion($groupTitle);
    if (in_array($version,array('live','remix','alternate','extended'),true) &&
        artworkVersion($title) === 'standard') return null;
    $date = (string)($group['first-release-date'] ?? ($release['date'] ?? ''));
    $year = preg_match('/^(?:19|20)\d{2}/', $date) ? (int)substr($date,0,4) : 9999;
    $points = $identity + min(15, $releaseIdentity/2) + 26 +
        ($recordingScore >= 95 ? 10 : 6) + 10 +
        ($primary === 'album' ? 9 : ($primary === 'single' ? 8 : 5));
    if (in_array('soundtrack',$secondaries,true)) $points -= 3;
    if ($year !== 9999) $points += 3;
    return array('id'=>$id,'score'=>(int)$points,'year'=>$year,'primary'=>$primary,
        'reason'=>'artist=' . $identity . ', releaseArtist=' . $releaseIdentity .
        ', recording=' . $recordingScore . ', type=' . $primary . ', year=' . $year);
}
function artworkRankedReleases($recordings, $artist, $title, $artistId = null) {
    $found = array();
    foreach ((array)$recordings as $recording) {
        if (!is_array($recording)) continue;
        foreach ((array)($recording['releases'] ?? array()) as $release) {
            $item = artworkScoreRelease($recording,$release,$artist,$title,$artistId);
            if (!$item || $item['score'] < 83) continue;
            $id = $item['id'];
            if (!isset($found[$id]) || $found[$id]['score'] < $item['score'] ||
                ($found[$id]['score'] === $item['score'] && $item['year'] < $found[$id]['year'])) {
                $found[$id] = $item;
            }
        }
    }
    usort($found,function($a,$b){
        $priority = array('album'=>0,'single'=>1,'ep'=>2);
        return ($b['score'] <=> $a['score']) ?: ($a['year'] <=> $b['year']) ?:
            (($priority[$a['primary']] ?? 3) <=> ($priority[$b['primary']] ?? 3)) ?:
            strcmp($a['id'],$b['id']);
    });
    return $found;
}

/** Search route for releases not linked by the recording search index. */
function artworkRankedSingles($groups, $artist, $title, $artistId = null) {
    $out = array();
    foreach ((array)$groups as $group) {
        if (!is_array($group) || (int)($group['score'] ?? 100) < 88) continue;
        if (artworkBaseTitle($group['title'] ?? '') !== artworkBaseTitle($title) ||
            !artworkVersionCompatible($title, $group['title'] ?? '')) continue;
        if (strtolower((string)($group['primary-type'] ?? '')) !== 'single') continue;
        $types = array_map('strtolower',(array)($group['secondary-types'] ?? array()));
        if (array_diff($types,array('soundtrack'))) continue;
        $confidence = artworkArtistConfidence($group['artist-credit'] ?? array(),$artist,$artistId);
        if (!$confidence) continue;
        $id = $group['id'] ?? '';
        if (!is_string($id) || !preg_match('/^[a-f0-9-]{36}$/i',$id)) continue;
        $date = (string)($group['first-release-date'] ?? '');
        $out[] = array('id'=>$id,'score'=>91 + ($confidence === 30 ? 5 : 0),
            'primary'=>'single','year'=>preg_match('/^\d{4}/',$date) ? (int)substr($date,0,4) : 9999,
            'reason'=>'verified single artist and title');
    }
    usort($out,function($a,$b){return ($b['score'] <=> $a['score']) ?: ($a['year'] <=> $b['year']);});
    return $out;
}

// Legacy helpers retained for consumers, backed by the common scoring rules.
function canonicalArtworkArtist($artist) { return $artist; }
function artworkReleaseCandidates($recordings,$artist,$title,$matchesName = null) {
    return array_column(artworkRankedReleases($recordings,$artist,$title),'id');
}
function artworkSingleCandidates($groups,$artist,$title,$matchesName = null) {
    return array_column(artworkRankedSingles($groups,$artist,$title),'id');
}
