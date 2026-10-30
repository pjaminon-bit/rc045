const test=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');

const root=path.resolve(__dirname,'..');
const read=relative=>fs.readFileSync(path.join(root,relative),'utf8');
const pages=['index','aanmelden','baanreglement','fotoboek','media','ontstaan','bedankt'];

test('alle publieke pagina’s hebben één gedeelde en één eigen externe CSS-bron',()=>{
  for(const page of pages){
    const html=read(page+'.php');
    const shared='href="site-styles.php?v=';
    const own='href="page-styles.php?page='+page+'&amp;v=';
    assert.equal(html.split(shared).length-1,1,page+': geen unieke gedeelde stylesheet');
    assert.equal(html.split(own).length-1,1,page+': geen unieke pagina-CSS');
    assert.ok(html.indexOf(shared)<html.indexOf(own),page+': onjuiste cascadevolgorde');
    assert.doesNotMatch(html,/<style\b/i,page+': er staat nog pagina-CSS inline');
    const css=read('page-styles/'+page+'.css');
    assert.ok(css.length>500,page+': stylesheet leeg');
    assert.doesNotMatch(css,/<\?php|<\/style>/i,page+': HTML/PHP in CSS');
  }
});

test('alleen bestaande publieke pagina’s zijn opvraagbaar via de veilige CSS-router',()=>{
  const endpoint=read('page-styles.php');
  for(const page of pages) assert.ok(endpoint.includes("'"+page+"'"),'Pagina niet op whitelist: '+page);
  assert.match(endpoint,/in_array\(\$pagina,\s*\$toegestaan,\s*true\)/);
  assert.match(endpoint,/'\/page-styles\/'\s*\.\s*\$pagina/);
  assert.match(endpoint,/rc045CssCacheHeaders/);
  const cache=read('site-css-version.php');
  assert.match(cache,/Content-Type: text\/css/);
  assert.match(cache,/Cache-Control: public, max-age=31536000, immutable/);
  assert.match(cache,/Cache-Control: no-store/);
  assert.match(endpoint,/readfile\(\$bestand\)/);
});

test('gedeelde hero-geometrie is één keer gedefinieerd en achtergronden blijven pagina-specifiek',()=>{
  const shared=read('styles.css');
  const hero=shared.match(/\.page-hero-bg\s*\{([^}]+)\}/);
  assert.ok(hero,'Gemeenschappelijke hero-achtergrond ontbreekt');
  for(const property of ['position: absolute','top: -80px','bottom: -80px','background-size: cover','opacity: 0.35']){
    assert.ok(hero[1].includes(property),'Hero eigenschap ontbreekt: '+property);
  }
  for(const page of ['aanmelden','baanreglement']){
    const css=read('page-styles/'+page+'.css');
    assert.doesNotMatch(css,/\.page-hero-bg\s*\{/);
    assert.doesNotMatch(css,/hero-achtergrond\.jpg/);
  }
  for(const [page,image] of [['fotoboek','crawlercollage.jpg'],['media','crawlergroep.jpg'],['ontstaan','crawlergroen.jpg']]){
    const css=read('page-styles/'+page+'.css');
    assert.match(css,new RegExp('background-image:\\s*url\\([\\x27\\x22]?images/'+image.replace('.','\\.')+'[\\x27\\x22]?\\)'));
    assert.doesNotMatch(css,/top:\s*-80px|background-size:\s*cover/,'Hero-geometrie opnieuw gedefinieerd op '+page);
  }
});

test('functionele toestandsstijlen zijn behouden',()=>{
  const homepage=read('page-styles/index.css');
  assert.match(homepage,/\.reveal\.visible/);
  assert.match(homepage,/\.carousel-slide-bg/);
  assert.match(homepage,/\.contact-grid/);
  const aanmelden=read('page-styles/aanmelden.css');
  assert.match(aanmelden,/\.faq-question:focus-visible/);
  assert.match(aanmelden,/\.faq-item\.open/);
  assert.match(aanmelden,/@media\s*\(max-width:\s*1000px\),\s*\(pointer:\s*coarse\)/);
  const fotoboek=read('page-styles/fotoboek.css');
  assert.match(fotoboek,/\.photo-grid/);
  const bedankt=read('page-styles/bedankt.css');
  assert.match(bedankt,/\.bedankt-card/);
});

test('bedanktpagina gebruikt dezelfde footercomponenten als de aanmeldpagina',()=>{
  const thank=read('bedankt.php'), register=read('aanmelden.php');
  const ids=['footer-brand-text','footer-nav-title','footer-join-title','footer-sponsors-title',
    'sponsors-grid','footer-sponsors-cta','footer-year','footer-credit-text'];
  for(const id of ids){
    assert.ok(thank.includes('id="'+id+'"'),'Bedanktfooter mist '+id);
    assert.ok(register.includes('id="'+id+'"'),'Aanmeldfooter mist '+id);
  }
  assert.match(thank,/class="footer-inner"/);
  assert.match(thank,/class="footer-top"/);
  assert.match(thank,/class="footer-sponsors"/);
  assert.match(thank,/class="footer-bottom"/);
  const own=read('page-styles/bedankt.css');
  assert.doesNotMatch(own,/\.footer\s*\{/,'Geen afwijkende footer op bedanktpagina');
  assert.match(own,/\.iban-card\s*\{[^}]+var\(--gold-light\)/);
  assert.match(own,/\.iban-number-row\s*\{[^}]*flex-wrap:\s*wrap/);
});
