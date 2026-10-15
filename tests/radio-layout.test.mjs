import assert from 'node:assert/strict';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';
import { mkdir } from 'node:fs/promises';
import { chromium } from 'playwright';

const app = pathToFileURL(resolve('radio.html')).href;
const browser = await chromium.launch({headless: true, args: ['--no-sandbox']});
await mkdir('radio-layout-screenshots', {recursive: true});

try {
  for (const width of [1440, 1200, 760, 390]) {
    const page = await browser.newPage({
      viewport: {width, height: 850},
      reducedMotion: 'reduce',
    });

    const pageErrors = [];
    page.on('pageerror', error => pageErrors.push(error.message));

    // Externe logo's, streaming en metadata-API's zijn voor dit layouttest niet nodig.
    await page.route(/^https?:\/\//, route => route.abort());
    await page.goto(app, {waitUntil: 'domcontentloaded'});
    await page.waitForSelector('.station', {timeout: 20000});

    const layout = await page.evaluate(() => {
      const root = document.querySelector('.app');
      const toolbar = document.querySelector('.station-toolbar');
      const tabs = document.querySelector('.radio-country-tabs');
      const search = document.querySelector('.station-search');
      const selected = document.querySelector('.radio-country-tab[aria-selected="true"]');
      const panel = document.querySelector('.country-group:not([hidden])');
      const grid = panel?.querySelector('.country-stations');
      const cards = [...(grid?.querySelectorAll('.station') || [])].filter(card => !card.hidden);
      const r = el => {
        const b = el.getBoundingClientRect();
        return {left:b.left,right:b.right,top:b.top,bottom:b.bottom,width:b.width,height:b.height};
      };

      const gridColumns = grid
        ? getComputedStyle(grid).gridTemplateColumns.split(/\s+/).filter(Boolean).length
        : 0;

      return {
        viewport: window.innerWidth,
        docWidth: document.documentElement.scrollWidth,
        cardCount: cards.length,
        columns: gridColumns,
        root:r(root), toolbar:r(toolbar), tabs:r(tabs), search:r(search),
        firstCard:r(cards[0]),
        activeTab:selected?.textContent.trim()
      };
    });

    await page.locator('.station-toolbar').scrollIntoViewIfNeeded();
    await page.screenshot({path: 'radio-layout-screenshots/toolbar-' + width + '.png'});

    assert.equal(layout.viewport, width, 'viewport ' + width);
    assert.ok(layout.cardCount > 0, 'station cards visible at ' + width);
    assert.ok(layout.docWidth <= width + 1, 'unexpected horizontal overflow at ' + width);
    assert.ok(Math.abs(layout.tabs.left - layout.firstCard.left) < 2,
      'tabs not left-aligned with first card at ' + width);
    assert.ok(layout.tabs.right <= width && layout.search.right <= width,
      'toolbar controls overflow viewport at ' + width);
    assert.equal(layout.activeTab, 'NL');

    if (width > 760) {
      assert.equal(layout.columns, 4, 'four desktop station columns at ' + width);
      assert.ok(Math.abs(layout.tabs.top - layout.search.top) < 2,
        'tabs/search must share one row at ' + width);
      assert.ok(layout.search.left >= layout.tabs.right,
        'search overlaps tabs at ' + width);
      assert.ok(layout.search.left - layout.tabs.right <= 15,
        'search too far from tabs at ' + width);
    } else {
      assert.ok(layout.search.top >= layout.tabs.bottom - 1,
        'search must be below tabs on mobile at ' + width);
      assert.equal(layout.columns, width === 390 ? 1 : 2,
        'mobile station grid unexpectedly changed at ' + width);
    }

    if (pageErrors.length) {
      throw Error('Page script error at width ' + width + ': ' + pageErrors.join('; '));
    }

    console.log('PASS ' + width + 'px: ' + layout.columns +
      ' columns, controls aligned, no overlap or horizontal overflow');
    await page.close();
  }

  // UI-acceptatie: klein scherm, mobiele instellingen en bedieningsvlakken.
  for (const width of [320, 360, 390, 430, 760, 1024, 1440]) {
    const page = await browser.newPage({
      viewport: {width, height: 850},
      reducedMotion: 'reduce',
    });
    const pageErrors = [];
    page.on('pageerror', error => pageErrors.push(error.message));
    await page.route(/^https?:\/\//, route => route.abort());
    await page.goto(app, {waitUntil: 'domcontentloaded'});
    await page.waitForSelector('.station', {timeout: 20000});

    const dimensions = await page.evaluate(() => {
      const box = selector => {
        const r = document.querySelector(selector).getBoundingClientRect();
        return {width:r.width, height:r.height, left:r.left, right:r.right};
      };
      return {
        documentWidth: document.documentElement.scrollWidth,
        controls: {
          mute:box('#muteButton'),
          sleep:box('#sleepButton'),
          favorite:box('.station .favorite-button'),
          countryFavorite:box('.radio-country-favorite')
        }
      };
    });
    assert.ok(dimensions.documentWidth <= width + 1,
      'horizontal overflow in enhanced UI at ' + width);
    for (const [name, r] of Object.entries(dimensions.controls)) {
      assert.ok(r.width >= 43 && r.height >= 43,
        name + ' must have at least a 44px touch target at ' + width);
    }

    const menu = page.locator('#displaySettingsButton');
    const settingsPanel = page.locator('#displaySettingsPanel');
    if (width <= 760) {
      assert.ok(await menu.isVisible(), 'mobile display settings menu should be visible at ' + width);
      assert.equal(await settingsPanel.isVisible(), false, 'settings should default closed');
      await menu.click();
      assert.equal(await menu.getAttribute('aria-expanded'), 'true');
      assert.ok(await page.locator('#albumArtToggle').isVisible(), 'albumart toggle remains usable');
      assert.ok(await page.locator('#themeSwitcher').isVisible(), 'theme controls remain usable');
      const themeTargets = await page.locator('.theme-option').evaluateAll(nodes =>
        nodes.map(n => ({width:n.getBoundingClientRect().width, height:n.getBoundingClientRect().height}))
      );
      for (const box of themeTargets)
        assert.ok(box.width >= 43 && box.height >= 43, 'theme button must be 44px');
      await page.locator('[data-theme-choice="dark"]').click();
      assert.equal(await page.locator('html').getAttribute('data-theme'), 'dark',
        'theme selection should still work');
      await page.keyboard.press('Escape');
      assert.equal(await menu.getAttribute('aria-expanded'), 'false');
      assert.equal(await settingsPanel.isVisible(), false, 'Escape closes mobile settings');
    } else {
      assert.equal(await menu.isVisible(), false, 'desktop settings menu button should be hidden');
      assert.ok(await page.locator('#albumArtToggle').isVisible(), 'desktop albumart visible');
      assert.ok(await page.locator('#themeSwitcher').isVisible(), 'desktop theme visible');
    }

    await page.locator('#status').evaluate(node => { node.textContent = 'Geen internetverbinding'; });
    await page.waitForFunction(() => document.querySelector('#status')?.dataset.tone === 'error');
    await page.locator('#status').evaluate(node => { node.textContent = 'Live'; });
    await page.waitForFunction(() => document.querySelector('#status')?.classList.contains('status-live-only'));

    if (pageErrors.length) throw Error('UI script errors at ' + width + ': ' + pageErrors.join('; '));
    console.log('PASS UI ' + width + 'px: touch targets, settings and status');
    await page.close();
  }
} finally {
  await browser.close();
}
