const urls = [
  ["Qmusic Non-Stop", "https://stream.qmusic.nl/nonstop/mp3"],
  ["Qmusic Easy", "https://streams.radio.dpgmedia.cloud/redirect/qnl_easy/mp3"],
  ["Qmusic Energy", "https://streams.radio.dpgmedia.cloud/redirect/qnl_energy/mp3"],
  ["Radio Noordzee", "https://playerservices.streamtheworld.com/api/livestream-redirect/TLPSTR17.mp3"],
  ["L1 Radio", "https://d34pj260kw1xmk.cloudfront.net/icecast/l1/radio-bb-mp3"],
  ["Brabant A", "https://streaming.omroepbrabant.nl/mp3hq"],
  ["Brabant B", "https://streaming.omroepbrabant.nl/mp3"],
  ["Brabant C", "https://av.omroepbrabant.nl/icecast/omroepbrabant/mp3"],
  ["Brabant D", "https://av.omroepbrabant.nl/icecast/omroepbrabant/mp3hq"],
  ["Brabant E", "https://icecast.stream.bbvms.com/omroepbrabant-mp3hq"],
  ["Brabant F", "https://icecast.stream.bbvms.com/omroepbrabant-mp3"]
];

const checks = await Promise.all(urls.map(async ([name,url]) => {
  const ac = new AbortController();
  const timeout = setTimeout(() => ac.abort(), 12000);
  try {
    const response = await fetch(url, {
      method: "GET", redirect: "follow", signal: ac.signal,
      headers: {"Range":"bytes=0-2048"}
    });
    const result = {
      name, status: response.status,
      type: response.headers.get("content-type"),
      finalUrl: response.url.replace(/\?.*$/, "")
    };
    console.log(JSON.stringify(result));
    return {name, ok: response.ok && /audio|octet-stream|mpeg|aac/i.test(result.type||""), result};
  } catch(e) {
    console.log(JSON.stringify({name, error:String(e)}));
    return {name,ok:false,error:String(e)};
  } finally {clearTimeout(timeout);ac.abort()}
}));
if (checks.slice(0,5).some(x=>!x.ok) || !checks.slice(5).some(x=>x.ok)) {
  console.error("STREAM PROBE ERRORS:",checks.filter(x=>!x.ok).map(x=>x.name).join(", "));
  process.exitCode=1;
} else {console.log("ALL PROBED STREAMS RETURNED AUDIO");}
