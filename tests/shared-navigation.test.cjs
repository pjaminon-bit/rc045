// De publieke navigatie blijft centraal in site-nav.php.
// Uit te voeren met: node --test tests/shared-navigation.test.cjs
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const read = name => fs.readFileSync(path.join(root, name), 'utf8');
const shared = read('site-nav.php');
const publicPages = ['index', 'aanmelden', 'baanreglement', 'fotoboek', 'media', 'ontstaan', 'bedankt'];

test('iedere publieke pagina gebruikt de gedeelde menutemplate', () => {
  for (const page of publicPages) {
    const file = read(page + '.php');
    const include = "$rc045NavPage = '" + page + "'; require __DIR__ . '/site-nav.php';";
    assert.ok(file.includes(include), 'Include ontbreekt in ' + page);
    assert.doesNotMatch(file, /<nav class="nav"/);
  }
});

test('beheerpaneel, ledenadministratie en radio blijven gescheiden', () => {
  for (const name of ['beheer.php', 'leden.php', 'radio.html']) {
    assert.doesNotMatch(read(name), /require __DIR__ \. '\/site-nav\.php';/);
  }
});

test('alle menu-items, taalkeuze en mobiel menu staan één keer centraal', () => {
  assert.equal((shared.match(/<nav\b/g) || []).length, 1);
  for (const id of ['nav-about', 'nav-membership', 'nav-track', 'nav-location', 'nav-photobook', 'nav-contact', 'nav-join', 'lang-switch', 'hamburger']) {
    assert.ok(shared.includes('id="' + id + '"'), 'Menu-element ontbreekt: ' + id);
  }
  assert.ok(shared.includes("$rc045NavPage === 'aanmelden'"));
  assert.ok(shared.includes("$rc045NavPage === 'fotoboek'"));
  assert.ok(shared.includes("$rc045NavPage === 'index'"));
});


test('alle zeven pagina\'s laden de gedeelde CSS met een inhoudshash', () => {
  for (const page of publicPages) {
    const file = read(page + '.php');
    assert.ok(file.includes('href="site-styles.php?v='), page + ' CSS-link ontbreekt');
    assert.ok(file.includes("rc045CssVersion('styles.css')"), page + ' gedeelde versie ontbreekt');
    assert.ok(!file.includes('href="styles.css'), 'Oude CSS-link in ' + page);
  }
});

test('alleen CSS met de huidige inhoudshash wordt lang gecachet', () => {
  const endpoint = read('site-styles.php');
  const cache = read('site-css-version.php');
  const stylesheet = read('styles.css');
  assert.ok(endpoint.includes('rc045CssCacheHeaders'));
  assert.ok(cache.includes('Cache-Control: public, max-age=31536000, immutable'));
  assert.ok(cache.includes('Cache-Control: no-store'));
  assert.ok(cache.includes('Content-Type: text/css'));
  assert.ok(stylesheet.includes('.nav-links .nav-lid.nav-active a'));
  assert.ok(stylesheet.includes('@keyframes navJoinGlow'));
});
