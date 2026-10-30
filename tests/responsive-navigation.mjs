// Echte Chromium/CDP-viewportmeting, zonder externe browserbibliotheken.
// NAV_DIAGNOSTICS_ONLY=1 verzamelt eerst de oorspronkelijke geometrie.
import assert from 'node:assert/strict';

const diagnostic = process.env.NAV_DIAGNOSTICS_ONLY === '1';
const widths = [320,360,375,390,480,600,700,701,768,820,900,960,1024,1080,1100,1120,1150,1200,1201,1440];
const delay = ms => new Promise(r => setTimeout(r,ms));
let target;
for (let i=0;i<120;i++) {
  try {
    const res=await fetch('http://127.0.0.1:9222/json');
    if(res.ok){target=(await res.json()).find(x=>x.type==='page');if(target?.webSocketDebuggerUrl)break;}
  } catch {}
  await delay(250);
}
assert.ok(target?.webSocketDebuggerUrl,'Chrome niet bereikbaar (30 seconden lang geen CDP-endpoint)');
const ws=new WebSocket(target.webSocketDebuggerUrl);
await new Promise((resolve,reject)=>{ws.addEventListener('open',resolve,{once:true});ws.addEventListener('error',reject,{once:true});});
let id=0;
const pending=new Map();
ws.addEventListener('message', event=>{
  const m=JSON.parse(event.data);
  if(!m.id)return;
  const p=pending.get(m.id);if(!p)return;
  pending.delete(m.id);m.error?p.reject(m.error):p.resolve(m.result);
});
const cmd=(method,params={})=>new Promise((resolve,reject)=>{
  const k=++id;pending.set(k,{resolve,reject});ws.send(JSON.stringify({id:k,method,params}));
});
await cmd('Page.enable');
await cmd('Runtime.enable');
async function evaluate(expression){
  const x=await cmd('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});
  if(x.exceptionDetails)throw Error(x.exceptionDetails.text);
  return x.result?.value;
}
async function viewport(width){
  await cmd('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:false});
  await delay(65);
}
function browserMetrics(){
  const ids=['.nav-inner','.nav-logo','.nav-links','.nav-tools','.lang-switch','#hamburger','#nav-join'];
  const rect=el=>{
    const r=el.getBoundingClientRect(),s=getComputedStyle(el);
    return {left:Math.round(r.left*10)/10,right:Math.round(r.right*10)/10,
      top:Math.round(r.top*10)/10,bottom:Math.round(r.bottom*10)/10,
      width:Math.round(r.width*10)/10,display:s.display};
  };
  const nav=document.querySelector('#main-nav');
  const out={width:innerWidth,navScrollWidth:nav.scrollWidth,navClientWidth:nav.clientWidth,
    lang:document.documentElement.lang,theme:document.documentElement.dataset.thema};
  for(const selector of ids){
    const el=document.querySelector(selector);
    if(el)out[selector]=rect(el);
  }
  const links=document.querySelector('.nav-links');
  out.items=Array.from(links?.querySelectorAll('li')||[],el=>({
    text:el.innerText,rect:rect(el),textRect:rect(el.querySelector('a')),lineBoxes:el.querySelector('a').getClientRects().length
  }));
  out.joinGlow=getComputedStyle(document.querySelector('#nav-join')).boxShadow;
  out.open=links?.classList.contains('open')||false;
  out.expanded=document.querySelector('#hamburger')?.getAttribute('aria-expanded');
  return out;
}
const metrics=()=>evaluate('('+browserMetrics.toString()+')()');
await cmd('Page.navigate',{url:'http://127.0.0.1:8123/index.php'});
let loaded=false;
for(let i=0;i<70;i++){
  await delay(200);
  loaded=await evaluate("!!document.querySelector('#nav-join') && !!document.querySelector('.nav-tools') && !!document.querySelector('link[href=\"site-styles.php\"]')?.sheet");
  if(loaded)break;
}
assert.ok(loaded,'Lokale site of CSS niet geladen');
for(const width of widths){
  await viewport(width);
  const m=await metrics();
  const desktop=m['.nav-links'].display!=='none';
  const logo=m['.nav-logo'],links=m['.nav-links'],tools=m['.nav-tools'],hamb=m['#hamburger'],inner=m['.nav-inner'];
  const between=desktop?links.left-logo.right:tools.left-logo.right;
  const after=desktop?tools.left-links.right:hamb.left-tools.right;
  const overlap=between< -0.5||after< -0.5;
  const compressed=desktop&&m.items.some(i=>i.rect.width+0.5<i.textRect.width||i.lineBoxes>1);
  const broken=overlap||compressed||m.navScrollWidth>m.navClientWidth+1;
  console.log(JSON.stringify({width,desktop,between:+between.toFixed(1),after:+after.toFixed(1),innerWidth:inner.width,
    logoRight:logo.right,toolsLeft:tools.left,menuWidth:links.width,navScroll:m.navScrollWidth,navClient:m.navClientWidth,broken}));
  if(!diagnostic){
    assert.equal(broken,false,'Overlap/overflow op '+width+'px');
    assert.equal(desktop,width>1200,'Onjuist breakpoint op '+width+'px');
    if(!desktop)assert.ok(after<=16,'Taal/thema en menuknop staan te ver uiteen: '+width+'px');
    assert.ok(m.joinGlow!=='none','Lid worden glow ontbreekt bij '+width+'px');
  }
}
if(!diagnostic){
  for(const width of [320,375,700,900,1100,1200]){
    await viewport(width);
    for(const theme of ['licht','donker']){
      await evaluate("document.documentElement.setAttribute('data-thema',"+JSON.stringify(theme)+")");
      await evaluate("document.querySelector('#hamburger').click()");
      let m=await metrics();
      assert.ok(m.open&&m.expanded==='true','Mobiel menu opent niet: '+width+'/'+theme);
      assert.equal(m['.nav-links'].display,'flex');
      assert.ok(m.items.every(i=>i.textRect.left>=0&&i.textRect.right<=width+1),'Mobiel item buiten scherm: '+width+'/'+theme);
      await evaluate("document.querySelector('#hamburger').click()");
      m=await metrics();
      assert.ok(!m.open&&m.expanded==='false','Mobiel menu sluit niet: '+width+'/'+theme);
    }
  }
  await viewport(1200);
  await evaluate("document.querySelector('#hamburger').click()");
  assert.ok((await metrics()).open);
  await viewport(1201);
  assert.equal((await metrics()).expanded,'false','Open mobiel menu reset niet na vergroten');
  await viewport(1200);
  assert.equal((await metrics()).open,false,'Mobiel menu heropent onbedoeld');
  for(const lang of ['nl','en','de']){
    await viewport(1201);
    await evaluate("setLang("+JSON.stringify(lang)+")");
    const m=await metrics();
    console.log('Locale desktop '+lang+' '+JSON.stringify({menuWidth:m['.nav-links'].width,available:m['.nav-inner'].width,items:m.items.map(i=>({text:i.text,lineBoxes:i.lineBoxes,width:i.rect.width}))}));
    assert.equal(m['.nav-links'].display,'flex');
    assert.ok(m['.nav-logo'].right<=m['.nav-links'].left+0.5,'Logo raakt menu in '+lang);
    assert.ok(m['.nav-links'].right<=m['.nav-tools'].left+0.5,'Menu raakt knoppen in '+lang);
    assert.ok(m.items.every(i=>i.rect.width+0.5>=i.textRect.width&&i.lineBoxes===1),'Menu-item te smal in '+lang);
    assert.ok(m.navScrollWidth<=m.navClientWidth+1,'Navbar te breed in '+lang);
    await viewport(1440);
    const wide=await metrics();
    assert.ok(wide['.nav-logo'].right<=wide['.nav-links'].left+0.5,'Logo raakt menu op groot scherm in '+lang);
  }

  // Alle publieke templates delen het menu. Test de daadwerkelijke rendering,
  // inclusief de actieve menu-items, in plaats van alleen de include-string.
  const pages=['index','aanmelden','baanreglement','fotoboek','media','ontstaan','bedankt'];
  for(const page of pages){
    await cmd('Page.navigate',{url:'http://127.0.0.1:8123/'+page+'.php'});
    let ready=false;
    for(let i=0;i<50;i++){
      await delay(150);
      ready=await evaluate("!!document.querySelector('#nav-join') && !!document.querySelector('.nav-tools') && !!document.querySelector('link[href=\"site-styles.php\"]')?.sheet");
      if(ready)break;
    }
    assert.ok(ready,'Gedeelde navigatie niet geladen op '+page);
    for(const width of [320,768,1200,1201,1440]){
      await viewport(width);
      const m=await metrics();
      const desktop=m['.nav-links'].display!=='none';
      assert.equal(desktop,width>1200,page+': verkeerd omslagpunt '+width);
      assert.ok(m.navScrollWidth<=m.navClientWidth+1,page+': menu te breed '+width);
      assert.ok(m.joinGlow!=='none',page+': gloed ontbreekt '+width);
      if(desktop){
        assert.ok(m['.nav-logo'].right<=m['.nav-links'].left+0.5,page+': logo overlapt '+width);
        assert.ok(m['.nav-links'].right<=m['.nav-tools'].left+0.5,page+': tools overlappen '+width);
      }else{
        assert.ok(m['#hamburger'].left-m['.nav-tools'].right<=16,page+': te grote knoppengroepafstand '+width);
      }
    }
    await viewport(375);
    for(const theme of ['licht','donker']){
      await evaluate("document.documentElement.setAttribute('data-thema',"+JSON.stringify(theme)+")");
      await evaluate("document.querySelector('#hamburger').click()");
      const opened=await metrics();
      assert.ok(opened.open&&opened.expanded==='true',page+': menu opent niet in '+theme);
      await evaluate("document.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true}))");
      const closed=await metrics();
      assert.ok(!closed.open&&closed.expanded==='false',page+': Escape sluit niet in '+theme);
    }
    const active=await evaluate("document.querySelector('#nav-join').parentElement.classList.contains('nav-active')");
    assert.equal(active,page==='aanmelden',page+': verkeerde Lid worden actieve status');
    console.log('Gedeelde navigatie getest: '+page);
  }
}
// Regressietest: de volledige homepage mag nooit horizontaal scrollen.
await cmd('Page.navigate',{url:'http://127.0.0.1:8123/index.php'});
let homeReady=false;
for(let i=0;i<60;i++){
  await delay(150);
  homeReady=await evaluate("!!document.querySelector('#telefoon') && !!document.querySelector('#contact') && !!document.querySelector('.nav-tools')");
  if(homeReady)break;
}
assert.ok(homeReady,'Homepage of contactformulier ontbreekt');
for(const language of ['nl','en','de']){
  await evaluate("setLang("+JSON.stringify(language)+")");
  for(const theme of ['licht','donker']){
    await evaluate("document.documentElement.setAttribute('data-thema',"+JSON.stringify(theme)+")");
    for(const width of [320,360,375,390,480,600,700,768,900,1200,1440]){
      await viewport(width);
      const result=await evaluate("(() => {const d=document.documentElement;const tel=document.querySelector('#telefoon').getBoundingClientRect();return {width:innerWidth,client:d.clientWidth,scroll:d.scrollWidth,phoneRight:tel.right,phoneLeft:tel.left,formWidth:document.querySelector('#contact-form').getBoundingClientRect().width};})()");
      assert.ok(result.scroll<=result.client+1,
        'Horizontale scroll '+language+'/'+theme+'/'+width+'px: '+JSON.stringify(result));
      assert.ok(result.phoneLeft>=-1 && result.phoneRight<=result.client+1,
        'Telefoonveld buiten scherm '+language+'/'+theme+'/'+width+'px: '+JSON.stringify(result));
    }
    await viewport(320);
    await evaluate("document.querySelector('#hamburger').click()");
    const opened=await evaluate("({scroll:document.documentElement.scrollWidth,client:document.documentElement.clientWidth})");
    assert.ok(opened.scroll<=opened.client+1,'Open mobiel menu veroorzaakt overflow '+language+'/'+theme+': '+JSON.stringify(opened));
    await evaluate("document.querySelector('#hamburger').click()");
  }
  console.log('Homepage horizontale overflow getest in '+language+': 11 breedtes, licht/donker');
}
ws.close();
console.log('Viewporttests gereed'+(diagnostic?' (diagnostiek)':' (geslaagd)'));
