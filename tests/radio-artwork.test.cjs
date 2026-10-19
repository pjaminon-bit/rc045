'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.join(__dirname, '..');
const html = fs.readFileSync(path.join(root, 'radio.html'), 'utf8');
const php = fs.readFileSync(path.join(root, 'radio-artwork.php'), 'utf8');
const photoSelector = fs.readFileSync(path.join(root, 'radio-artwork-photo-select.php'), 'utf8');
const start = html.indexOf('const artworkCache = new Map();');
const end = html.indexOf('function setTrackLoading()', start);
assert.ok(start > 0 && end > start, 'separate artwork-only helper block');
const artworkLogic = html.slice(start, end);

function element() {
  const attributes = new Map();
  const classes = new Set();
  return {
    hidden: false, textContent: '', title: '', src: '',
    classList: {
      add(s) {classes.add(s);},
      remove(s) {classes.delete(s);},
      contains(s) {return classes.has(s);}
    },
    removeAttribute(name) {
      attributes.delete(name);
      if (name === 'src' || name === 'href') this[name] = undefined;
    }
  };
}

function env() {
  const container = element();
  const image = element();
  const credit = element();
  const calls = [];
  const saved = [];
  const toggle = {
    checked: true,
    listeners: {},
    addEventListener(type, cb) {this.listeners[type] = cb;}
  };
  const pendingImages = [];
  const pendingRequests = [];
  class ImageStub {
    set src(url) {
      this.currentSrc = url;
      pendingImages.push(this);
    }
  }
  const ctx = vm.createContext({
    stationArtwork: container,
    trackArtworkBackground: image,
    artworkCredit: credit,
    albumArtEnabled: true,
    albumArtToggle: toggle,
    trackInfo: {hidden: true},
    currentTrack: {artist: '', title: ''},
    isContentSuppressed() {return false;},
    saveRadioPreference(key, value) {saved.push({key,value});},
    Image: ImageStub,
    URL, URLSearchParams, AbortController,
    fetch(url, options) {
      calls.push({url,options});
      return new Promise((resolve,reject) => {
        pendingRequests.push({resolve,reject,options});
      });
    }
  });
  vm.runInContext(artworkLogic, ctx);
  const tick = () => new Promise(resolve => setImmediate(resolve));
  async function fulfill(data) {
    const req = pendingRequests.shift();
    assert.ok(req, 'expected pending request');
    req.resolve({ok:true, json: async()=>data});
    await tick();
    await tick();
  }
  return {ctx,container,image,credit,toggle,saved,calls,pendingImages,pendingRequests,
    tick, fulfill,
    update(artist,title) {return vm.runInContext(
      'updateTrackArtwork('+JSON.stringify(artist)+','+JSON.stringify(title)+')',ctx);},
    clear() {return vm.runInContext('clearTrackArtwork()',ctx);}
  };
}

const valid = {
  image: 'https://coverartarchive.org/release-group/70664047-2545-4e46-b75f-4556f2a7b83e/front-250',
  kind:'album',
  creditUrl:'https://musicbrainz.org/release-group/70664047-2545-4e46-b75f-4556f2a7b83e',
  creditLabel:'Cover Art Archive'
};

