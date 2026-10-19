'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const html = fs.readFileSync(path.join(root, 'radio.html'), 'utf8');
const start = html.indexOf('const stations = [');
const end = html.indexOf('/* =========================================================\n   ELEMENTS', start);
const stations = html.slice(start, end);
const logos = [...stations.matchAll(/\{\s*id:\s*"([^"]+)"(?:(?!\n  \}).)*?logo:\s*"([^"]+)"/gs)]
  .map(([,id,logo]) => ({id,logo}));

test('63 stations verwijzen uitsluitend naar zelf gehoste logo-assets', () => {
  assert.equal(logos.length, 63);
  for (const {id,logo} of logos) {
    assert.match(logo, /^\/assets\/radio\/logos\/[a-z0-9-]+\.(?:png|svg)$/, id);
    assert.doesNotMatch(logo, /^https?:\/\//, id);
  }
  assert.equal(new Set(logos.map(x => x.logo)).size, 50);
});

test('50 lokale PNG- of SVG-bestanden bestaan en zijn geldig', () => {
  for (const logo of new Set(logos.map(x => x.logo))) {
    const file = path.join(root, logo.slice(1));
    const bytes = fs.readFileSync(file);
    assert.ok(bytes.length > 100, file);
    if (file.endsWith('.png')) {
    assert.equal(bytes.subarray(0,8).toString('hex'), '89504e470d0a1a0a', file);
    } else {
      const svg = bytes.toString('utf8');
      assert.ok(svg.startsWith('<svg xmlns="http://www.w3.org/2000/svg"'));
      assert.ok(svg.includes('</svg>'));
      assert.ok(svg.includes('<title>'));
    }
  }
});

test('herkomst van alle logo’s is terug te vinden', () => {
  const manifest = JSON.parse(fs.readFileSync(
    path.join(root, 'assets/radio/logos/sources.json'), 'utf8'));
  assert.equal(manifest.assets.length, 50);
  for (const item of manifest.assets) {
    assert.match(item.source, /^https:\/\//);
    assert.ok(item.stations.length > 0);
    assert.ok(fs.existsSync(path.join(root, item.file)));
  }
});

test('trackinfo-configuratie en streams behouden hun oorspronkelijke referenties', () => {
  assert.match(html, /const METADATA_REFRESH = 15000;/);
  assert.match(html, /const PROGRAM_REFRESH = 60000;/);
  assert.match(html, /function refreshMetadata\(/);
  assert.match(html, /function refreshProgram\(/);
  assert.equal((stations.match(/\bstream:\s*"https:\/\//g)||[]).length, 63);
});