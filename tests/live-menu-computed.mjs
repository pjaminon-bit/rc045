import assert from 'node:assert/strict';

const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
const targets = async () => {
  const res = await fetch('http://127.0.0.1:9222/json');
  assert.ok(res.ok, 'Chrome CDP is not ready');
  return await res.json();
};

let browserTargets;
for (let i = 0; i < 30; i++) {
  try {
    browserTargets = await targets();
    if (browserTargets.find(x => x.type === 'page')) break;
  } catch (e) {}
  await delay(200);
}
const target = browserTargets?.find(x => x.type === 'page');
assert.ok(target?.webSocketDebuggerUrl, 'No debuggable Chrome page');

const ws = new WebSocket(target.webSocketDebuggerUrl);
await new Promise((resolve, reject) => {
  ws.addEventListener('open', resolve, { once: true });
  ws.addEventListener('error', reject, { once: true });
});
let nextId = 0;
const pending = new Map();
ws.addEventListener('message', event => {
  const message = JSON.parse(event.data);
  if (!message.id) return;
  const pair = pending.get(message.id);
  if (!pair) return;
  pending.delete(message.id);
  message.error ? pair.reject(message.error) : pair.resolve(message.result);
});
const cmd = (method, params={}) => new Promise((resolve,reject) => {
  const id = ++nextId;
  pending.set(id,{resolve,reject});
  ws.send(JSON.stringify({id,method,params}));
});
await cmd('Page.enable');
await cmd('Runtime.enable');

for (const url of ['https://rc045.nl/', 'https://rc045.nl/aanmelden.html']) {
  await cmd('Page.navigate',{url});
  let data;
  for (let i = 0; i < 35; i++) {
    await delay(300);
    const res=await cmd('Runtime.evaluate',{
      expression: `(() => {
        const a = document.getElementById('nav-join');
        const css = document.querySelector('link[rel="stylesheet"][href="site-styles.php"]');
        if (!a || !css || !css.sheet) return null;
        const cs = getComputedStyle(a);
        return {url:location.href, href:css.href, background:cs.backgroundColor,
          color:cs.color, boxShadow:cs.boxShadow, animation:cs.animationName,
          navClass:a.parentElement.className};
      })()`,
      returnByValue:true
    });
    data=res?.result?.value;
    if(data) break;
  }
  assert.ok(data,'Page or CSS did not load: '+url);
  console.log(JSON.stringify(data));
  assert.ok(data.href.endsWith('/site-styles.php'), 'Incorrect stylesheet URL '+url);
  assert.notEqual(data.boxShadow,'none','Glow is missing at '+url);
  assert.notEqual(data.background,'rgb(58, 122, 119)','Old dark-green background at '+url);
  assert.notEqual(data.background,'rgb(45, 98, 96)','Old dark-green hover background at '+url);
  if(url.includes('aanmelden')) {
    assert.ok(data.navClass.includes('nav-active'),'Join link is not marked active');
  } else {
    assert.ok(data.animation.includes('navJoinGlow'),'Glow animation not applied to home menu');
  }
}
ws.close();
