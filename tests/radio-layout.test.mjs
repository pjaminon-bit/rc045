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
    assert.ok(await menu.isVisible(), 'instellingenknop ook op desktop zichtbaar');
    assert.equal(await settingsPanel.isVisible(), false, 'instellingen standaard gesloten');
    await menu.click();
    assert.equal(await menu.getAttribute('aria-expanded'), 'true');
    assert.ok(await settingsPanel.isVisible(), 'instellingen openen op alle schermformaten');

    // Compact blijft compact, maar beide direct zichtbare instellingen
    // gebruiken dezelfde checkbox, dezelfde maat en rechter uitlijning.
    const advanced = page.locator('#settingsDisplayOptions');
    assert.equal(await advanced.getAttribute('open'),null,
      'weergaveopties standaard ingeklapt');
    const primarySwitches = await page.locator(
      '#displaySettingsPanel .settings-main-row input[type="checkbox"]'
    ).evaluateAll(inputs => inputs.map(input => {
      const b=input.getBoundingClientRect();
      return {id:input.id,width:b.width,height:b.height,right:b.right};
    }));
    assert.deepEqual(primarySwitches.map(x=>x.id), [
      'darkModeToggle','showThemeStationsToggle'
    ]);
    assert.ok(primarySwitches.every(x=>x.width===44 && x.height===26),
      'beide hoofdschakelaars hebben identieke afmetingen');
    assert.ok(Math.abs(primarySwitches[0].right-primarySwitches[1].right)<1,
      'hoofdschakelaars zijn rechts uitgelijnd');
    assert.equal(await page.locator(
      '#displaySettingsPanel .settings-row input:visible').count(),2,
      'standaard slechts twee zichtbare schakelaars');

    const darkMode=page.locator('#darkModeToggle');
    await darkMode.check();
    assert.equal(await page.locator('html').getAttribute('data-theme'),'dark');
    await darkMode.uncheck();
    assert.equal(await page.locator('html').getAttribute('data-theme'),'light');

    await page.locator('#settingsDisplayOptions > summary').click();
    assert.notEqual(await advanced.getAttribute('open'),null);
    const prefs=await page.locator('#settingsDisplayOptions .settings-row').evaluateAll(rows=>
      rows.map(row=>{
        const input=row.querySelector('input');
        const box=input.getBoundingClientRect();
        return {name:row.textContent.trim(),width:box.width,height:box.height,right:box.right};
      })
    );
    assert.deepEqual(prefs.map(x=>x.name),[
      'Trackinfo tonen','Programma-info tonen','Muziekdiensten tonen',
      'Albumart tonen','Artiestenfoto tonen','Miniplayer tonen','Animaties tonen'
    ]);
    assert.ok(prefs.every(x=>x.width===44 && x.height===26),
      'alle bestaande schakelaars even groot');
    assert.ok(prefs.every(x=>Math.abs(x.right-prefs[0].right)<1),
      'gegroepeerde schakelaars netjes uitgelijnd');
    const albumArt=page.locator('#albumArtToggle');
    const artist=page.locator('#artistPhotoToggle');
    assert.equal(await albumArt.isChecked(),true);
    await artist.uncheck();
    assert.equal(await albumArt.isChecked(),true,
      'albumart en artiestenfoto blijven onafhankelijk');
    await artist.check();
    assert.ok(await page.locator('#showThemeStationsToggle').isVisible());
    await page.keyboard.press('Escape');
    assert.equal(await menu.getAttribute('aria-expanded'), 'false');
    assert.equal(await settingsPanel.isVisible(), false);

    await page.locator('#status').evaluate(node => { node.textContent = 'Geen internetverbinding'; });
    await page.waitForFunction(() => document.querySelector('#status')?.dataset.tone === 'error');
    await page.locator('#status').evaluate(node => { node.textContent = 'Live'; });
    await page.waitForFunction(() => document.querySelector('#status')?.classList.contains('status-live-only'));

    if (pageErrors.length) throw Error('UI script errors at ' + width + ': ' + pageErrors.join('; '));
    console.log('PASS UI ' + width + 'px: touch targets, settings and status');
    await page.close();
  }

  // Zoekresultaten zijn onafhankelijk van de zichtbaarheid van themakanalen.
  // Het uitschakelen van de instelling mag noch streams noch favorieten wissen.
  for (const width of [390, 1024]) {
    const page = await browser.newPage({viewport:{width,height:850}});
    const errors=[];
    page.on('pageerror', error=>errors.push(error.message));
    await page.route(/^https?:\/\//, route=>route.abort());
    await page.goto(app,{waitUntil:'domcontentloaded'});
    await page.waitForSelector('.station');
    const toggle=page.locator('#showThemeStationsToggle');
    assert.equal(await toggle.isChecked(),true,'thema-zenders default zichtbaar');
    const selectedBefore=await page.evaluate(()=>({
      station:stations[currentIndex].id,
      src:document.getElementById('audio').src
    }));
    await page.locator('#displaySettingsButton').click();
    await toggle.uncheck();
    assert.equal(await toggle.isChecked(),false);
    assert.equal(await page.locator('.station[data-station-id="538"]').count(),1,
      '538 hoofdzender blijft bestaan');
    assert.equal(await page.locator('.station[data-station-id="538ibiza"]').count(),0,
      '538 Ibiza verborgen in normale lijst');

    const input=page.locator('#stationSearchInput');
    await input.fill('538 Ibiza');
    assert.ok(await page.locator('.station[data-station-id="538ibiza"]').isVisible(),
      '538 Ibiza verschijnt altijd bij zoeken');
    assert.ok(await page.locator('#radioThemePanel-538').isVisible(),
      'relevante groep opent automatisch');
    await input.fill('Pinguin Groove');
    assert.ok(await page.locator('.station[data-station-id="pinguingroove"]').isVisible(),
      'Pinguin thema verschijnt ook zonder hoofdzender');
    await input.fill('Radio 2 Relax');
    assert.ok(await page.locator('.station[data-station-id="radio2relax"]').isVisible(),
      'ook Belgische themakanalen verschijnen in zoekresultaten');
    await input.fill('538');
    assert.equal(await page.locator('#radioThemePanel-538 .station:visible').count(),8,
      'zoeken op hoofdomroep toont alle acht 538 thema-kanalen');
    await input.fill('');
    assert.equal(await page.locator('.station[data-station-id="538ibiza"]').count(),0,
      'thema-zenders zijn na wissen zoekterm weer verborgen');

    const selectedAfter=await page.evaluate(()=>({
      station:stations[currentIndex].id,
      src:document.getElementById('audio').src
    }));
    assert.deepEqual(selectedAfter,selectedBefore,
      'toon-thema-toggle raakt huidige zender en audio niet');

    await page.reload({waitUntil:'domcontentloaded'});
    assert.equal(await toggle.isChecked(),false,'instelling blijft bewaard na herladen');
    await input.fill('SLAM! MixMarathon');
    assert.ok(await page.locator('.station[data-station-id="slammix"]').isVisible(),
      'de zoekfunctie toont nog steeds thema na herladen');

    await page.locator('#displaySettingsButton').click();
    await toggle.check();
    await input.fill('');
    assert.ok(await page.locator('[data-radio-family="538"]').isVisible(),
      'inschakelen toont de radiofamilies weer');
    assert.equal(errors.length,0,'geen JavaScript-fouten tijdens thema-toggle test');
    console.log('PASS thema-toggle '+width+'px: gewone lijst, zoeken, opslag en audio');
    await page.close();
  }


  // Privacy en opslag: goed bereikbaar, toetsenbordvriendelijk en zonder
  // de playback, lokale voorkeuren of het scrollgedrag te veranderen.
  for (const width of [390, 1440]) {
    const page=await browser.newPage({
      viewport:{width,height:850}, reducedMotion:'reduce'
    });
    const scriptErrors=[];
    page.on('pageerror',error=>scriptErrors.push(error.message));
    await page.route(/^https?:\/\//,route=>route.abort());
    await page.goto(app,{waitUntil:'domcontentloaded'});
    await page.waitForSelector('#radioPrivacyButton');

    const stationId=await page.evaluate(()=>stations[currentIndex].id);
    const trigger=page.locator('#radioPrivacyButton');
    const dialog=page.locator('#radioPrivacyDialog');
    assert.equal(await dialog.isVisible(),false,'privacyinformatie standaard gesloten');
    await trigger.click();
    assert.equal(await dialog.isVisible(),true,'privacyinformatie kan worden geopend');
    assert.equal(await dialog.getAttribute('aria-labelledby'),'radioPrivacyTitle');
    assert.ok(await page.getByText('Externe verbindingen',{exact:true}).isVisible());
    assert.ok(await page.getByText('Lokale instellingen',{exact:true}).isVisible());
    assert.equal(await page.locator('#radioPrivacyClose').evaluate(el => document.activeElement === el),true);
    assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),
      'geen horizontale overflow door privacyvenster');
    await page.keyboard.press('Escape');
    assert.equal(await dialog.isVisible(),false,'Escape sluit de dialoog');
    assert.equal(await trigger.evaluate(el => document.activeElement === el),true,'focus keert terug naar privacylink');
    await trigger.click();
    await page.locator('#radioPrivacyDismiss').click();
    assert.equal(await dialog.isVisible(),false,'Sluiten-knop werkt');
    assert.equal(await page.evaluate(()=>stations[currentIndex].id),stationId,
      'privacyvenster wijzigt de gekozen zender niet');
    assert.equal(scriptErrors.length,0,'geen JavaScript-fouten in privacyvenster');
    console.log('PASS privacy '+width+'px: dialog en toetsenbord werken');
    await page.close();
  }

  // Volume regressies: 0% is mute, herstel vorige hoorbare stand,
  // iOS toont geen misleidende native volumeschuif.
  {
    const page = await browser.newPage({viewport:{width:390,height:850}});
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.route(/^https?:\/\//, route => route.abort());
    await page.goto(app, {waitUntil:'domcontentloaded'});
    await page.waitForSelector('#muteButton');
    const value = async v => page.locator('#volume').evaluate((input, n) => {
      input.value = String(n);
      input.dispatchEvent(new Event('input', {bubbles:true}));
    }, v);
    await value(0.55);
    assert.equal(await page.locator('#audio').evaluate(a => a.muted),false);
    await value(0);
    assert.equal(await page.locator('#audio').evaluate(a => a.muted),true,'zero volume must mute audio');
    assert.equal(await page.locator('#muteButton').getAttribute('aria-pressed'),'true');
    assert.equal(await page.locator('#muteButton').getAttribute('aria-label'),'Geluid inschakelen');
    await page.locator('#muteButton').click();
    assert.equal(await page.locator('#audio').evaluate(a => a.muted),false,'unmute should restore audio');
    const restored=await page.locator('#volume').inputValue();
    assert.equal(Number(restored),0.55,'unmute must restore last audible volume');
    await value(0.2);
    assert.equal(await page.locator('#audio').evaluate(a => a.muted),false,
      'dragging above zero should clear mute');
    assert.equal(await page.locator('#volumeDeviceHint').isVisible(),false);
    assert.equal(await page.locator('#volumeSlider').isVisible(),true);
    assert.equal(errors.length,0,'desktop volume should not create JS errors');
    await page.close();
  }
  {
    const page = await browser.newPage({
      viewport:{width:390,height:850},
      userAgent:'Mozilla/5.0 (iPhone; CPU iPhone OS 27_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/27.0 Mobile/15E148 Safari/604.1'
    });
    const errors=[];
    page.on('pageerror',e=>errors.push(e.message));
    await page.route(/^https?:\/\//,route=>route.abort());
    await page.goto(app,{waitUntil:'domcontentloaded'});
    await page.waitForSelector('#muteButton');
    assert.equal(await page.locator('#volumeSlider').isVisible(),false,
      'iOS Safari should not show ineffective native HTMLMediaElement.volume slider');
    assert.equal(await page.locator('.control-row').evaluate(node =>
      node.classList.contains('native-volume-mode')), true,
      'iOS Safari should display a compact native-volume control row');
    const buttons = await page.locator('.control-row').evaluate(row => {
      const rect = row.getBoundingClientRect();
      const player = document.querySelector('.secondary-controls').getBoundingClientRect();
      return {
        rowWidth: rect.width,
        centered: Math.abs((rect.left + rect.right) / 2 - (player.left + player.right) / 2) < 2
      };
    });
    assert.ok(buttons.rowWidth < 190, 'iOS volume control row should be compact');
    assert.ok(buttons.centered, 'iOS compact controls should be centered');
    await page.locator('#muteButton').click();
    assert.equal(await page.locator('#audio').evaluate(a=>a.muted),true,
      'mute property should remain usable on iOS');
    assert.equal(await page.locator('#muteButton').getAttribute('aria-pressed'),'true');
    await page.locator('#muteButton').click();
    assert.equal(await page.locator('#audio').evaluate(a=>a.muted),false);
    assert.equal(errors.length,0,'iPhone UI smoke test must not produce script errors');
    await page.close();
  }

} finally {
  await browser.close();
}
