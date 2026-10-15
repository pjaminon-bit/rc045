<?php
/**
 * Pure, testable Wikipedia / Commons artist-photo validation.
 * No station- or artist-specific rules; never accept uncredited non-free art.
 */
function artworkWikipediaTitleMatchesArtist($pageTitle, $artist) {
    if (matchArtworkName($pageTitle, $artist)) return true;
    // Wikipedia may disambiguate a musical artist: "Name (singer)".
    // Require an exact base-name match, never a partial title.
    if (preg_match('/^(.+?)\\s*\\((?:musician|singer|band|music group|rapper|disc jockey|dj)\\)$/iu',
        trim((string)$pageTitle), $matches)) {
        return matchArtworkName($matches[1], $artist);
    }
    return false;
}

function artworkCommonsFilename($url) {
    // MediaWiki pageimages returns either a resized /thumb/ URL or an
    // original-size URL (when the source image is already <= 500 pixels).
    // Accept both exact Commons path structures, never arbitrary hosts.
    if (!is_string($url) ||
        strpos($url, 'https://upload.wikimedia.org/wikipedia/commons/') !== 0) return null;
    $path = parse_url($url, PHP_URL_PATH);
    $pattern = '#^/wikipedia/commons/(?:thumb/[0-9a-f]/[0-9a-f]{2}/([^/]+)/[^/]+|[0-9a-f]/[0-9a-f]{2}/([^/]+))$#i';
    if (!is_string($path) || !preg_match($pattern, $path, $matches)) return null;
    $filename = rawurldecode($matches[1] ?: ($matches[2] ?? ''));
    if (strpos($filename, '/') !== false ||
        !preg_match('/\\.(?:png|jpe?g|webp)$/i', $filename)) return null;
    return $filename;
}

function artworkLicensedArtistPhoto($artist, $page, $imageInfo) {
    if (!is_array($page) || !is_array($imageInfo) ||
        !artworkWikipediaTitleMatchesArtist($page['title'] ?? '', $artist)) return null;
    $src = $page['thumbnail']['source'] ?? null;
    if (artworkCommonsFilename($src) === null) return null;

    $licenseName = trim(html_entity_decode(strip_tags((string)(
        $imageInfo['extmetadata']['LicenseShortName']['value'] ?? ''
    )), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $license = strtolower($licenseName);
    if (!preg_match('/^(?:cc[ -]?(?:by|0)|public domain|pdm)\\b/i', $license)) return null;
    $rawArtist = $imageInfo['extmetadata']['Artist']['value'] ??
        ($imageInfo['extmetadata']['Attribution']['value'] ?? '');
    $photoAuthor = trim((string)preg_replace('/\\s+/', ' ',
        html_entity_decode(strip_tags((string)$rawArtist), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    if (preg_match('/^cc[ -]?by/i', $license) && $photoAuthor === '') return null;
    if (function_exists('mb_substr')) $photoAuthor = mb_substr($photoAuthor, 0, 65, 'UTF-8');
    else $photoAuthor = substr($photoAuthor, 0, 65);
    $description = $imageInfo['descriptionurl'] ?? '';
    if (!is_string($description) ||
        strpos($description, 'https://commons.wikimedia.org/wiki/File:') !== 0) return null;

    return array(
        'image' => $src,
        'kind' => 'artist',
        'creditUrl' => $description,
        'creditLabel' => 'Foto: ' .
            ($photoAuthor !== '' ? $photoAuthor : 'Wikimedia Commons') .
            ' · ' . $licenseName
    );
}
