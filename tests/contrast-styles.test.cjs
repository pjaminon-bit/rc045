// Controleert WCAG-contrast en bewaakt de gedeelde navigatiestijl.
// Uitvoeren: node --test tests/contrast-styles.test.cjs
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const read = name => fs.readFileSync(path.join(__dirname,'..',name),'utf8');

function lum(hex) {
  assert.match(hex,/^#[0-9a-f]{6}$/i);
  const nums=hex.slice(1).match(/.{2}/g).map(v=>parseInt(v,16)/255);
  const [r,g,b]=nums.map(v=>v<=0.04045?v/12.92:((v+0.055)/1.055)**2.4);
  return 0.2126*r+0.7152*g+0.0722*b;
}
function ratio(a,b) {
  const l=lum(a),r=lum(b);
  return (Math.max(l,r)+0.05)/(Math.min(l,r)+0.05);
}
function contrast(label,foreground,background) {
  const c=ratio(foreground,background);
  assert.ok(c>=4.5,label+': '+c.toFixed(2)+':1, minimum 4.5:1');
  console.log(label+': '+c.toFixed(2)+':1');
}
function block(source,selector) {
  const start=source.indexOf(selector);
  assert.ok(start>=0,'Ontbrekende selector: '+selector);
  const open=source.indexOf('{',start);
  const close=source.indexOf('}',open);
  assert.ok(open>=0 && close>open);
  return source.slice(open+1,close);
}
function token(source,selector,name) {
  const inside=block(source,selector);
  const found=inside.match(new RegExp(name+'\\s*:\\s*(#[a-fA-F0-9]{6})'));
  assert.ok(found,'Ontbrekende kleur '+name+' in '+selector);
  return found[1];
}
test('kleine goudkleurige tekst en groene openstatus in licht thema',()=>{
  const css=read('styles.css'), homepage=read('index.php');
  const gold=token(css,':root {','--gold-text');
  for(const [label,bg] of [['papier','#FAF6EC'],['wit','#FFFFFF'],['goudvlak','#FBF4DF'],['tealvlak','#EAF4F3']]) {
    contrast('Goud op '+label,gold,bg);
  }
  contrast('Openstatus op wit','#147D37','#FFFFFF');
  for(const selector of ['.section-label {'])assert.ok(block(css,selector).includes('var(--gold-text)'));
  for(const selector of ['.about-photos-title {','.agenda-card-tag.leden {','.nieuws-card-date {']){
    assert.ok(block(homepage,selector).includes('var(--gold-text)'),selector);
  }
  assert.ok(block(read('aanmelden.php'),'.form-section-title {').includes('var(--gold-text)'));
});
test('publieke donkere knoppen en footerhover',()=>{
  const css=read('styles.css');
  const bg=token(css,':root[data-thema="donker"] {','--button-bg');
  const hover=token(css,':root[data-thema="donker"] {','--button-bg-hover');
  contrast('Publieke knop', '#FFFFFF', bg);
  contrast('Publieke knop hover', '#FFFFFF', hover);
  contrast('Footerlink hover','#9EDAD5','#1E2C13');
  assert.ok(block(css,'.btn-primary {').includes('var(--button-bg)'));
  assert.ok(block(css,'.btn-primary:hover {').includes('var(--button-bg-hover)'));
  assert.ok(block(css,'.back-to-top {').includes('var(--button-bg)'));
  assert.ok(css.includes(':root[data-thema="donker"] .modal-btn-primary,'));
});
test('beheerknoppen en ledenbadges donker',()=>{
  const css=read('paneel.css');
  contrast('Beheerknop','#FFFFFF',token(css,':root[data-thema="donker"] {','--button-bg'));
  contrast('Beheerknop hover','#FFFFFF',token(css,':root[data-thema="donker"] {','--button-bg-hover'));
  contrast('Ledenbadge goud','#E5C25A','#262112');
  contrast('Ledenbadge persoonlijk','#2A2000','#D3A72B');
  assert.ok(block(css,'\n    button {').includes('var(--button-bg)'));
  assert.ok(block(css,'\n    button:hover {').includes('var(--button-bg-hover)'));
  for(const selector of ['.label.goud','.label.rood','.label.grijs']){
    assert.ok(css.includes(':root[data-thema="donker"] #tab-mijn '+selector));
  }
});
test('navigatieglow en zeven gedeelde pagina-templates blijven bestaan',()=>{
  const css=read('styles.css');
  assert.ok(css.includes('@keyframes navJoinGlow'));
  assert.ok(css.includes('.nav-links .nav-lid.nav-active a'));
  assert.ok(css.includes('--nav-join-glow-rgb: 58, 122, 119'));
  assert.ok(css.includes('.page-hero .section-label, .rules .section-label { color: var(--gold); }'));
  for(const page of ['index','aanmelden','baanreglement','fotoboek','media','ontstaan','bedankt']){
    const html=read(page+'.php');
    assert.ok(html.includes('href="site-styles.php"'),page+' stylesheet');
    assert.ok(html.includes("require __DIR__ . '/site-nav.php'"),page+' navigation');
  }
});
