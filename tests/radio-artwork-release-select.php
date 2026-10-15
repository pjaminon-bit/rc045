<?php
require_once __DIR__ . '/../radio-artwork-release-select.php';

// Dezelfde normalisatie als de live PHP endpoint voor exacte matches.
function normalArtworkName($value) {
    return strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '', (string)$value));
}
function sameArtistOrTitle($a, $b) {
    return strtolower(trim((string)$a)) === strtolower(trim((string)$b));
}
function checkResult($condition, $message) {
    if (!$condition) {fwrite(STDERR, $message . "\n"); exit(1);}
}

$compilation = '11111111-1111-4111-8111-111111111111';
$playlist = '22222222-2222-4222-8222-222222222222';
$properAlbum = '33333333-3333-4333-8333-333333333333';
$properSingle = '44444444-4444-4444-8444-444444444444';
$variousAlbum = '55555555-5555-4555-8555-555555555555';

$recordings = array(array(
    'title' => 'Heads Will Roll',
    'score' => 100,
    'artist-credit' => array(array('name'=>'Yeah Yeah Yeahs')),
    'releases' => array(
        array('status'=>'Official', 'date'=>'2020-10-03',
            'title'=>'Witchy Fall Vibes',
            'release-group'=>array('id'=>$compilation,'primary-type'=>'Album',
                'secondary-types'=>array('Compilation'))),
        array('status'=>'Official', 'date'=>'2026-10-01',
            'title'=>'witchy fall vibes',
            'release-group'=>array('id'=>$playlist,'primary-type'=>'Album',
                'secondary-types'=>array())),
        array('status'=>'Official', 'date'=>'2021-01-03',
            'title'=>'Random Album', 'artist-credit'=>array(array('name'=>'Various Artists')),
            'release-group'=>array('id'=>$variousAlbum,'primary-type'=>'Album',
                'secondary-types'=>array())),
        array('status'=>'Official', 'date'=>'2010-04-01',
            'title'=>'Heads Will Roll', 'artist-credit'=>array(array('name'=>'Yeah Yeah Yeahs')),
            'release-group'=>array('id'=>$properSingle,'title'=>'Heads Will Roll',
                'primary-type'=>'Single','secondary-types'=>array())),
        array('status'=>'Official', 'date'=>'2009-03-06',
            'title'=>"It's Blitz!", 'artist-credit'=>array(array('name'=>'Yeah Yeah Yeahs')),
            'release-group'=>array('id'=>$properAlbum,'title'=>"It's Blitz!",
                'primary-type'=>'Album','secondary-types'=>array()))
    )
));
$ranked = artworkReleaseCandidates($recordings, 'Yeah Yeah Yeahs', 'Heads Will Roll',
    'sameArtistOrTitle');
checkResult($ranked === array($properAlbum, $properSingle),
    'Canonical artist album must win, and playlist/compilation must be excluded.');

$wrong = $recordings;
$wrong[0]['artist-credit'][0]['name'] = 'Different Band';
checkResult(artworkReleaseCandidates($wrong, 'Yeah Yeah Yeahs',
    'Heads Will Roll','sameArtistOrTitle') === array(),
    'Wrong recording artist must never supply a cover.');

$wrong = $recordings;
$wrong[0]['title'] = 'Other Song';
checkResult(artworkReleaseCandidates($wrong, 'Yeah Yeah Yeahs',
    'Heads Will Roll','sameArtistOrTitle') === array(),
    'Wrong song must never supply a cover.');

$wrong = $recordings;
$wrong[0]['releases'][4]['release-group']['secondary-types'] = array('Live');
checkResult(artworkReleaseCandidates($wrong, 'Yeah Yeah Yeahs',
    'Heads Will Roll','sameArtistOrTitle') === array($properSingle),
    'Live album cannot override the original single.');

checkResult(canonicalArtworkArtist('PINK') === 'P!nk',
    'Uppercase PINK from Radio 538 must resolve to P!nk.');
checkResult(canonicalArtworkArtist('Pink') === 'P!nk',
    'Regular Pink spelling must resolve to P!nk for artwork.');
checkResult(canonicalArtworkArtist('P!nk') === 'P!nk',
    'Correct MusicBrainz stylization must remain unchanged.');
checkResult(canonicalArtworkArtist('Pink Floyd') === 'Pink Floyd',
    'Another artist containing Pink may not be rewritten.');
checkResult(canonicalArtworkArtist('Coldplay') === 'Coldplay',
    'Other artists must be untouched.');


$duasSingleId = '54d80d1a-d34e-40e1-968a-38368bfa4546';
$singleGroups = array(
    array('id'=>'11111111-1111-4111-8111-111111111111',
        'score'=>100, 'title'=>'Barbie: The Album', 'primary-type'=>'Album',
        'secondary-types'=>array('Soundtrack'),
        'artist-credit'=>array(array('name'=>'Various Artists'))),
    array('id'=>'22222222-2222-4222-8222-222222222222',
        'score'=>100, 'title'=>'Dance the Night', 'primary-type'=>'Single',
        'secondary-types'=>array('Remix'),
        'artist-credit'=>array(array('name'=>'Dua Lipa'))),
    array('id'=>'33333333-3333-4333-8333-333333333333',
        'score'=>100, 'title'=>'Dance the Night', 'primary-type'=>'Single',
        'secondary-types'=>array('Soundtrack'),
        'artist-credit'=>array(array('name'=>'Other Singer'))),
    array('id'=>$duasSingleId,
        'score'=>100, 'title'=>'Dance the Night', 'primary-type'=>'Single',
        'secondary-types'=>array('Soundtrack'),
        'first-release-date'=>'2023-05-23',
        'artist-credit'=>array(array('name'=>'Dua Lipa'))),
    array('id'=>'44444444-4444-4444-8444-444444444444',
        'score'=>100, 'title'=>'Dance the Night (Live)', 'primary-type'=>'Single',
        'secondary-types'=>array(),
        'artist-credit'=>array(array('name'=>'Dua Lipa')))
);
$duas = artworkSingleCandidates($singleGroups, 'Dua Lipa', 'Dance The Night',
    'sameArtistOrTitle');
checkResult($duas === array($duasSingleId),
    'Dua Lipa soundtrack single must match without showing a compilation, remix or wrong artist.');

$bad = $singleGroups;
$bad[3]['secondary-types'] = array('Soundtrack', 'Compilation');
checkResult(artworkSingleCandidates($bad, 'Dua Lipa', 'Dance The Night',
    'sameArtistOrTitle') === array(),
    'Soundtrack singles also marked Compilation must be rejected.');

echo "Artwork release matching OK: canonical artist album, soundtrack single, no compilations.\n";