test('albumart is een laag achter het bestaande logo, zonder layoutverschuiving', () => {
  assert.match(html, /id="trackArtworkBackground"/);
  assert.match(html, /class="artwork-photo"/);
  assert.match(html, /class="artwork-tint"/);
  assert.match(html, /id="mainStationLogo"/);
  assert.match(html, /id="artworkCredit"/);
  assert.match(html, /\.artwork \{\s*position: relative;\s*aspect-ratio: 1;/);
  assert.match(html, /\.main-logo-surface \{\s*position: absolute;\s*z-index: 2;/);
  assert.match(html, /\.playing-indicator \{\s*position: absolute;[\s\S]*?z-index: 3;/);
  assert.match(html, /prefers-reduced-motion: reduce/);
});

test('bestaande artiest en titel laden albumart, pas tonen na succesvolle afbeeldingsload', async () => {
  const a = env();
  void a.update('Arctic Monkeys', 'Do I Wanna Know?');
  assert.equal(a.calls.length, 1);
  assert.match(a.calls[0].url, /^\/radio-artwork\.php\?/);
  assert.equal(new URL('https://rc045.nl'+a.calls[0].url).searchParams.get('artist'), 'Arctic Monkeys');
  assert.equal(a.container.classList.contains('has-track-artwork'), false);
  await a.fulfill(valid);
  assert.equal(a.pendingImages.length, 1);
  assert.equal(a.container.classList.contains('has-track-artwork'), false);
  a.pendingImages[0].onload();
  assert.equal(a.image.src, valid.image);
  assert.equal(a.credit.href, undefined);
  assert.equal(a.credit.textContent, '');
  assert.equal(a.credit.hidden, true);
  assert.equal(a.container.classList.contains('has-track-artwork'), true);
});

test('directe zenderwissel wist de achtergrond, oude verzoeken worden afgebroken', async () => {
  const a = env();
  void a.update('First', 'Track 1');
  const old = a.pendingRequests[0];
  void a.update('Second', 'Track 2');
  assert.equal(old.options.signal.aborted, true);
  assert.equal(a.calls.length, 2);
  await a.fulfill(valid);
  assert.equal(a.pendingImages.length, 0, 'oude resultaten niet meer getoond');
  await a.fulfill(valid);
  const latestImage = a.pendingImages.at(-1);
  assert.ok(latestImage);
  a.clear();
  assert.equal(a.image.src, undefined);
  assert.equal(a.credit.hidden, true);
  assert.equal(a.container.classList.contains('has-track-artwork'), false);
  assert.equal(latestImage.onload, null);
});

test('ongeldige afbeelding of ontbreken van albumart houdt kleurverloop zichtbaar', async () => {
  const a = env();
  void a.update('Artist', 'No art');
  await a.fulfill({image:null});
  assert.equal(a.pendingImages.length, 0);
  assert.equal(a.container.classList.contains('has-track-artwork'), false);

  void a.update('Artist2','Unsafe');
  await a.fulfill({image:'https://example.org/untrusted.svg',
    creditUrl:'https://example.org/credits',kind:'album'});
  assert.equal(a.pendingImages.length, 0);

  void a.update('Artist3','Broken image');
  await a.fulfill(valid);
  assert.equal(a.pendingImages.length,1);
  a.pendingImages[0].onerror();
  assert.equal(a.container.classList.contains('has-track-artwork'),false);
  assert.equal(a.credit.hidden,true);
});

test('zonder artiest of titel wordt geen verzoek verzonden; misses worden gecachet', async () => {
  const a = env();
  await a.update('', 'Song');
  assert.equal(a.calls.length,0);
  void a.update('Artist','Missing');
  await a.fulfill({image:null});
  await a.update('Artist','Missing');
  assert.equal(a.calls.length,1);
});

test('php-server gebruikt vaste upstream-domeinen, cache en MusicBrainz-ratelimit', () => {
  assert.ok(php.includes('https://musicbrainz.org/ws/2/'));
  assert.match(php,/https:\/\/coverartarchive\.org\/release-group\//);
  assert.match(php,/https:\/\/en\.wikipedia\.org\/w\/api\.php/);
  assert.match(php,/https:\/\/commons\.wikimedia\.org\/w\/api\.php/);
  assert.match(php,/function paceArtworkMusicBrainz\(/);
  assert.match(php,/flock\(/);
  assert.match(php,/musicbrainz-rate\.lock/);
  assert.match(php,/imageinfo[\s\S]*?extmetadata/);
  assert.match(php,/7 \* 86400/);
  assert.doesNotMatch(php,/file_put_contents\(\s*\$result\['image'\]/);
});

test('trackinfo-providers, intervals, logo, player links en audiorouting blijven', () => {
  assert.match(html,/const METADATA_REFRESH = 15000;/);
  assert.match(html,/const PROGRAM_REFRESH = 60000;/);
  assert.match(html,/function resolveMetadataProvider\(/);
  assert.match(html,/function refreshMetadata\(/);
  assert.match(html,/function refreshProgram\(/);
  assert.match(html,/function updateWikipediaLinks\(/);
  assert.match(html,/function updateMusicServiceLinks\(/);
  assert.match(html,/function updateMediaSession\(/);
  assert.match(html,/function clearTrack\(\) \{\s*clearTrackArtwork\(\);/);
  assert.match(html,/function setTrackLoading\(\) \{\s*clearTrackArtwork\(\);/);
  assert.match(html,/updateTrackArtwork\(artist, title\);/);
  assert.doesNotMatch(artworkLogic, /resolveMetadataProvider|METADATA_REFRESH|refreshMetadata\(\)/);
});


test('albumart vult de achtergrond met een klein zenderlogo linksboven', () => {
  assert.match(html, /\.artwork\.has-track-artwork \.main-logo-surface \{\s*top: 12px;\s*left: 12px;/);
  assert.match(html, /\.artwork\.has-track-artwork \.main-logo-surface[\s\S]*?width: clamp\(44px, 18%, 54px\)/);
  assert.match(html, /\.artwork\.has-track-artwork \.main-logo-surface[\s\S]*?background: rgba\(255,255,255,\.89\)/);
  assert.match(html, /\.artwork\.has-track-artwork::before,[\s\S]*?opacity: 0;/);
  assert.match(html, /backdrop-filter: blur\(12px\)/);
  assert.match(html, /\.artwork\.has-track-artwork \.artwork-photo \{\s*opacity: 1;/);
  assert.match(html, /\.main-logo-surface \{[\s\S]*?width: 58%;/);
  assert.match(html, /id="albumArtToggle" type="checkbox" checked/);
  assert.match(html, /<span>Albumart tonen<\/span>/);
});

test('albumart uit voorkomt elk verzoek en schakelt bestaande achtergrond onmiddellijk uit', async () => {
  const a = env();
  void a.update('Old artist', 'Old track');
  const first = a.pendingRequests[0];
  a.toggle.checked = false;
  a.toggle.listeners.change();
  assert.equal(a.ctx.albumArtEnabled, false);
  assert.equal(first.options.signal.aborted, true);
  assert.equal(a.container.classList.contains('has-track-artwork'), false);
  assert.equal(a.image.src, undefined);
  assert.equal(a.credit.hidden, true);
  assert.deepEqual(a.saved.at(-1), {key:'radio-albumart-visible',value:false});

  await a.update('Some artist','Some title');
  assert.equal(a.calls.length, 1, 'geen nieuwe requests als de toggle uit is');
});

test('albumart aan haalt de hoes van het zichtbare nummer op zonder nieuwe trackherkenning', async () => {
  const a = env();
  a.toggle.checked = false;
  a.toggle.listeners.change();
  a.ctx.trackInfo.hidden = false;
  a.ctx.currentTrack = {artist: 'Arctic Monkeys', title:'505'};
  a.toggle.checked = true;
  a.toggle.listeners.change();
  assert.equal(a.ctx.albumArtEnabled, true);
  assert.equal(a.calls.length, 1);
  assert.equal(new URL('https://rc045.nl'+a.calls[0].url).searchParams.get('title'), '505');
  assert.deepEqual(a.saved.at(-1), {key:'radio-albumart-visible',value:true});

  await a.fulfill(valid);
  a.pendingImages[0].onload();
  assert.equal(a.container.classList.contains('has-track-artwork'),true);
  a.toggle.checked = false;
  a.toggle.listeners.change();
  assert.equal(a.container.classList.contains('has-track-artwork'),false);
});

test('artiestenfoto toont auteur/licentie en heeft een klikbare Commons-vermelding', async () => {
  const a = env();
  const artistPhoto = {
    image: 'https://upload.wikimedia.org/wikipedia/commons/thumb/a/ab/Band.jpg/500px-Band.jpg',
    creditUrl: 'https://commons.wikimedia.org/wiki/File:Band.jpg',
    kind: 'artist',
    creditLabel: 'Foto: Jane Photographer · CC BY-SA 4.0'
  };
  void a.update('Artist','Track');
  await a.fulfill(artistPhoto);
  assert.equal(a.pendingImages.length,1);
  a.pendingImages[0].onload();
  assert.equal(a.credit.hidden,false);
  assert.equal(a.credit.href,artistPhoto.creditUrl);
  assert.equal(a.credit.textContent,artistPhoto.creditLabel);
  assert.match(photoSelector, /extmetadata.*Artist|extmetadata'\]\['Artist'/);
  assert.match(photoSelector, /creditLabel'\s*=>\s*'Foto: '/);
});

test('voorkeur wordt via bestaande veilige localStorage-helper gelezen en opgeslagen', () => {
  assert.match(html,/let albumArtEnabled = readRadioPreference\("radio-albumart-visible"\) !== "false";/);
  assert.match(html,/saveRadioPreference\("radio-albumart-visible", albumArtEnabled\)/);
  assert.match(html,/albumArtToggle\.checked = albumArtEnabled/);
});


test('backend rangschikt uitsluitend echte artiestreleases, geen compilaties', () => {
  const selection = fs.readFileSync(path.join(root, 'radio-artwork-release-select.php'), 'utf8');
  assert.match(php, /require_once __DIR__ \. '\/radio-artwork-release-select\.php'/);
  assert.match(php, /rc045-radio-artwork-v6/);
  assert.ok(php.includes('function artworkIdentifyArtist('));
  assert.match(php, /findAlbumArtwork\(\$artist,\s*\$title,\s*\$dir\)/);
  assert.match(selection, /secondary-types/);
  assert.ok(selection.includes("array('compilation','live','remix','mixtape'"));
  assert.match(selection, /release\['artist-credit'\]/);
});

test('soundtrack single artwork fallback only after normal cover lookup', () => {
  assert.ok(php.includes("musicBrainzArtwork('release-group'"));
  assert.ok(php.includes('artworkRankedSingles('));
  assert.ok(php.includes("$singles['release-groups']"));
  const helper = fs.readFileSync(path.join(root, 'radio-artwork-release-select.php'), 'utf8');
  assert.ok(helper.includes("array_diff($types,array('soundtrack'))"));
  assert.ok(helper.includes("$group['primary-type']"));
});

test('500px-hoes wordt op 250px herprobeerd, zonder verkeerde afbeelding te tonen', async () => {
  const a = env();
  const hd = {...valid,
    image: valid.image.replace('/front-250','/front-500'),
    imageFallback: valid.image};
  void a.update('ABBA','Dancing Queen');
  await a.fulfill(hd);
  assert.equal(a.pendingImages.length,1);
  a.pendingImages[0].onerror();
  assert.equal(a.pendingImages.length,2);
  a.pendingImages[1].onload();
  assert.equal(a.image.src,valid.image);
  assert.equal(a.container.classList.contains('has-track-artwork'),true);
});

test('onbetrouwbare fallback-URL en andere hosts worden genegeerd', async () => {
  const a = env();
  void a.update('P!nk','Sober');
  await a.fulfill({...valid, image:valid.image.replace('/front-250','/front-500'),
    imageFallback:'https://example.com/unsafe.jpg'});
  a.pendingImages[0].onerror();
  assert.equal(a.pendingImages.length,1);
  assert.equal(a.container.classList.contains('has-track-artwork'),false);
});

test('server onderscheidt tijdelijke fouten van echte missers en beperkt API-verzoeken', () => {
  assert.match(php, /'status'=>'temporary_error'/);
  assert.ok(php.includes("'not_found'"));
  assert.ok(php.includes('7 * 86400'));
  assert.ok(php.includes('45 * 60'));
  assert.ok(php.includes("'temporary_error') ? 30"));
  assert.ok(php.includes("['musicbrainz'] >= 5"));
  assert.ok(php.includes('count($seen) >= $maxChecks'));
  assert.ok(php.includes('LOCK_EX|LOCK_NB') || php.includes('LOCK_EX | LOCK_NB'));
  assert.ok(html.includes('data.status === "temporary_error" ? 30000'));
});

test('LIVE blijft altijd compact met of zonder album- of artiestenart', () => {
  assert.ok(html.includes('LIVE behoudt altijd dezelfde compacte maat'));
  assert.match(html, /\.playing-indicator\s*\{[^}]*height:\s*24px;/s);
  assert.match(html, /\.live-dot\s*\{[^}]*width:\s*5px;/s);
});

test('foto wordt direct getoond als de server geen geschikte albumhoes vond', async () => {
  const a = env();
  const photo = {
    image:'https://upload.wikimedia.org/wikipedia/commons/thumb/a/ab/Artist.jpg/500px-Artist.jpg',
    kind:'artist',creditUrl:'https://commons.wikimedia.org/wiki/File:Artist.jpg',
    creditLabel:'Foto: Photographer · CC BY-SA 4.0'
  };
  void a.update('Artist','Unknown recording');
  await a.fulfill(photo);
  assert.equal(a.calls.length,1,'geen herhaalde metadata- of albumzoektocht');
  a.pendingImages[0].onload();
  assert.equal(a.container.classList.contains('has-track-artwork'),true);
  assert.equal(a.image.src,photo.image);
  assert.equal(a.credit.textContent,photo.creditLabel);
  assert.equal(a.credit.hidden,false);
});

test('kapotte albumhoes met mislukte 250px-fallback zoekt artiestenfoto zonder MusicBrainz', async () => {
  const a = env();
  void a.update('Example Artist','Example Song');
  const cover = {...valid,image:valid.image.replace('front-250','front-500'),
    imageFallback:valid.image};
  await a.fulfill(cover);
  a.pendingImages[0].onerror();
  assert.equal(a.pendingImages.length,2,'250px wordt eerst geprobeerd');
  a.pendingImages[1].onerror();
  assert.equal(a.calls.length,2,'alleen artist-only verzoek bij falende albumimage');
  const lookup=new URL('https://rc045.nl'+a.calls[1].url);
  assert.equal(lookup.searchParams.get('fallback'),'artist');
  assert.equal(lookup.searchParams.get('artist'),'Example Artist');
  assert.equal(lookup.searchParams.get('title'),'Example Song');
  assert.equal(a.container.classList.contains('has-track-artwork'),false);
  const photo={image:'https://upload.wikimedia.org/wikipedia/commons/thumb/a/ab/Artist.jpg/500px-Artist.jpg',
    kind:'artist',creditUrl:'https://commons.wikimedia.org/wiki/File:Artist.jpg',
    creditLabel:'Foto: Photographer · CC BY 4.0'};
  await a.fulfill(photo);
  assert.equal(a.pendingImages.length,3);
  a.pendingImages[2].onload();
  assert.equal(a.image.src,photo.image);
  assert.equal(a.credit.hidden,false);
  assert.equal(a.container.classList.contains('has-track-artwork'),true);
});

test('zonder geldige artiestenfoto blijft stationskleur zichtbaar en wordt niet gespamd', async () => {
  const a=env();
  void a.update('Unknown band','Unknown song');
  await a.fulfill(valid);
  a.pendingImages[0].onerror();
  assert.equal(a.calls.length,2);
  await a.fulfill({image:null,status:'not_found'});
  assert.equal(a.image.src,undefined);
  assert.equal(a.container.classList.contains('has-track-artwork'),false);
  await a.update('Unknown band','Unknown song');
  assert.equal(a.calls.length,2,'de artist-only misser wordt kort hergebruikt');
});

test('albumart uit stopt ook lopende artiestenfoto-fallback en toont geen oude foto', async () => {
  const a=env();
  void a.update('Band','Song');
  await a.fulfill(valid);
  a.pendingImages[0].onerror();
  const request=a.pendingRequests.at(-1);
  assert.ok(request);
  a.toggle.checked=false;
  a.toggle.listeners.change();
  assert.equal(request.options.signal.aborted,true);
  await a.fulfill({image:'https://upload.wikimedia.org/wikipedia/commons/thumb/a/ab/Artist.jpg/500px-Artist.jpg',
    kind:'artist',creditUrl:'https://commons.wikimedia.org/wiki/File:Artist.jpg',
    creditLabel:'Foto: Photographer · CC BY 4.0'});
  assert.equal(a.container.classList.contains('has-track-artwork'),false);
  assert.equal(a.pendingImages.length,1,'late artiestenfoto mag niet gepreload worden');
});

test('PHP reserveert tijd voor artiestenfoto en hergebruikt artist-only cache', () => {
  assert.ok(php.includes('function cachedArtistArtwork('));
  assert.ok(php.includes('function findArtistArtwork('));
  assert.ok(php.includes('https://nl.wikipedia.org/w/api.php'));
  assert.ok(php.includes("$artistOnly = ($_GET['fallback'] ?? '') === 'artist';"));
  assert.ok(php.includes('microtime(true) + 14'));
  assert.ok(php.includes('microtime(true) + 9.5'));
  assert.ok(php.includes('radio-artwork-photo-select.php'));
});
