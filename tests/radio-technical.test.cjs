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

test('alle 13 regionale publieke omroepen inclusief dubbel Zuid-Holland', () => {
  const st = html.slice(html.indexOf('const stations = ['), html.indexOf('/* =========================================================\n   ELEMENTS'));
  const entries = [...st.matchAll(/id: "([^"]+)",\s*country: "(NL|BE|LOCAL_NL)"/g)];
  assert.equal(entries.length, 56);
  assert.equal(new Set(entries.map(e => e[1])).size, 56);
  const locals = entries.filter(e => e[2] === 'LOCAL_NL').map(e => e[1]).sort();
  assert.deepEqual(locals, ["l1radio","nhradio","omroepbrabant","omroepflevoland","omroepgelderland","omroepwest","omroepzeeland","omropfryslan","radiomutrecht","rtvdrenthe","rtvnoord","rtvoost","rtvrijnmond"]);
  assert.equal(entries.filter(e => e[2] === 'NL').length, 34);
  assert.equal(entries.filter(e => e[2] === 'BE').length, 9);
  assert.equal(entries.filter(e => e[2] === 'LOCAL_NL').length, 13);
  for (const province of ['Groningen','Friesland','Drenthe','Overijssel','Flevoland',
   'Gelderland','Utrecht','Noord-Holland','Zuid-Holland','Zeeland','Noord-Brabant','Limburg']) {
    assert.ok(st.includes('province: "' + province + '"'), province);
  }
  assert.match(html, /class="station-province"/);
});

test('verouderde KINK Classics verdwijnt met behoud opgeslagen keuzes', () => {
  const st = html.slice(html.indexOf('const stations = ['), html.indexOf('/* =========================================================\n   ELEMENTS'));
  assert.doesNotMatch(st, /id: "kinkclassics"/);
  for (const [id,mount] of [['kink80s','KINK_DNA'],['kink90s','KINK_90S'],['kinkdistortion','KINK_DISTORTION']]) {
    const start = st.indexOf('id: "'+id+'"'), end = st.indexOf('\n  },',start);
    const piece=st.slice(start,end);
    assert.ok(start>=0 && end>start,id);
    assert.ok(piece.includes(mount),id);
  }
  assert.match(html, /previousStationId === "kinkclassics"/);
  assert.match(html, /favoriteIds\.delete\("kinkclassics"\)/);
  assert.match(html, /favoriteIds\.add\("kink80s"\)/);
});

