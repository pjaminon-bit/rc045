'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const html = fs.readFileSync(path.join(__dirname, '..', 'radio.html'), 'utf8');
function part(from, until) {
  const start = html.indexOf(from);
  const end = html.indexOf(until, start + from.length);
  assert.ok(start >= 0 && end > start, 'sectie moet bestaan: ' + from);
  return html.slice(start, end);
}

test('één centraal HTML-audio-element voor beide players', () => {
  assert.equal((html.match(/<audio\b/gi) || []).length, 1);
  assert.match(html, /id="audio"/);
  assert.match(html, /id="miniPlayer"/);
  assert.match(html, /miniPlay\.addEventListener\(/);
  assert.match(html, /togglePlay\(\)/);
});

test('bestaande PWA, zenderzoekfunctie en trackstructuur aanwezig', () => {
  for (const element of ['stationSearchInput', 'stationSearchClear', 'trackInfo',
    'trackArtist', 'trackTitle', 'miniPlayer']) {
    assert.ok(html.includes('id="' + element + '"'), element);
  }
  assert.match(html, /rel="manifest"/);
  assert.match(html, /function refreshMetadata\(/);
  assert.match(html, /function refreshProgram\(/);
  assert.match(html, /function splitTrack\(/);
});

test('geen technische diagnose, zonder andere playerfuncties te verliezen', () => {
  assert.doesNotMatch(html, /radioDiagnostic(?:s|\()/);
  assert.doesNotMatch(html, /radio-diagnostics/);
  assert.doesNotMatch(html, /radio:diagnostic/);
  assert.doesNotMatch(html, /Technische diagnose/);
  assert.match(html, /function scheduleReconnect\(/);
  assert.match(html, /async function play\(/);
});

test('Licht/Donker: zonder voorkeur OS volgen, daarna handmatig bewaren', () => {
  const choices = [...html.matchAll(/data-theme-choice="([^"]+)"/g)].map(m => m[1]);
  assert.deepEqual(choices, ['light', 'dark']);
  assert.doesNotMatch(html, /theme-option-system/);
  const themeJS = part('const THEME_KEY =',
    '/* =========================================================\n   FAVORITES / COUNTRIES\n');
  const storage = new Map(), query = {
    matches: true, addEventListener(_event, callback) { this.changed = callback; }
  };
  const buttons = choices.map(choice => ({
    dataset: {themeChoice: choice}, attrs: {},
    addEventListener() {}, classList: {toggle() {}},
    setAttribute(key,val) {this.attrs[key] = val;}
  }));
  const switcher = { dataset: {}, setAttribute() {} }, themeColor = {content: ''};
  const ctx = {
    document: {documentElement: {dataset: {theme: 'system'}}},
    window: {matchMedia() {return query;}}, themeColor,
    themeSwitcher: switcher, themeButtons: buttons,
    localStorage: {getItem(k) {return storage.get(k) ?? null;},
      setItem(k,v) {storage.set(k,v);}}
  };
  const api = new Function('ctx',
    'with(ctx){' + themeJS + '\nreturn {applyTheme,getResolvedTheme};}'
  )(ctx);
  assert.equal(switcher.dataset.state, 'light');
  assert.equal(storage.has('radio-theme'), false);
  query.matches = false; query.changed();
  assert.equal(switcher.dataset.state, 'dark');
  assert.equal(themeColor.content, '#080a0f');
  api.applyTheme('light');
  assert.equal(storage.get('radio-theme'), 'light');
  query.matches = true; query.changed();
  query.matches = false; query.changed();
  assert.equal(switcher.dataset.state, 'light');
  assert.equal(buttons[0].attrs['aria-pressed'], 'true');
});

test('alle inline scripts zijn syntactisch geldig', () => {
  const scripts = [...html.matchAll(/<script\b([^>]*)>([\s\S]*?)<\/script>/g)]
    .filter(m => !m[1].includes('src='));
  assert.ok(scripts.length >= 6);
  scripts.forEach((s, index) => {
    assert.doesNotThrow(() => new vm.Script(s[2]), 'script ' + index);
  });
});

test('voorkeuren, logooptimalisatie en a11y blijven aanwezig', () => {
  assert.match(html, /function saveRadioPreference\(/);
  assert.match(html, /"radio-station-id"/);
  assert.match(html, /"radio-favorites"/);
  assert.match(html, /"radio-country-collapsed"/);
  assert.match(html, /"radio-volume"/);
  assert.match(html, /"radio-muted"/);
  assert.match(html, /decoding="async"/);
  assert.match(html, /loading="lazy"/);
  assert.match(html, /aria-pressed/);
  assert.match(html, /aria-current/);
  assert.match(html, /role="status"/);
});

function makeAudioEnvironment(playImpl) {
  let timerId = 0, playCalls = 0;
  const timers = new Map();
  const ctx = {
    document: { body: { classList: { remove() {} } } },
    status: { textContent: '' },
    window: {
      setTimeout(callback, ms) {
        const id = ++timerId;
        timers.set(id, { callback, ms });
        return id;
      }
    },
    navigator: { onLine: true },
    HTMLMediaElement: { HAVE_FUTURE_DATA: 3 },
    clearTimeout(id) { timers.delete(id); },
    audio: {
      paused: true, readyState: 0, src: 'stream',
      play() { ++playCalls; return playImpl(); },
      pause() { this.paused = true; },
      load() {}
    },
    beginPreRollGuard() {},
    isCasting() { return false; },
    loadCurrentStationOnCast() {},
    castLoadedStationId: '', castPlayer: null, castPlayerController: null,
    stations: [{ id: 'a', stream: 'stream-a' }], currentIndex: 0,
    playbackRequested: false, playInFlight: null, playSequence: 0,
    reconnectInFlight: false, reconnectTimer: null, reconnectAttempts: 0,
    MAX_RECONNECT_ATTEMPTS: 5
  };
  ctx.isPlaybackActive = () => !ctx.audio.paused;

  // Alleen audiobediening en herstel worden uitgevoerd; de originele
  // metadata-/trackprogramma's worden NIET geladen of aangeroepen.
  const js =
    part('function cancelReconnect()', '/* =========================================================\n   AUDIO\n') +
    part('async function play()', 'playButton.addEventListener(');
  const actions = new Function('ctx', 'with(ctx) {' + js +
    '\nreturn {play, pause, togglePlay, scheduleReconnect};}')(ctx);
  return { ctx, actions, timers, calls: () => playCalls };
}

test('gelijktijdige Play-acties veroorzaken slechts één audio.play()', async () => {
  let rejectPlay;
  const sim = makeAudioEnvironment(() =>
    new Promise((_resolve, reject) => { rejectPlay = reject; }));
  const a = sim.actions.play();
  const b = sim.actions.play();
  assert.equal(sim.calls(), 1);
  sim.actions.togglePlay();
  rejectPlay(Object.assign(new Error('user paused'), { name: 'AbortError' }));
  await Promise.all([a, b]);
  assert.equal(sim.ctx.playbackRequested, false);
  assert.equal(sim.timers.size, 0);
});

test('iOS-autoplayblokkade geeft geen automatische herhaallus', async () => {
  const sim = makeAudioEnvironment(() =>
    Promise.reject(Object.assign(new Error('gesture required'), {
      name: 'NotAllowedError'
    })));
  await sim.actions.play();
  assert.equal(sim.ctx.playbackRequested, false);
  assert.equal(sim.timers.size, 0);
});

test('herstel wacht steeds langer en stopt na vijf pogingen', async () => {
  const sim = makeAudioEnvironment(() =>
    Promise.reject(Object.assign(new Error('network'), { name: 'NetworkError' })));
  await sim.actions.play();
  const delays = [];
  for (let i = 0; i < 7 && sim.timers.size; ++i) {
    const [id, timer] = sim.timers.entries().next().value;
    sim.timers.delete(id);
    delays.push(timer.ms);
    await timer.callback();
  }
  assert.deepEqual(delays, [1100, 1800, 3600, 7200, 14400]);
  assert.equal(sim.ctx.reconnectAttempts, 5);
  assert.equal(sim.timers.size, 0);
  assert.match(sim.ctx.status.textContent, /Verbinding verbroken/);
});

test('handmatig pauzeren of offline gaan stopt herstel', async () => {
  const sim = makeAudioEnvironment(() =>
    Promise.reject(Object.assign(new Error('network'), { name: 'NetworkError' })));
  await sim.actions.play();
  assert.equal(sim.timers.size, 1);
  sim.actions.pause();
  assert.equal(sim.timers.size, 0);
  sim.ctx.playbackRequested = true;
  sim.ctx.navigator.onLine = false;
  sim.actions.scheduleReconnect('offline');
  assert.equal(sim.timers.size, 0);
});
