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
    spotifyTrackLink: element(), wikipediaGeneration: 0,
    currentTrack: {artist:'', title:''}, currentTrackUpdatedAt: 0,
    blocked: false, mediaUpdates: 0, wikiUpdates: 0,
    isContentSuppressed() { return ctx.blocked; },
    cleanText(s) { return String(s || '').trim(); },
    updateWikipediaLinks() { ctx.wikiUpdates++; },
    updateMediaSession() { ctx.mediaUpdates++; }
  };
  const js = section('function resetWikipediaLinks()', '/* =========================================================\n   PROGRAM UI');
  const api = new Function('ctx', 'with(ctx) {' + js +
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
  assert.doesNotMatch(section('function updateSpotifyLink(', 'function setTrackLoading()'),
    /fetch\s*\(|XMLHttpRequest|Spotify\.WebApi|access_token/);
});
