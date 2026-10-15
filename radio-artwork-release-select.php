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
