'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const root = path.join(__dirname, '..');
const html = fs.readFileSync(path.join(root, 'radio.html'), 'utf8');
const asset = fs.readFileSync(path.join(root, 'assets/radio/spotify.svg'), 'utf8');
function section(from, to) {
  const begin = html.indexOf(from);
  const end = html.indexOf(to, begin + from.length);
  assert.ok(begin >= 0 && end > begin, 'missing segment: ' + from);
  return html.slice(begin, end);
}

function element() {
  const attrs = new Map();
  return {
    hidden: false, textContent: '', title: '',
    style: {}, classList: {remove() {}},
    setAttribute(k, v) { attrs.set(k,v); },
    removeAttribute(k) { attrs.delete(k); if (k === 'href') this.href = undefined; },
    getAttribute(k) { return attrs.get(k); }
  };
}

function harness() {
  const ctx = {
    trackInfo: element(), trackArtist: element(), trackTitle: element(),
    spotifyTrackLink: element(), musicServiceLinks: element(),
    appleMusicTrackLink: element(), deezerTrackLink: element(),
    tidalTrackLink: element(), wikipediaGeneration: 0,
    currentTrack: {artist:'', title:''}, currentTrackUpdatedAt: 0,
    blocked: false, mediaUpdates: 0, wikiUpdates: 0,
    isContentSuppressed() { return ctx.blocked; },
    cleanText(s) { return String(s || '').trim(); },
    updateWikipediaLinks() { ctx.wikiUpdates++; },
    updateMediaSession() { ctx.mediaUpdates++; }
  };
  // Het artworkdeel heeft eigen regressietests. Houd deze oude
  // muzieklijntests gericht op de Spotify/Apple/Deezer/TIDAL logica.
  const source = section('function resetWikipediaLinks()', '/* =========================================================\n   PROGRAM UI');
  const from = source.indexOf('/* =========================================================\n   OPTIONAL ALBUM / ARTIST BACKGROUND');
  const to = source.indexOf('function setTrackLoading()', from);
  assert.ok(from > 0 && to > from);
  const js = source.slice(0, from) + source.slice(to);
  const api = new Function('ctx',
    'with(ctx) { function clearTrackArtwork() {} function updateTrackArtwork() {} ' + js +
    '\nreturn {setTrack,setTrackLoading,clearTrack,updateSpotifyLink};}')(ctx);
  return {ctx,api};
}

test('het officiële Spotify-icoon wordt vanaf eigen hosting geladen, minimaal 21px', () => {
  assert.match(html, /id="spotifyTrackLink"/);
  assert.match(html, /src="\/assets\/radio\/spotify\.svg"/);
  assert.match(html, /width="22"\s+height="22"/);
  assert.match(html, /rel="noopener noreferrer"/);
  assert.match(html, /target="_blank"/);
  assert.match(asset, /^<svg\b/);
  assert.match(asset, /fill="#1ED760"/);
  assert.match(asset, /viewBox="0 0 24 24"/);
});

test('herkende nummers tonen een Spotify-zoeklink, zonder Spotify-API', () => {
  const {ctx,api} = harness();
  assert.equal(ctx.spotifyTrackLink.hidden, false); // harness input initial state
  api.setTrackLoading();
  assert.equal(ctx.spotifyTrackLink.hidden, true);
  assert.equal(ctx.spotifyTrackLink.href, undefined);

  assert.equal(api.setTrack('Beyoncé', 'Halo (Live) & More'), true);
  assert.equal(ctx.trackInfo.hidden, false);
  assert.equal(ctx.spotifyTrackLink.hidden, false);
  assert.equal(ctx.spotifyTrackLink.href,
    'https://open.spotify.com/search/Beyonc%C3%A9%20Halo%20(Live)%20%26%20More');
  assert.equal(ctx.spotifyTrackLink.getAttribute('aria-label'),
    'Zoek Beyoncé – Halo (Live) & More op Spotify');
  assert.equal(ctx.trackArtist.textContent, 'Beyoncé');
  assert.equal(ctx.trackTitle.textContent, 'Halo (Live) & More');

  assert.equal(api.setTrack('', 'Instrumental'), true);
  assert.equal(ctx.spotifyTrackLink.href, 'https://open.spotify.com/search/Instrumental');
  assert.equal(ctx.spotifyTrackLink.hidden, false);
});

test('bij talk/noTrack, laadstatus en wisselen verdwijnt de oude Spotify-link', () => {
  const {ctx,api} = harness();
  api.setTrack('Artist', 'Old track');
  assert.equal(ctx.spotifyTrackLink.hidden, false);
  api.clearTrack();
  assert.equal(ctx.spotifyTrackLink.hidden, true);
  assert.equal(ctx.spotifyTrackLink.href, undefined);
  api.setTrack('Artist', 'New track');
  assert.equal(ctx.spotifyTrackLink.href, 'https://open.spotify.com/search/Artist%20New%20track');
  api.setTrackLoading();
  assert.equal(ctx.spotifyTrackLink.hidden, true);
  assert.equal(ctx.spotifyTrackLink.href, undefined);
  // Huidige track ongewijzigd: setTrack() herstelt link in unchanged-branch.
  api.setTrack('Artist', 'New track');
  assert.equal(ctx.spotifyTrackLink.hidden, false);
  ctx.blocked = true;
  api.setTrack('Artist', 'New track');
  assert.equal(ctx.spotifyTrackLink.hidden, true);
  assert.equal(ctx.spotifyTrackLink.href, undefined);
});

