<?php
/**
 * Select canonical artist releases for the optional album-art background.
 * No HTTP calls here: deterministic and independently testable.
 */
/**
 * Stations kunnen de naam van een artiest zonder stilering doorgeven.
 * MusicBrainz indexeert P!nk anders dan Pink/PINK. Houd die omzetting
 * uitsluitend bij de artwork-lookup; verander NOOIT de radio-trackinfo.
 */
function canonicalArtworkArtist($artist) {
    if (strcasecmp(trim((string)$artist), 'pink') === 0) {
        return 'P!nk';
    }
    return $artist;
}

function artworkReleaseCandidates($recordings, $artist, $title, $matchesName) {
    if (!is_array($recordings)) return array();
    $found = array();

    foreach ($recordings as $recording) {
        if (!is_array($recording) ||
            (int)($recording['score'] ?? 0) < 88 ||
            !$matchesName($recording['title'] ?? '', $title)) continue;

        $trackCredits = array();
        foreach (($recording['artist-credit'] ?? array()) as $credit) {
            if (is_array($credit)) {
                $trackCredits[] = $credit['name'] ?? ($credit['artist']['name'] ?? '');
            }
        }
        if (!$matchesName(implode(' ', $trackCredits), $artist)) continue;

        foreach (($recording['releases'] ?? array()) as $release) {
            if (!is_array($release) ||
                strtolower((string)($release['status'] ?? '')) !== 'official') continue;

            $group = $release['release-group'] ?? array();
            $id = $group['id'] ?? '';
            if (!is_string($id) || !preg_match('/^[a-f0-9-]{36}$/i', $id)) continue;

            // A playlist, compilation, soundtrack, remix, or live reissue is
            // not the original record's sleeve, even if the track matches.
            if (!empty($group['secondary-types'])) continue;
            if (preg_match('/compilation|soundtrack|remix|live|mixtape/i',
                (string)($group['type'] ?? ''))) continue;

            $primary = strtolower((string)($group['primary-type'] ?? ''));
            if (!in_array($primary, array('album', 'single', 'ep'), true)) continue;

            // Recording credits identify the performance; release credits
            // identify whether the sleeve is actually issued by that artist.
            $releaseCredit = $release['artist-credit'] ?? ($group['artist-credit'] ?? array());
            $releaseNames = array();
            foreach ($releaseCredit as $credit) {
                if (is_array($credit)) {
                    $releaseNames[] = $credit['name'] ?? ($credit['artist']['name'] ?? '');
                }
            }
            if ($releaseNames &&
                !$matchesName(implode(' ', $releaseNames), $artist)) continue;

            $releaseName = (string)($group['title'] ?? ($release['title'] ?? ''));
            if (preg_match('/\\b(?:playlist|compilation|mixtape|vibes)\\b/i', $releaseName)) {
                continue;
            }

            $date = (string)($group['first-release-date'] ?? ($release['date'] ?? ''));
            $year = preg_match('/^(?:19|20)\\d{2}/', $date)
                ? (int)substr($date, 0, 4) : 9999;
            $weight = ($primary === 'album' ? 0 : ($primary === 'ep' ? 1 : 2));
            $rank = $weight * 100000 + $year * 10 - min(100, (int)$recording['score']);
            if (!isset($found[$id]) || $rank < $found[$id]['rank']) {
                $found[$id] = array('id' => $id, 'rank' => $rank);
            }
        }
    }

    uasort($found, function($a, $b) {return $a['rank'] <=> $b['rank'];});
    return array_slice(array_keys($found), 0, 4);
}


/**
 * Tweede, strikte zoekroute als de opname-index geen geschikte originele
 * release toont. Bijvoorbeeld "Dance the Night" van Dua Lipa: de officiële
 * single staat als Single + Soundtrack geregistreerd. Dat is nadrukkelijk
 * niet hetzelfde als een soundtrack-compilatie door Various Artists.
 */
function artworkSingleCandidates($groups, $artist, $title, $matchesName) {
    if (!is_array($groups)) return array();
    $found = array();
    foreach ($groups as $group) {
        if (!is_array($group) || (int)($group['score'] ?? 0) < 95) continue;
        if (!$matchesName($group['title'] ?? '', $title)) continue;
        if (strcasecmp((string)($group['primary-type'] ?? ''), 'Single') !== 0) continue;

        $types = $group['secondary-types'] ?? array();
        if (!is_array($types)) continue;
        foreach ($types as $type) {
            // Alleen het type Soundtrack is toegestaan voor een artiestensingle.
            if (strcasecmp((string)$type, 'Soundtrack') !== 0) continue 2;
        }

        $names = array();
        foreach (($group['artist-credit'] ?? array()) as $credit) {
            if (is_array($credit)) {
                $names[] = $credit['name'] ?? ($credit['artist']['name'] ?? '');
            }
        }
        if (!$names || !$matchesName(implode(' ', $names), $artist)) continue;
        // Bij de strikte route géén fuzzy substringmatches: voorkomt
        // covers van andere nummers of gelijknamige artiesten.
        if (!function_exists('normalArtworkName') ||
            normalArtworkName($group['title'] ?? '') !== normalArtworkName($title) ||
            normalArtworkName(implode(' ', $names)) !== normalArtworkName($artist)) {
            continue;
        }

        $id = $group['id'] ?? '';
        if (!is_string($id) || !preg_match('/^[a-f0-9-]{36}$/i', $id)) continue;
        $found[] = array('id' => $id, 'date' => $group['first-release-date'] ?? '9999');
    }
    usort($found, function($a, $b) {
        return strcmp((string)$a['date'], (string)$b['date']);
    });
    return array_slice(array_column($found, 'id'), 0, 3);
}
