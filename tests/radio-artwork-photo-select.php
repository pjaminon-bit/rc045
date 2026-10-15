<?php
require_once __DIR__.'/../radio-artwork-release-select.php';
require_once __DIR__.'/../radio-artwork-photo-select.php';

function ensurePhoto($condition,$message) {
    if (!$condition) {fwrite(STDERR,"Artist photo test failure: ".$message."\n");exit(1);}
}
$artist = 'Example Artist';
$url = 'https://upload.wikimedia.org/wikipedia/commons/thumb/a/ab/Example_Artist.jpg/500px-Example_Artist.jpg';
$page = array('title'=>$artist,'thumbnail'=>array('source'=>$url));
$info = array(
    'descriptionurl'=>'https://commons.wikimedia.org/wiki/File:Example_Artist.jpg',
    'extmetadata'=>array(
        'LicenseShortName'=>array('value'=>'CC BY-SA 4.0'),
        'Artist'=>array('value'=>'<a href="https://commons.wikimedia.org">Jane Photographer</a>')
    )
);
ensurePhoto(artworkWikipediaTitleMatchesArtist('Example Artist (singer)',$artist),
    'Artist disambiguation with the exact artist name is allowed.');
ensurePhoto(artworkWikipediaTitleMatchesArtist('Example Artist (band)',$artist),
    'Band disambiguation with the exact artist name is allowed.');
ensurePhoto(!artworkWikipediaTitleMatchesArtist('Another Artist (singer)',$artist),
    'A different musician must not be accepted.');
ensurePhoto(!artworkWikipediaTitleMatchesArtist('Example Artist (actor)',$artist),
    'Non-musical disambiguation must not be accepted.');
ensurePhoto(artworkCommonsFilename($url)==='Example_Artist.jpg',
    'Only Commons thumbnails are eligible.');
ensurePhoto(artworkCommonsFilename('https://example.com/photo.jpg')===null,
    'Unknown image hosts must be rejected.');
ensurePhoto(artworkCommonsFilename('https://upload.wikimedia.org/wikipedia/commons/thumb/a/ab/Photo.svg/500px-Photo.svg.png')===null,
    'SVG is not accepted as a photographic original.');
$photo = artworkLicensedArtistPhoto($artist,$page,$info);
ensurePhoto($photo !== null && ($photo['kind'] ?? '') === 'artist',
    'A valid freely-licensed photograph should be accepted.');
ensurePhoto(strpos($photo['creditLabel'],'Jane Photographer')!==false &&
    strpos($photo['creditLabel'],'CC BY-SA 4.0')!==false,
    'Author and license must appear in visible credit.');
$missing = $info;unset($missing['extmetadata']['Artist']);
ensurePhoto(artworkLicensedArtistPhoto($artist,$page,$missing)===null,
    'Attribution licenses must not be displayed without an author.');
$unfree = $info;$unfree['extmetadata']['LicenseShortName']['value']='All rights reserved';
ensurePhoto(artworkLicensedArtistPhoto($artist,$page,$unfree)===null,
    'Non-free artist photos must be excluded.');
$wrong = $page;$wrong['title']='Another Artist';
ensurePhoto(artworkLicensedArtistPhoto($artist,$wrong,$info)===null,
    'A different artist page must never supply a photo.');
echo "Artist photo fallback: matching, security and Commons license OK\n";
