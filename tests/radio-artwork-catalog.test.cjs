'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const rows = JSON.parse(fs.readFileSync(path.join(__dirname,'radio-artwork-catalog.json'),'utf8'));
const helper = fs.readFileSync(path.join(__dirname,'..','radio-artwork-release-select.php'),'utf8');
const backend = fs.readFileSync(path.join(__dirname,'..','radio-artwork.php'),'utf8');

test('catalogus bevat 180 unieke nummers uit negen representatieve categorieën', () => {
  assert.equal(rows.length,180);
  const seen = new Set(rows.map(({artist,title})=>`${artist}|${title}`.toLocaleLowerCase()));
  assert.equal(seen.size,rows.length);
  const categories = new Map();
  for (const entry of rows) {
    assert.ok(entry.artist && entry.title);
    categories.set(entry.category,(categories.get(entry.category)||0)+1);
  }
  assert.equal(categories.size,9);
  for (const count of categories.values()) assert.equal(count,20);
});

test('eerder gemelde missers en misidentificaties staan als regressie in de catalogus', () => {
  const cases = [
    ['P!nk','Sober'],['Dua Lipa','Dance the Night'],
    ['ABBA','Dancing Queen'],['Taylor Swift','Anti-Hero'],
    ['Rihanna','We Found Love'],['Daft Punk','Get Lucky'],
    ['Harry Styles','As It Was'],['Yeah Yeah Yeahs','Heads Will Roll']
  ];
  const keys = new Set(rows.map(x=>x.artist+'|'+x.title));
  for (const [artist,title] of cases) assert.ok(keys.has(artist+'|'+title),artist+' – '+title);
});

test('de selectie is generiek: geen hardcoded artiest- of zenderspecifieke productieregels', () => {
  assert.doesNotMatch(helper,/\b(?:P!nk|Dua Lipa|Yeah Yeah Yeahs|ABBA|Taylor Swift|Rihanna)\b/i);
  assert.doesNotMatch(backend,/\b(?:P!nk|Dua Lipa|Yeah Yeah Yeahs|ABBA|Taylor Swift|Rihanna)\b/i);
  assert.match(helper,/artworkScoreRelease/);
  assert.match(backend,/musicBrainzArtwork\('release'/);
});
