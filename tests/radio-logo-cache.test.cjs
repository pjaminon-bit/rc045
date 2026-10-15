'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.join(__dirname, '..');
const workerSource = fs.readFileSync(path.join(root, 'radio-sw.js'), 'utf8');
const radioHtml = fs.readFileSync(path.join(root, 'radio.html'), 'utf8');

function harness() {
  const listeners = new Map();
  const stores = new Map();
  const network = [];
  let offline = false;

  const caches = {
    async open(name) {
      if (!stores.has(name)) stores.set(name, new Map());
      const items = stores.get(name);
      const keyOf = request => typeof request === 'string' ? request : request.url;
      return {
        async match(request) { return items.get(keyOf(request)); },
        async put(request, response) { items.set(keyOf(request), response); },
        async keys() { return [...items.keys()].map(url => ({url})); },
        async delete(request) { return items.delete(keyOf(request)); },
        async addAll() {}
      };
    },
    async keys() { return [...stores.keys()]; },
    async delete(name) { return stores.delete(name); },
    async match(request) {
      for (const items of stores.values()) {
        if (items.has(request)) return items.get(request);
      }
    }
  };

  const self = {
    location: {origin: 'https://rc045.nl'},
    addEventListener(name, handler) { listeners.set(name, handler); },
    skipWaiting() { return Promise.resolve(); },
    clients: {claim() { return Promise.resolve(); }}
  };

  const makeResponse = type => ({
    type, ok: type !== 'opaque',
    clone() { return makeResponse(type); }
  });

  const fetch = async request => {
    if (offline) throw new Error('offline');
    network.push(request.url);
    return makeResponse('opaque');
  };

  vm.runInNewContext(workerSource, {self, URL, caches, fetch});

  async function request(url, destination = 'image', method = 'GET') {
    const pending = [];
    let responsePromise;
    const req = {url, destination, method, mode: 'no-cors'};
    const event = {
      request: req,
      respondWith(promise) { responsePromise = Promise.resolve(promise); },
      waitUntil(promise) { pending.push(Promise.resolve(promise)); }
    };
    listeners.get('fetch')(event);
    if (!responsePromise) return {intercepted: false};
    const response = await responsePromise;
    await Promise.all(pending);
    return {intercepted: true, response};
  }

  return {
    request, caches, stores, network, listeners,
    setOffline(value) { offline = value; }
  };
}

test('alle zenderlogo-domeinen worden herkend zonder metadata-code te wijzigen', async () => {
  const section = radioHtml.slice(
    radioHtml.indexOf('const stations = ['),
    radioHtml.indexOf('/* =========================================================\n   ELEMENTS')
  );
  const logos = [...section.matchAll(/\blogo\s*:\s*"([^"]+)"/g)]
    .map(match => match[1]);
  assert.ok(logos.length >= 50, 'zenderconfiguratie moet alle huidige logo’s bevatten');

  const app = harness();
  for (const url of new Set(logos)) {
    const result = await app.request(url);
    assert.equal(result.intercepted, true, 'niet gecachet: ' + url);
  }
  assert.ok(app.stores.get('radio-station-logos-v1')?.size > 0);
});

test('opaque externe logo’s worden eenmalig geladen en daarna uit cache getoond', async () => {
  const app = harness();
  const url = 'https://commons.wikimedia.org/wiki/Special:Redirect/file/Radio%20Veronica%20logo.svg';
  const first = await app.request(url);
  assert.equal(first.response.type, 'opaque');
  assert.equal(app.network.length, 1);
  app.setOffline(true);
  const cached = await app.request(url);
  assert.equal(cached.intercepted, true);
  assert.equal(cached.response.type, 'opaque');
  assert.equal(app.network.length, 1);
});

test('audio, metadata en afbeeldingen van niet-radiohosts worden nooit onderschept', async () => {
  const app = harness();
  const forbidden = [
    ['https://icecast.omroep.nl/radio1-bb-mp3', 'audio'],
    ['https://rc045.nl/be-nowplaying.php', ''],
    ['https://www.google.com/s2/favicons?domain=test.nl', ''],
    ['https://www.random-site.test/photo.png', 'image'],
    ['https://static.bnr.nl/api/metadata.json', 'image']
  ];
  for (const [url, destination] of forbidden) {
    assert.equal((await app.request(url, destination)).intercepted, false, url);
  }
  assert.equal(app.network.length, 0);
});

test('logo-cache blijft begrensd op 80 afbeeldingen', async () => {
  const app = harness();
  for (let i = 0; i < 85; i++) {
    await app.request('https://www.google.com/s2/favicons?domain=radio' + i + '.nl&sz=128');
  }
  const items = app.stores.get('radio-station-logos-v1');
  assert.equal(items.size, 80);
  assert.equal(items.has('https://www.google.com/s2/favicons?domain=radio0.nl&sz=128'), false);
  assert.equal(items.has('https://www.google.com/s2/favicons?domain=radio84.nl&sz=128'), true);
});

test('verouderde logo-cache wordt bij serviceworker-update verwijderd', async () => {
  const app = harness();
  await app.caches.open('radio-shell-v1');
  await app.caches.open('radio-station-logos-v0');
  await app.caches.open('radio-station-logos-v1');
  const pending = [];
  app.listeners.get('activate')({waitUntil(promise) {pending.push(promise);}});
  await Promise.all(pending);
  const names = await app.caches.keys();
  assert.equal(names.includes('radio-station-logos-v0'), false);
  assert.equal(names.includes('radio-station-logos-v1'), true);
  assert.equal(names.includes('radio-shell-v1'), true);
});
