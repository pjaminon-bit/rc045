// Diagnose: inspecteer één actueel ICY-metadatablok per nieuwe VRT-stream.
// Geen playlists; geen claims over ontbrekende metadata.
const channels = [
  ["MNM Hits", "mnm_hits"],
  ["De Jaren Nul", "stubru_dejarennul"],
  ["Bruut", "stubru_bruut"],
  ["UNTZ", "stubru_untz"],
  ["Vuurland", "stubru_tgs"],
  ["Radio 2 Relax", "radio2_relax"],
  ["Ketnet Hits", "ketnetradio"]
];

await Promise.all(channels.map(async ([name, slug]) => {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 9500);
  try {
    const response = await fetch(
      "https://icecast.vrtcdn.be/" + slug + "-high.mp3",
      { headers: { "Icy-MetaData": "1" }, signal: controller.signal }
    );
    const interval = Number(response.headers.get("icy-metaint"));
    if (!response.ok || !Number.isInteger(interval) || interval < 1 || interval > 262144) {
      console.log(JSON.stringify({ name, status: response.status, meta: "unavailable" }));
      return;
    }
    const reader = response.body.getReader();
    const chunks = [];
    let size = 0;
    let need = interval + 1;
    while (size < need && size < 270000) {
      const { value, done } = await reader.read();
      if (done) break;
      chunks.push(Buffer.from(value));
      size += value.length;
    }
    let all = Buffer.concat(chunks);
    if (all.length <= interval) {
      console.log(JSON.stringify({ name, meta: "short_stream" }));
      return;
    }
    need += all[interval] * 16;
    while (size < need && size < 270000) {
      const { value, done } = await reader.read();
      if (done) break;
      chunks.push(Buffer.from(value));
      size += value.length;
    }
    all = Buffer.concat(chunks);
    const title = all.subarray(interval + 1, need).toString("utf8")
      .match(/StreamTitle\s*=\s*'([^']*)'/i)?.[1]?.trim() || "";
    console.log(JSON.stringify({
      name,
      icyMetaInt: interval,
      streamTitle: title.slice(0, 180),
      hasArtistTitle: title.includes(" - ")
    }));
  } catch (error) {
    console.log(JSON.stringify({ name, meta: "request_error", error: String(error) }));
  } finally {
    controller.abort();
    clearTimeout(timeout);
  }
}));
