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

test('alle stations gebruiken uitsluitend zelf gehoste PNG- of SVG-logo-assets', () => {
  assert.ok(logos.length >= 96, 'ook de Nederlandse themazenders moeten bestaan');
  for (const {id,logo} of logos) {
    assert.match(logo, /^\/assets\/radio\/logos\/(?:themes\/)?[a-z0-9-]+\.(?:png|svg)$/, id);
    assert.doesNotMatch(logo, /^https?:\/\//, id);
  }
  assert.ok(new Set(logos.map(x => x.logo)).size >= 94);
});

test('alle gekoppelde lokale PNG- of SVG-bestanden bestaan en zijn geldig', () => {
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
  assert.equal((stations.match(/\bstream:\s*"https:\/\//g)||[]).length, logos.length);
});


test('45 nieuwe station- en themalogo’s zijn lokaal aanwezig en traceerbaar', () => {
  const themeDir = path.join(root, 'assets/radio/logos/themes');
  const generated = JSON.parse(fs.readFileSync(
    path.join(themeDir, 'sources-generated.json'), 'utf8'));
  assert.equal(Object.keys(generated).length, 45);
  const sources = Object.entries(generated).filter(([,item]) => item.type === 'source');
  const fallbacks = Object.entries(generated).filter(([,item]) => item.type === 'fallback');
  assert.equal(sources.length, 37);
  assert.equal(fallbacks.length, 8);
  for (const [id,item] of Object.entries(generated)) {
    assert.equal(item.file, id + '.png');
    assert.ok(['source','fallback'].includes(item.type));
    const bytes = fs.readFileSync(path.join(themeDir,item.file));
    assert.equal(bytes.subarray(0,8).toString('hex'), '89504e470d0a1a0a');
    assert.ok(bytes.length > 1000);
    const sha = require('node:crypto').createHash('sha256').update(bytes).digest('hex');
    assert.equal(sha,item.sha256);
  }
  const themed = logos.filter(({logo}) => logo.includes('/themes/'));
  assert.equal(themed.length, 44);
  assert.ok(html.includes('logo:"/assets/radio/logos/themes/pinguin.png"'));
  assert.match(html, /\.station-theme-grid \.station-logo-wrap \{\s*display:grid/);
});

test('radio PWA ververst HTML na een deployment zonder offlinefunctie te verliezen', () => {
  const sw = fs.readFileSync(path.join(root, 'radio-sw.js'), 'utf8');
  const htaccess = fs.readFileSync(path.join(root, '.htaccess'), 'utf8');
  assert.match(sw, /fetch\(request,\s*\{ cache: "no-store" \}\)/);
  assert.match(sw, /cache\.put\("\/radio\.html", response\.clone\(\)\)/);
  assert.match(sw, /caches\.match\("\/radio\.html"\)/);
  assert.match(sw, /radio-shell-v3/);
  assert.match(htaccess, /radio\\\.html\|radio-sw\\\.js\|radio-manifest\\\.json/);
  assert.match(htaccess, /private, no-cache, max-age=0, must-revalidate/);
});
