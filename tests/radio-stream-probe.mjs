const urls = [
  ["Qmusic Non-Stop", "https://stream.qmusic.nl/nonstop/mp3"],
  ["Qmusic Easy", "https://streams.radio.dpgmedia.cloud/redirect/qnl_easy/mp3"],
  ["Qmusic Energy", "https://streams.radio.dpgmedia.cloud/redirect/qnl_energy/mp3"],
  ["Radio Noordzee", "https://playerservices.streamtheworld.com/api/livestream-redirect/TLPSTR17.mp3"],
  ["L1 Radio", "https://d34pj260kw1xmk.cloudfront.net/icecast/l1/radio-bb-mp3"],
  ["Omroep Brabant", "https://av.omroepbrabant.nl/icecast/omroepbrabant/mp3hq"],
  ["NPO Klassiek", "https://icecast.omroep.nl/radio4-bb-mp3"],
  ["NPO BLEND", "https://icecast.omroep.nl/npoblend-bb-mp3"],
  ["NPO Sterren NL", "https://icecast.omroep.nl/radio2-sterrennl-mp3"],
  ["Sublime", "https://playerservices.streamtheworld.com/api/livestream-redirect/SUBLIME.mp3"],
  ["Grand Prix Radio", "https://playerservices.streamtheworld.com/api/livestream-redirect/GRAND_PRIX_RADIO.mp3"],
  ["KINK 80's", "https://playerservices.streamtheworld.com/api/livestream-redirect/KINK_DNA.mp3"],
  ["KINK 90's", "https://playerservices.streamtheworld.com/api/livestream-redirect/KINK_90S.mp3"],
  ["KINK Distortion", "https://playerservices.streamtheworld.com/api/livestream-redirect/KINK_DISTORTION.mp3"],
  ["Radio 10 80's", "https://playerservices.streamtheworld.com/api/livestream-redirect/TLPSTR20.mp3"],
  ["Radio 10 90's", "https://playerservices.streamtheworld.com/api/livestream-redirect/TLPSTR22.mp3"],
  ["Radio 10 Non-Stop", "https://playerservices.streamtheworld.com/api/livestream-redirect/TLPSTR15.mp3"],
  ["Sky Radio Hits", "https://playerservices.streamtheworld.com/api/livestream-redirect/SRGSTR01.mp3"],
  ["Sky Radio Love Songs", "https://playerservices.streamtheworld.com/api/livestream-redirect/SRGSTR03.mp3"],
  ["RADIONL", "https://stream.radionl.fm/radionl"],
  ["RTV Noord", "https://media.rtvnoord.nl/icecast/rtvnoord/radio"],
  ["Omrop Fryslân", "https://d3pvma9xb2775h.cloudfront.net/icecast/omropfryslan/radio.mp3"],
  ["RTV Drenthe", "https://cdn.rtvdrenthe.nl/icecast/rtvdrenthe/rtvradio"],
  ["Radio Oost", "https://streams.rtvoost.nl/audio/oost/mp3"],
  ["Omroep Flevoland", "https://stream.omroepflevoland.nl/icecast/omroepflevoland/stream2"],
  ["Radio Gelderland", "https://d2od87akyl46nm.cloudfront.net/icecast/omroepgelderland/radiogelderland"],
  ["Radio M Utrecht", "https://d18rwjdhpr8dcw.cloudfront.net/icecast/rtvutrecht/radiomutrecht-bb-mp3"],
  ["NH Radio", "https://ice.cr6.streamzilla.xlcdn.com:8000/sz=nhnieuws=NHRadio_mp3"],
  ["Radio West", "https://d3jhv0ayn0z3fg.cloudfront.net/icecast/omroepwest/radio"],
  ["Radio Rijnmond", "https://d2e9xgjjdd9cr5.cloudfront.net/icecast/rijnmond/radio-mp3"],
  ["Omroep Zeeland", "https://d3isaxd2t6q8zm.cloudfront.net/icecast/omroepzeeland/omroepzeeland_radio"]
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
if (checks.some(x=>!x.ok)) {
  console.error("STREAM PROBE ERRORS:",checks.filter(x=>!x.ok).map(x=>x.name).join(", "));
  process.exitCode=1;
} else {console.log("ALL PROBED STREAMS RETURNED AUDIO");}