test('geen wijzigingen in providers, audio, stations of Wikipedia-links', () => {
  assert.match(html, /const METADATA_REFRESH = 15000;/);
  assert.match(html, /const PROGRAM_REFRESH = 60000;/);
  assert.match(html, /function updateWikipediaLinks\(/);
  assert.match(html, /resetWikipediaLinks\(\)/);
  assert.match(html, /function refreshMetadata\(/);
  assert.match(html, /function refreshProgram\(/);
  assert.match(html, /src="\/assets\/radio\/spotify\.svg"/);
  assert.doesNotMatch(section('function updateSpotifyLink(', '/* =========================================================\n   OPTIONAL ALBUM / ARTIST BACKGROUND'),
    /fetch\s*\(|XMLHttpRequest|Spotify\.WebApi|access_token/);
});


test('Apple Music, Deezer en TIDAL tonen lokaal gehoste SVG-iconen', () => {
  for (const [id, file] of [
    ['appleMusicTrackLink', 'apple-music.svg'],
    ['deezerTrackLink', 'deezer.svg'],
    ['tidalTrackLink', 'tidal.svg']
  ]) {
    assert.match(html, new RegExp('id="' + id + '"'));
    assert.match(html, new RegExp('src="/assets/radio/' + file.replace('.', '\\.') + '"'));
    const icon = fs.readFileSync(path.join(root, 'assets/radio', file), 'utf8');
    assert.match(icon, /^<svg\b/);
    assert.match(icon, /viewBox="0 0 24 24"/);
    assert.match(icon, /<path\b/);
    assert.doesNotMatch(icon, /<image\b|<script\b|href=["']https?:\/\//i);
  }
  assert.match(html, /id="musicServiceLinks"/);
  assert.match(html, /\.music-service-links\[hidden\]/);
  assert.match(html, /\.music-service-link\[hidden\]/);
});

test('alle vier diensten zoeken dezelfde herkende titel en artiest', () => {
  const {ctx,api} = harness();
  api.setTrackLoading();
  assert.equal(ctx.musicServiceLinks.hidden, true);

  api.setTrack('Beyoncé', 'Halo (Live) & More');
  const query = 'Beyonc%C3%A9%20Halo%20(Live)%20%26%20More';
  const expected = [
    [ctx.spotifyTrackLink, 'Spotify', 'https://open.spotify.com/search/' + query],
    [ctx.appleMusicTrackLink, 'Apple Music', 'https://music.apple.com/nl/search?term=' + query],
    [ctx.deezerTrackLink, 'Deezer', 'https://www.deezer.com/search/' + query],
    [ctx.tidalTrackLink, 'TIDAL', 'https://tidal.com/search/tracks?q=' + query]
  ];
  assert.equal(ctx.musicServiceLinks.hidden, false);
  for (const [link, service, url] of expected) {
    assert.equal(link.hidden, false, service);
    assert.equal(link.href, url, service);
    assert.equal(link.getAttribute('aria-label'), 'Zoek Beyoncé – Halo (Live) & More op ' + service);
    assert.equal(link.title, 'Zoek op ' + service);
  }

  api.setTrack('', 'Instrumental');
  for (const [link] of expected) {
    assert.equal(link.hidden, false);
    assert.match(link.href, /Instrumental$/);
  }
});

test('oude links verdwijnen bij nieuw station, talk, loading en onderdrukking', () => {
  const {ctx,api} = harness();
  const all = [ctx.spotifyTrackLink, ctx.appleMusicTrackLink,
    ctx.deezerTrackLink, ctx.tidalTrackLink];
  function assertGone() {
    assert.equal(ctx.musicServiceLinks.hidden, true);
    for (const item of all) {
      assert.equal(item.hidden, true);
      assert.equal(item.href, undefined);
      assert.equal(item.getAttribute('aria-label'), undefined);
    }
  }
  api.setTrack('Old artist', 'Old title');
  api.clearTrack();
  assertGone();

  api.setTrack('New artist', 'New title');
  for (const item of all) {
    assert.match(item.href, /New%20artist%20New%20title$/);
  }
  api.setTrackLoading();
  assertGone();

  // Ongewijzigde track na loading herstelt alle vier links.
  api.setTrack('New artist', 'New title');
  assert.equal(ctx.musicServiceLinks.hidden, false);
  ctx.blocked = true;
  api.setTrack('New artist', 'New title');
  assertGone();
});

test('geen nieuwe metadata-API of wijziging van verversingsintervallen', () => {
  const searchLogic = section('function updateOtherMusicLinks(', '/* =========================================================\n   OPTIONAL ALBUM / ARTIST BACKGROUND');
  assert.doesNotMatch(searchLogic, /\bfetch\s*\(|XMLHttpRequest|access_token|client_secret/);
  assert.match(html, /const METADATA_REFRESH = 15000;/);
  assert.match(html, /const PROGRAM_REFRESH = 60000;/);
  assert.match(html, /function refreshMetadata\(/);
  assert.match(html, /function refreshProgram\(/);
  assert.match(html, /function updateWikipediaLinks\(/);
});
