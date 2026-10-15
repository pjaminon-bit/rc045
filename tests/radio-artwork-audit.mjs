import {readFile,writeFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import {dirname,join} from 'node:path';

const root=dirname(fileURLToPath(import.meta.url));
const catalog=JSON.parse(await readFile(join(root,'radio-artwork-catalog.json'),'utf8'));
const limit=Math.min(20,Math.max(1,Number(process.env.ARTWORK_AUDIT_LIMIT || 8)));
const base=(process.env.ARTWORK_AUDIT_BASE_URL || 'http://127.0.0.1:8765').replace(/\/$/,'');
const live=process.env.ARTWORK_AUDIT_LIVE === '1';
const pause=ms=>new Promise(resolve=>setTimeout(resolve,ms));

// Regression cases are fixtures only. Production PHP has no song-specific rules.
const regressions=[
  ['P!nk','Sober'],['Dua Lipa','Dance the Night'],
  ['ABBA','Dancing Queen'],['Taylor Swift','Anti-Hero'],
  ['Rihanna','We Found Love'],['Daft Punk','Get Lucky'],
  ['Harry Styles','As It Was'],['Yeah Yeah Yeahs','Heads Will Roll']
];
const ordered=[];
for(const [artist,title] of regressions) {
  const item=catalog.find(row=>row.artist===artist&&row.title===title);
  if(item) ordered.push(item);
}
// Alternate station spellings are input fixtures, never production exceptions.
ordered.push({category:'alias',artist:'PINK',title:'Sober'});
ordered.push({category:'unicode',artist:'Taylor Swift',title:'Anti–Hero'});
for(const category of new Set(catalog.map(x=>x.category))) {
  for(const item of catalog.filter(x=>x.category===category)) {
    if(!ordered.includes(item)) ordered.push(item);
  }
}
const sample=ordered.slice(0,limit);
if(!live) {
  console.log('Offline catalog: '+catalog.length+' nummers, '+new Set(catalog.map(x=>x.category)).size+' categorieën.');
  console.log('Live audit uit. Activeer met ARTWORK_AUDIT_LIVE=1. Maximaal 20 nummers per uitvoering.');
  process.exit(0);
}
const results=[];
let networkErrors=0,totalUpstream=0,totalCovers=0,cacheHits=0;
async function query(item) {
  const params=new URLSearchParams({artist:item.artist,title:item.title});
  const url=base+'/radio-artwork.php?'+params;
  const start=performance.now();
  try {
    const response=await fetch(url,{signal:AbortSignal.timeout(17000),headers:{accept:'application/json'},cache:'no-store'});
    const data=await response.json();
    const elapsed=Math.round(performance.now()-start);
    const mb=Number(response.headers.get('x-radio-artwork-musicbrainz')||0);
    const covers=Number(response.headers.get('x-radio-artwork-coverchecks')||0);
    totalUpstream+=mb;totalCovers+=covers;
    if(response.headers.get('x-radio-artwork-cache')==='HIT') cacheHits++;
    const valid=data.image&&/^https:\/\/(?:coverartarchive\.org\/release-group\/[a-f0-9-]{36}\/front-(?:250|500)|upload\.wikimedia\.org\/wikipedia\/commons\/)/.test(data.image);
    return {...item,elapsedMs:elapsed,httpStatus:response.status,
      status:data.status || (data.image?'ok':'not_found'),
      kind:data.kind || null,image:data.image || null,validUrl:Boolean(valid),
      creditUrl:data.creditUrl || null,musicbrainzRequests:mb,coverChecks:covers,
      cache:response.headers.get('x-radio-artwork-cache') || 'unknown',
      accuracy:'manual_review_required'};
  } catch(error) {
    networkErrors++;
    return {...item,elapsedMs:Math.round(performance.now()-start),
      status:'network_error',error:String(error),image:null,
      accuracy:'not_assessed'};
  }
}
for(const item of sample) {
  const result=await query(item);
  results.push(result);
  console.log([result.artist,result.title,result.status,result.elapsedMs+'ms',
    result.kind||'none',result.cache,result.creditUrl||''].join(' | '));
  // Bounded music metadata lookups: sequential, no burst across this test client.
  await pause(1350);
}
// Re-request first track to measure warm-cache behavior, without new MB lookups.
const cached=await query(sample[0]);
const successful=results.filter(x=>x.image&&x.validUrl).length;
const transient=results.filter(x=>x.status==='temporary_error'||x.status==='network_error').length;
const missing=results.filter(x=>x.status==='not_found').length;
const durations=results.map(x=>x.elapsedMs).sort((a,b)=>a-b);
const p95=durations[Math.min(durations.length-1,Math.ceil(durations.length*.95)-1)]||0;
const report={source:base,date:new Date().toISOString(),
  catalogSize:catalog.length,sampled:sample.length,
  found:successful,coveragePct:Math.round(successful*100/sample.length),
  temporaryErrors:transient,notFound:missing,networkErrors,
  coldP95Ms:p95,warmSampleMs:cached.elapsedMs,warmSampleStatus:cached.status,
  apiMusicbrainzRequests:totalUpstream,apiCoverChecks:totalCovers,cacheHits,
  accuracy:'Not automatically verified. Check release group and cover image manually.',
  results};
console.log('AUDIT '+JSON.stringify({...report,results:undefined}));
await writeFile(process.env.ARTWORK_AUDIT_REPORT || 'radio-artwork-audit.json',
  JSON.stringify(report,null,2)+'\n');
