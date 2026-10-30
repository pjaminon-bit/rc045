// Chromium CDP: registratieformulier op iPhone en toetsenbordtoegankelijke FAQ.
import assert from 'node:assert/strict';

const delay=ms=>new Promise(r=>setTimeout(r,ms));
let target;
for(let i=0;i<120;i++){
  try{
    const res=await fetch('http://127.0.0.1:9222/json');
    if(res.ok){target=(await res.json()).find(v=>v.type==='page');if(target?.webSocketDebuggerUrl)break;}
  }catch{}
  await delay(250);
}
assert.ok(target?.webSocketDebuggerUrl,'Chrome debugendpoint niet bereikbaar');
const ws=new WebSocket(target.webSocketDebuggerUrl);
await new Promise((resolve,reject)=>{
  ws.addEventListener('open',resolve,{once:true});
  ws.addEventListener('error',reject,{once:true});
});
let id=0;
const pending=new Map();
ws.addEventListener('message',e=>{
  const msg=JSON.parse(e.data),job=pending.get(msg.id);
  if(!job)return;
  pending.delete(msg.id);
  msg.error?job.reject(msg.error):job.resolve(msg.result);
});
const cmd=(method,params={})=>new Promise((resolve,reject)=>{
  const k=++id;pending.set(k,{resolve,reject});ws.send(JSON.stringify({id:k,method,params}));
});
const evaluate=async expression=>{
  const res=await cmd('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});
  if(res.exceptionDetails)throw Error(res.exceptionDetails.text);
  return res.result?.value;
};
const viewport=async width=>{
  await cmd('Emulation.setDeviceMetricsOverride',{width,height:800,deviceScaleFactor:1,mobile:false});
  await delay(70);
};
function faqState(){
  const buttons=[...document.querySelectorAll('#faq-items button.faq-question')];
  return buttons.map(b=>{
    const a=document.getElementById(b.getAttribute('aria-controls'));
    const bs=getComputedStyle(b),as=getComputedStyle(a);
    return {tag:b.tagName,type:b.type,question:b.textContent,aria:b.getAttribute('aria-expanded'),
      controls:b.getAttribute('aria-controls'),answerId:a?.id,hidden:a?.hidden,
      display:as.display,fullHeight:a?.scrollHeight,shownHeight:a?.clientHeight,
      outline:bs.outlineStyle,focused:document.activeElement===b};
  });
}
const faq=()=>evaluate('('+faqState.toString()+')()');
async function press(key,code,windowsVirtualKeyCode){
  // CDP laat bij Enter/spatie de karaktergebeurtenis afzonderlijk
  // doorlopen; een losse keyDown is niet altijd een volledige toetsaanslag.
  if (key === 'Enter' || key === ' ') {
    const text = key === 'Enter' ? '\r' : ' ';
    await cmd('Input.dispatchKeyEvent',{type:'rawKeyDown',key,code,windowsVirtualKeyCode});
    await cmd('Input.dispatchKeyEvent',{type:'char',key,code,windowsVirtualKeyCode,text,unmodifiedText:text});
    await cmd('Input.dispatchKeyEvent',{type:'keyUp',key,code,windowsVirtualKeyCode});
  } else {
    await cmd('Input.dispatchKeyEvent',{type:'keyDown',key,code,windowsVirtualKeyCode});
    await cmd('Input.dispatchKeyEvent',{type:'keyUp',key,code,windowsVirtualKeyCode});
  }
  await delay(70);
}
try{
  await cmd('Page.enable');
  await cmd('Runtime.enable');
  await cmd('Page.navigate',{url:'http://127.0.0.1:8123/aanmelden.php'});
  let ready=false;
  for(let i=0;i<100;i++){
    await delay(150);
    ready=await evaluate("document.readyState==='complete' && document.querySelectorAll('#faq-items button.faq-question').length===3 && !!document.querySelector('.nav-tools')");
    if(ready)break;
  }
  assert.ok(ready,'Aanmeldpagina en drie FAQ-testitems niet correct geladen');
  function formMetrics(){
    const fields=[...document.querySelectorAll('.form-group input:not([type="hidden"]):not([type="checkbox"]),.form-group select')];
    return {width:innerWidth,fonts:fields.map(el=>({id:el.id,font:getComputedStyle(el).fontSize})),
      page:document.documentElement.scrollWidth,client:document.documentElement.clientWidth};
  }
  for(const width of [320,375,390,600,768,900,1000]){
    await viewport(width);
    const data=await evaluate('('+formMetrics.toString()+')()');
    assert.ok(data.fonts.length>=10,'Niet alle invoervelden aangetroffen');
    assert.ok(data.fonts.every(el=>Number.parseFloat(el.font)>=16),
      'Mogelijke Safari-focuszoom bij '+width+': '+JSON.stringify(data.fonts));
    assert.ok(data.page<=data.client+1,'Horizontale overflow bij '+width+': '+JSON.stringify(data));
  }
  await viewport(1100);
  await cmd('Emulation.setTouchEmulationEnabled',{enabled:true,maxTouchPoints:1});
  await delay(100);
  const coarse=await evaluate("({coarse:matchMedia('(pointer: coarse)').matches,fontsize:getComputedStyle(document.querySelector('#voornaam')).fontSize})");
  assert.ok(coarse.coarse,'Touch-emulatie activeerde pointer: coarse niet');
  assert.ok(Number.parseFloat(coarse.fontsize)>=16,'Touch 1100px gebruikte te kleine tekst');
  await cmd('Emulation.setTouchEmulationEnabled',{enabled:false});
  await viewport(375);

  let items=await faq();
  assert.equal(items.length,3);
  for(const i of items){
    assert.equal(i.tag,'BUTTON');
    assert.equal(i.type,'button');
    assert.equal(i.aria,'false');
    assert.equal(i.hidden,true);
    assert.equal(i.display,'none');
    assert.equal(i.controls,i.answerId);
  }
  await evaluate("document.querySelector('#faq-items button.faq-question').focus()");
  assert.ok((await faq())[0].focused,'FAQ niet focusbaar');
  // Gebruik echte Tab-navigatie: focus-visible wordt pas na toetsenbordfocus
  // verwacht, niet bij een programmatische .focus() na muis/touch-emulatie.
  await press('Tab','Tab',9);
  assert.ok((await faq())[1].focused,'Tab bereikt volgende FAQ-vraag niet');
  await cmd('Input.dispatchKeyEvent',{type:'keyDown',key:'Tab',code:'Tab',windowsVirtualKeyCode:9,modifiers:8});
  await cmd('Input.dispatchKeyEvent',{type:'keyUp',key:'Tab',code:'Tab',windowsVirtualKeyCode:9,modifiers:8});
  await delay(70);
  assert.ok((await faq())[0].focused,'Shift+Tab keert niet terug naar eerste vraag');
  assert.equal((await faq())[0].outline,'solid','Focusrand ontbreekt bij toetsenbordnavigatie');

  await press('Enter','Enter',13);
  items=await faq();
  assert.equal(items[0].aria,'true','Enter opent niet');
  assert.equal(items[0].hidden,false,'Antwoord verborgen na Enter');
  await press(' ','Space',32);
  items=await faq();
  assert.equal(items[0].aria,'false','Spatie sluit niet');
  assert.equal(items[0].hidden,true);

  await evaluate("document.querySelectorAll('#faq-items button.faq-question')[1].click()");
  items=await faq();
  assert.equal(items[1].hidden,false);
  assert.ok(items[1].fullHeight>600,'FAQ-fixture mist lang antwoord');
  assert.ok(items[1].shownHeight>=items[1].fullHeight,'Lang antwoord wordt afgekapt');

  for(const lang of ['en','de','nl']){
    await evaluate("setLang("+JSON.stringify(lang)+")");
    items=await faq();
    assert.equal(items.length,3);
    assert.equal(items[1].aria,'true','Open FAQ-status verloren bij '+lang);
    assert.equal(items[1].hidden,false,'Open antwoord verdwijnt bij '+lang);
    assert.equal(items[0].question,({nl:'Hoe werkt het?',en:'How does it work?',de:'Wie funktioniert es?'})[lang]);
    assert.equal(items[2].question,'Vraag zonder vertaling?','NL-terugval ontbreekt in '+lang);
  }
  const safe=await evaluate("({form:!!document.querySelector('#faq-items button.faq-question').closest('form'),script:!!document.querySelector('#faq-items script')})");
  assert.equal(safe.form,false);
  assert.equal(safe.script,false);
  console.log('Aanmelden: invoervelden 16px, FAQ toetsenbord/aria/lange tekst en NL/EN/DE GESLAAGD');
}finally{
  ws.close();
}