test('NL / BE / Lokaal NL zijn echte toegankelijke tabs met zoek- en favorietenbehoud', () => {
  const names = [...html.matchAll(/data-radio-tab="([^"]+)"/g)].map(x => x[1]);
  assert.deepEqual(names, ['NL', 'LOCAL_NL', 'BE']);
  assert.match(html, /role="tablist"/);
  assert.match(html, /aria-selected="true"/);
  assert.match(html, /aria-labelledby", "radioTab-"/);
  assert.match(html, /saveRadioPreference\("radio-country-tab", code\)/);
  assert.match(html, /group\.dataset\.country !== activeTab/);
  assert.match(html, /renderStations\(\)/);
});

test('zendergroepen zijn direct zichtbaar en niet meer uitklapbaar', () => {
  assert.doesNotMatch(html, /class="country-(?:header|toggle|count|chevron)"/);
  assert.doesNotMatch(html, /function toggleCountryCollapsed\(/);
  assert.match(html, /group\.hidden = country\.code !== selectedTab/);
  assert.match(html, /getVisibleDisplayOrder\(\) \{[\s\S]*?return getDisplayOrder\(\)/);
  assert.match(html, /group\.dataset\.country !== activeTab/);
  assert.match(html, /function renderStations\(/);
  assert.doesNotMatch(html, /\$\{items\.length\} stations/);
  assert.doesNotMatch(html, /\$\{stations\.length\} stations/);
  assert.doesNotMatch(html, /van \$\{stations\.length\} radiostations/);
});

test('drie tabs en één contextafhankelijke favorietster zonder gegevensverlies', () => {
  const labels = [...html.matchAll(/data-radio-tab="(NL|BE|LOCAL_NL)"[^>]*>([^<]+)<\/button>/g)]
    .map(match => [match[1], match[2].trim()]);
  assert.deepEqual(labels, [['NL', 'NL'], ['LOCAL_NL', 'NL Lokaal'], ['BE', 'BE']]);
  assert.deepEqual([...html.matchAll(/data-country-favorite="([^"]+)"/g)].map(m => m[1]),['NL']);
  assert.match(html, /class="radio-country-favorite-wrap"/);
  assert.match(html, /saveRadioPreference\("radio-country-favorites"/);
  assert.match(html, /toggleCountryFavorite\(favorite\.dataset\.countryFavorite\)/);
  assert.match(html, /updateCountryFavoriteButtons\(\)/);

  // De ster verandert van regio, maar de volledige Set met
  // opgeslagen favoriete landen blijft bestaan.
  const code=part('function updateCountryFavoriteButtons()',
    '/* =========================================================\n   RENDER STATIONS');
  const storage=new Map([['radio-country-tab','NL']]);
  const button={
    dataset:{countryFavorite:'NL'},
    attrs:{},
    textContent:'',
    classList:{toggle(){}},
    setAttribute(key,value){this.attrs[key]=value;}
  };
  const favorites=new Set(['NL','BE']);
  const context={
    document:{querySelector(){return button;}},
    favoriteCountries:favorites,
    COUNTRIES:[{code:'NL',name:'Nederland'},{code:'LOCAL_NL',name:'NL Lokaal'},{code:'BE',name:'België'}],
    readRadioPreference(key){return storage.get(key)||null;}
  };
  const update=new Function('ctx','with(ctx){'+code+
    '\nreturn updateCountryFavoriteButtons;}')(context);
  update();
  assert.equal(button.dataset.countryFavorite,'NL');
  assert.equal(button.attrs['aria-pressed'],'true');
  storage.set('radio-country-tab','LOCAL_NL');
  update();
  assert.equal(button.dataset.countryFavorite,'LOCAL_NL');
  assert.equal(button.attrs['aria-pressed'],'false');
  storage.set('radio-country-tab','BE');
  update();
  assert.equal(button.dataset.countryFavorite,'BE');
  assert.equal(button.attrs['aria-pressed'],'true');
  assert.deepEqual([...favorites].sort(),['BE','NL']);
});

test('de originele 56 stations, metadata en programmaconfiguratie zijn beschermd', () => {
  const all = part('const stations = [', '/* =========================================================\n   ELEMENTS');
  const entries = [...all.matchAll(/id: "([^"]+)",\s*country: "(NL|BE|LOCAL_NL)"/g)];
  assert.equal(entries.length, 56);
  assert.equal(new Set(entries.map(m => m[1])).size, 56);
  for (const id of ['npo1','npo2','kink80s','omroepbrabant','l1radio']) {
    assert.match(all, new RegExp('id: "' + id + '"'));
  }
});


function makeWindowsMediaSessionHarness() {
  const events = new Map(), timers = new Map(), assignments = [];
  let counter = 0;
  const sessionState = {metadata: null, playbackState: 'none', setActionHandler() {}};
  const session = new Proxy(sessionState, {
    set(target,key,value) {
      if (key === 'metadata') assignments.push(value);
      target[key] = value;
      return true;
    }
  });
  const ctx = {
    navigator:{mediaSession:session},
    MediaMetadata: class { constructor(data) {Object.assign(this,data);} },
    stations: [
      {id:'3fm',name:'NPO 3FM',logo:'https://assets.example/3fm.png'},
      {id:'stubru',name:'Studio Brussel',logo:'https://assets.example/stubru.png'}
    ],
    currentIndex:0, currentTrack:{artist:'Artiest van 3FM',title:'Titel van 3FM'},
    currentProgram:'3FM programma',
    audio: {paused:true, addEventListener(event,callback){events.set(event,callback);}},
    isCasting(){return false;},
    getStationLogo(station){return station.logo;},
    window: {setTimeout(fn,ms){const id=++counter;timers.set(id,{fn,ms});return id;}},
    clearTimeout(id){timers.delete(id);},
    play(){},pause(){},nextStation(){},previousStation(){}
  };
  const source = part('let mediaSessionRefreshTimer = null;', '/* iOS Safari na BFCache');
  const api = new Function('ctx','with(ctx){'+source+
    '\nreturn {updateMediaSession,resetMediaSessionForStationChange};}')(ctx);
  return {ctx,api,session,events,timers,assignments};
}

test('Windows Firefox lockscreen krijgt Studio Brussel in plaats van 3FM na afspelen', () => {
  const testSession = makeWindowsMediaSessionHarness();
  const {ctx,api,session,events,timers,assignments} = testSession;
  api.updateMediaSession();
  assert.equal(session.metadata.album,'NPO 3FM');
  assert.equal(session.metadata.title,'Titel van 3FM');

  ctx.currentIndex=1;
  ctx.currentTrack={artist:'',title:''};
  ctx.currentProgram='';
  api.resetMediaSessionForStationChange();
  assert.equal(assignments.at(-1),null);
  assert.equal(session.playbackState,'none');

  api.updateMediaSession();
  assert.equal(session.metadata.title,'Studio Brussel');
  assert.equal(session.metadata.artist,'Live radio');
  assert.equal(session.metadata.album,'Studio Brussel');
  assert.equal(session.metadata.artwork[0].src,'https://assets.example/stubru.png');

  ctx.audio.paused=false;
  events.get('play')();
  events.get('playing')();
  assert.equal(session.playbackState,'playing');
  assert.equal(session.metadata.album,'Studio Brussel');
  assert.equal(timers.size,1);
  const [{fn,ms}]=[...timers.values()];
  assert.equal(ms,400);
  fn();
  assert.equal(session.metadata.album,'Studio Brussel');
  assert.equal(session.metadata.artwork[0].src,'https://assets.example/stubru.png');

  ctx.audio.paused=true;
  events.get('pause')();
  assert.equal(session.playbackState,'paused');
});

test('vertraagde Windows-mediakaart van vorig station wordt bij opnieuw wisselen geannuleerd', () => {
  const {ctx,api,session,events,timers}=makeWindowsMediaSessionHarness();
  ctx.audio.paused=false;
  events.get('playing')();
  assert.equal(timers.size,1);

  ctx.currentIndex=1;
  ctx.currentTrack={artist:'',title:''};
  ctx.currentProgram='';
  api.resetMediaSessionForStationChange();
  assert.equal(timers.size,0);
  api.updateMediaSession();
  events.get('playing')();
  assert.equal(timers.size,1);
  for (const {fn} of timers.values()) fn();
  assert.equal(session.metadata.album,'Studio Brussel');
  assert.equal(session.playbackState,'playing');
});


test('navigatie en zoekfunctie vormen een compacte, gecentreerde bedieningsgroep', () => {
  const toolbar=part('<!-- Compacte bediening:', '<div id="stationSearchSummary"');
  assert.match(toolbar, /class="station-toolbar"/);
  assert.ok(toolbar.indexOf('id="radioCountryTabs"') < toolbar.indexOf('class="station-search"'));
  assert.match(toolbar,/id="stationSearchInput"/);
  assert.deepEqual([...toolbar.matchAll(/data-radio-tab="([^"]+)"/g)].map(m=>m[1]),
    ['NL','LOCAL_NL','BE']);
  assert.equal([...toolbar.matchAll(/class="radio-country-favorite"/g)].length,1);
  assert.match(toolbar,/class="radio-country-favorite-wrap"/);
  assert.match(toolbar,/Zoek radiostation…/);
});

test('navigatie en zoeken zijn links verankerd aan zendergrid zonder zwevend kader', () => {
  const css=part('/* =========================================================\n       ZENDERZOEKEN EN COMPACTE ZENDERLIJST','</style>');
  const row=css.slice(css.indexOf('.station-toolbar {'),css.indexOf('.station-search svg {'));
  assert.match(row,/justify-content: flex-start/);
  assert.doesNotMatch(row,/justify-content: (?:center|space-between)/);
  assert.match(row,/gap: 14px/);
  assert.match(row,/width: min\(100%, 275px\)/);
  const nav=css.slice(css.indexOf('/* Lijn de bediening uit met de eerste zenderkaart'),css.indexOf('/* Alleen regionale zenderkaarten'));
  assert.match(nav,/width: max-content/);
  assert.match(nav,/background: transparent/);
  assert.match(nav,/\.radio-country-tab-list\s*\{\s*display: flex/);
  assert.match(nav,/\.radio-country-tab\[aria-selected="true"\]/);
  assert.doesNotMatch(nav,/\.radio-country-tab\[aria-selected="true"\]::after/);
  assert.match(nav,/@media \(max-width: 760px\)[\s\S]*?align-items: flex-start/);
  assert.match(nav,/@media \(max-width: 420px\)[\s\S]*?grid-template-columns: repeat\(3, minmax\(0, 1fr\)\)/);
  assert.match(nav,/\.radio-country-tabs,\s*\.station-search\s*\{\s*width: 100%/);
});

test('zenderkaarten houden het bestaande grid en breedte van vier kolommen', () => {
  const css=part('/* =========================================================\n       ZENDERZOEKEN EN COMPACTE ZENDERLIJST',
                 '</style>');
  assert.match(css, /\.country-stations\s*\{\s*grid-template-columns: repeat\(\s*auto-fit,\s*minmax\(min\(100%, 235px\), 1fr\)/);
  assert.match(css, /\.station-select\s*\{\s*flex-direction: row/);
  assert.match(html, /class="station-groups"/);
  assert.match(html, /grid\.className = "country-stations"/);
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


function makeBackgroundRefreshHarness() {
  const pendingMetadata = [], pendingProgram = [];
  let playing = true;
  const document = { visibilityState: 'hidden' };
  const ctx = {
    document, currentIndex: 0, metadataGeneration: 0, programGeneration: 0,
    isPlaybackActive: () => playing,
    performMetadataRefresh: () => new Promise(resolve => pendingMetadata.push(resolve)),
    performProgramRefresh: () => new Promise(resolve => pendingProgram.push(resolve))
  };
  // Alleen de nieuwe scheduling-wrappers worden uitgevoerd, niet de
  // bevroren metadata- of programmaproviders.
  const js =
    part('function shouldRefreshInBackground()', 'async function performMetadataRefresh()') +
    part('let programRefreshFlight = null;', 'async function performProgramRefresh()');
  const api = new Function('ctx', 'with(ctx) {' + js +
    '\nreturn {refreshMetadata,refreshProgram};}')(ctx);
  return {
    ctx, document, api, pendingMetadata, pendingProgram,
    setPlaying: value => { playing = value; }
  };
}

test('trackinfo en programma verversen bij verborgen tab zolang radio speelt', async () => {
  const sim = makeBackgroundRefreshHarness();
  const firstTrack = sim.api.refreshMetadata();
  const duplicateTrack = sim.api.refreshMetadata();
  const firstProgram = sim.api.refreshProgram();
  const duplicateProgram = sim.api.refreshProgram();
  assert.strictEqual(firstTrack, duplicateTrack);
  assert.strictEqual(firstProgram, duplicateProgram);
  assert.equal(sim.pendingMetadata.length, 1);
  assert.equal(sim.pendingProgram.length, 1);

  sim.pendingMetadata.shift()();
  sim.pendingProgram.shift()();
  await Promise.all([firstTrack, firstProgram]);
  sim.setPlaying(false);
  await sim.api.refreshMetadata();
  await sim.api.refreshProgram();
  assert.equal(sim.pendingMetadata.length, 0);
  assert.equal(sim.pendingProgram.length, 0);

  sim.document.visibilityState = 'visible';
  const foregroundTrack = sim.api.refreshMetadata();
  const foregroundProgram = sim.api.refreshProgram();
  assert.equal(sim.pendingMetadata.length, 1);
  assert.equal(sim.pendingProgram.length, 1);
  sim.pendingMetadata.shift()();
  sim.pendingProgram.shift()();
  await Promise.all([foregroundTrack, foregroundProgram]);
});

test('zenderwissel start nieuwe refresh en oude afronding wist deze niet', async () => {
  const sim = makeBackgroundRefreshHarness();
  const oldTrack = sim.api.refreshMetadata();
  const oldProgram = sim.api.refreshProgram();
  sim.ctx.currentIndex = 1;
  sim.ctx.metadataGeneration++;
  sim.ctx.programGeneration++;
  const newTrack = sim.api.refreshMetadata();
  const newProgram = sim.api.refreshProgram();
  assert.equal(sim.pendingMetadata.length, 2);
  assert.equal(sim.pendingProgram.length, 2);

  sim.pendingMetadata.shift()();
  sim.pendingProgram.shift()();
  await Promise.all([oldTrack, oldProgram]);
  assert.strictEqual(sim.api.refreshMetadata(), newTrack);
  assert.strictEqual(sim.api.refreshProgram(), newProgram);
  sim.pendingMetadata.shift()();
  sim.pendingProgram.shift()();
  await Promise.all([newTrack, newProgram]);
});

test('focus en zichtbaarheid halen updates in en publiceren actuele Windows-mediakaart', () => {
  const events = {}, calls = [];
  const ctx = {
    document: {
      visibilityState: 'hidden',
      addEventListener(name, fn) { events['document:' + name] = fn; }
    },
    window: { addEventListener(name, fn) { events['window:' + name] = fn; } },
    refreshMetadata() { calls.push('track'); },
    refreshProgram() { calls.push('program'); },
    updateSleepTimer() { calls.push('timer'); },
    isPlaybackActive() { return true; },
    isCasting() { return false; },
    updateMediaSession() { calls.push('media'); },
    setMediaSessionPlaybackState(state) { calls.push(state); }
  };
  const js = part('function refreshAfterForegroundResume()', 
    '/* =========================================================\n   SLEEP TIMER');
  new Function('ctx', 'with(ctx) {' + js + '}')(ctx);
  assert.equal(typeof events['document:visibilitychange'], 'function');
  assert.equal(typeof events['window:focus'], 'function');

  events['window:focus']();
  assert.deepEqual(calls, []);
  ctx.document.visibilityState = 'visible';
  events['window:focus']();
  assert.deepEqual(calls, ['track', 'program', 'timer', 'media', 'playing']);
  calls.length = 0;
  events['document:visibilitychange']();
  assert.deepEqual(calls, ['track', 'program', 'timer', 'media', 'playing']);
});

test('hoofdplayknop heeft gebalanceerde SVG met rustigere schaduw, zonder spelerlogica te wijzigen', () => {
  const button = part('id="playButton"', '</button>');
  const styles = part('    .play-button {', '    .play-button::after {');
  const icons = part('    .play-button svg {', '    body.is-playing .play-icon {');
  const animation = part('    @keyframes play-attention {', '    @keyframes play-sweep {');
  assert.match(button, /class="play-icon"[\s\S]*?viewBox="0 0 24 24"[\s\S]*?aria-hidden="true"[\s\S]*?<path d="M6\.15 3\.75/);
  assert.match(button, /class="pause-icon"[\s\S]*?viewBox="0 0 24 24"[\s\S]*?aria-hidden="true"[\s\S]*?<path d="M5\.25 5\.25/);
  assert.match(styles, /width: 68px;[\s\S]*?height: 68px;/);
  assert.match(styles, /0 8px 20px rgba\(0,0,0,\.13\)/);
  assert.match(icons, /width: 38px;[\s\S]*?height: 38px;/);
  assert.match(icons, /transform: translateX\(1px\)/);
  assert.match(icons, /\.pause-icon[\s\S]*?width: 34px;/);
  // SVG geometry: 15.37 viewBox units in a 38px icon => ~24.3px visible width.
  const triangleWidth = (21.52 - 6.15) / 24 * 38;
  assert.ok(triangleWidth >= 24 && triangleWidth <= 26);
  assert.match(animation, /0 0 0 1\.5px rgba\(var\(--accent-rgb\), \.08\)/);
  assert.match(html, /body\.is-playing \.play-icon \{\s*display: none;/);
  assert.match(html, /body\.is-playing \.pause-icon \{\s*display: block;/);
  assert.match(html, /id="miniPlay"[\s\S]*?class="mini-play-icon"/);
  assert.match(html, /prefers-reduced-motion: reduce/);
  assert.match(html, /playButton\.addEventListener\(\s*"click"/);
});
