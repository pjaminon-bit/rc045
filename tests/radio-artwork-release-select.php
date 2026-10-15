<?php
require_once __DIR__.'/../radio-artwork-release-select.php';
function requireArtwork($ok,$message) {
    if (!$ok) {fwrite(STDERR,"FAIL: ".$message."\n");exit(1);}
}
function gid($n) {return sprintf('%08d-1111-4111-8111-%012d',$n,$n);}
function credit($name,$id=null) {
    return array(array('name'=>$name,'artist'=>array('name'=>$name,'id'=>$id)));
}
function releaseArtwork($id,$title,$type='Album',$secondary=array(),$artist='Example Artist',$year='2020-01-01') {
    return array('status'=>'Official','date'=>$year,'title'=>$title,
        'artist-credit'=>credit($artist),'release-group'=>array(
            'id'=>$id,'title'=>$title,'primary-type'=>$type,
            'secondary-types'=>$secondary,'first-release-date'=>$year));
}
$album=gid(1);$single=gid(2);$compilation=gid(3);$wrongArtist=gid(4);$live=gid(5);
$track=array('id'=>gid(10),'title'=>'Heads Will Roll','score'=>100,
    'artist-credit'=>credit('Yeah Yeah Yeahs'),'releases'=>array(
        releaseArtwork($compilation,'Halloween Hits','Album',array('Compilation'),'Various Artists','2007-01-01'),
        releaseArtwork($wrongArtist,'Halloween Playlist','Album',array(),'Various Artists','2011-01-01'),
        releaseArtwork($live,'Live at Festival','Album',array('Live'),'Yeah Yeah Yeahs','2008-01-01'),
        releaseArtwork($album,"It's Blitz!",'Album',array(),'Yeah Yeah Yeahs','2009-01-01'),
        releaseArtwork($single,'Heads Will Roll','Single',array(),'Yeah Yeah Yeahs','2009-02-01')
    ));
$ranked=artworkRankedReleases(array($track),'Yeah Yeah Yeahs','Heads Will Roll');
requireArtwork(array_column($ranked,'id')===array($album,$single),
    'Official artist album must outrank single and exclude compilations.');
requireArtwork($ranked[0]['score'] >= 83 && strpos($ranked[0]['reason'],'artist=')!==false,
    'Ranked scores must be explainable and above confidence threshold.');
$bad=$track;$bad['artist-credit']=credit('Other Band');
requireArtwork(!artworkRankedReleases(array($bad),'Yeah Yeah Yeahs','Heads Will Roll'),
    'A wrong recording artist must never match.');
$bad=$track;$bad['title']='Other Song';
requireArtwork(!artworkRankedReleases(array($bad),'Yeah Yeah Yeahs','Heads Will Roll'),
    'A wrong title must never match.');
$bad=$track;$bad['title']='Heads Will Roll (Live)';
requireArtwork(!artworkRankedReleases(array($bad),'Yeah Yeah Yeahs','Heads Will Roll'),
    'A live performance cannot borrow a studio cover.');
$bad=$track;$bad['title']='Heads Will Roll (A Remix)';
requireArtwork(!artworkRankedReleases(array($bad),'Yeah Yeah Yeahs','Heads Will Roll'),
    'A remix cannot borrow a studio cover.');
$bad=$track;$bad['title']='Heads Will Roll (Radio Edit)';
requireArtwork(count(artworkRankedReleases(array($bad),'Yeah Yeah Yeahs','Heads Will Roll'))===2,
    'A radio edit can use the release of the corresponding base title.');
requireArtwork(!matchArtworkName('PINK','P!nk'),
    'Stylistically distinct names need verified aliases, not unsafe fuzzy spelling.');
requireArtwork(!matchArtworkName('Pink Floyd','P!nk'),
    'Substring matches between distinct artists must be forbidden.');
requireArtwork(matchArtworkName('Anti–Hero','Anti-Hero') &&
    matchArtworkName('Anti‑Hero','Anti-Hero'),
    'Unicode punctuation must normalise consistently.');
requireArtwork(matchArtworkName('Beyoncé','Beyonce'),
    'Unicode diacritics must be handled where iconv is available.');
requireArtwork(!artworkVersionCompatible('Get Lucky (Remix)','Get Lucky') &&
    !artworkVersionCompatible('Sober (Live)','Sober'),
    'Version identity must protect distinct performances.');
$duas=gid(11);
$groups=array(
    array('id'=>gid(12),'score'=>100,'title'=>'Dance the Night',
        'primary-type'=>'Album','secondary-types'=>array('Soundtrack'),
        'artist-credit'=>credit('Various Artists')),
    array('id'=>gid(13),'score'=>100,'title'=>'Dance the Night',
        'primary-type'=>'Single','secondary-types'=>array('Remix'),
        'artist-credit'=>credit('Dua Lipa')),
    array('id'=>$duas,'score'=>100,'title'=>'Dance the Night',
        'primary-type'=>'Single','secondary-types'=>array('Soundtrack'),
        'first-release-date'=>'2023-05-23','artist-credit'=>credit('Dua Lipa'))
);
requireArtwork(array_column(artworkRankedSingles($groups,'Dua Lipa','Dance the Night'),'id')===array($duas),
    'Official soundtrack single is allowed without soundtrack compilations or remixes.');
$wrong=$track;
$wrong['releases']=array(releaseArtwork(gid(23),'Heads Will Roll','Album',array(),'Another Artist'));
requireArtwork(!artworkRankedReleases(array($wrong),'Yeah Yeah Yeahs','Heads Will Roll'),
    'Wrong release credits must be rejected even when track credits match.');
$missing=$track;
unset($missing['releases'][3]['artist-credit']);
requireArtwork(in_array($album,array_column(artworkRankedReleases(array($missing),
    'Yeah Yeah Yeahs','Heads Will Roll'),'id'),true),
    'Missing nested release credits should not discard an otherwise validated release.');
// Same MBID is strong confirmation when one station uses an alias.
$alias=$track;$alias['artist-credit']=credit('P!nk',gid(100));
$alias['releases']=array(releaseArtwork(gid(24),'Funhouse','Album',array(),'P!nk'));
requireArtwork(artworkArtistConfidence($alias['artist-credit'],'PINK',gid(100))===30,
    'MusicBrainz artist ID is authoritative for aliases.');
echo "Artwork 2.0: deterministic identity, version, artist/release confidence tests OK\n";
