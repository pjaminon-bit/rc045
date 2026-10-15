'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.join(__dirname, '..');
const html = fs.readFileSync(path.join(root, 'radio.html'), 'utf8');
const php = fs.readFileSync(path.join(root, 'radio-artwork.php'), 'utf8');
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
  return {ctx,container,image,credit,calls,pendingImages,pendingRequests,
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
  assert.match(html, /\.main-logo-surface \{\s*position: relative;\s*z-index: 2;/);
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
  assert.equal(a.credit.href, valid.creditUrl);
  assert.equal(a.credit.textContent, 'Cover Art Archive');
  assert.equal(a.credit.hidden, false);
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
  assert.match(php,/https:\/\/musicbrainz\.org\/ws\/2\/recording\//);
  assert.match(php,/https:\/\/coverartarchive\.org\/release-group\//);
  assert.match(php,/https:\/\/en\.wikipedia\.org\/w\/api\.php/);
  assert.match(php,/https:\/\/commons\.wikimedia\.org\/w\/api\.php/);
  assert.match(php,/function paceArtworkMusicBrainz\(/);
  assert.match(php,/flock\(/);
  assert.match(php,/musicbrainz-rate\.lock/);
  assert.match(php,/imageinfo.*extmetadata/);
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
