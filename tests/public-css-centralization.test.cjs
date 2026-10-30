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
    const shared='href="site-styles.php"';
    const own='href="page-styles.php?page='+page+'"';
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
  assert.match(endpoint,/Content-Type: text\/css/);
  assert.match(endpoint,/Cache-Control: no-store/);
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
