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
} finally {
  await browser.close();
}
