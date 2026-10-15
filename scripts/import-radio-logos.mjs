/* Haal eenmalig de bestaande zenderlogo's op als echte, lokale PNG-assets.
   Gebruik: npm install --no-save sharp && node scripts/import-radio-logos.mjs
   De trackinfo, zenders en streams worden niet aangepast. */
import { readFile, writeFile, mkdir, stat } from "node:fs/promises";
import path from "node:path";
import sharp from "sharp";

const htmlPath = "radio.html";
const folder = "assets/radio/logos";
const raw = await readFile(htmlPath, "utf8");
const sectionStart = raw.indexOf("const stations = [");
const sectionEnd = raw.indexOf("/* =========================================================\n   ELEMENTS", sectionStart);
if (sectionStart < 0 || sectionEnd < 0) throw Error("Stationconfiguratie niet gevonden");

let section = raw.slice(sectionStart, sectionEnd);
const stations = [...section.matchAll(/\{\s*id:\s*"([^"]+)"(?:(?!\n  \}).)*?logo:\s*"(https?:\/\/[^"]+)"/gs)]
  .map(([, id, url]) => ({ id, url }));
if (stations.length !== 56) throw Error("Verwacht 56 stations, gevonden: " + stations.length);
const assets = new Map();
for (const station of stations) {
  if (!assets.has(station.url)) {
    const slug = station.id.replace(/[^a-z0-9-]/gi, "-").toLowerCase();
    assets.set(station.url, { file: folder + "/" + slug + ".png", stations: [] });
  }
  assets.get(station.url).stations.push(station.id);
}
if (assets.size !== 43) throw Error("Verwacht 43 unieke bronlogo's, gevonden: " + assets.size);

await mkdir(folder, { recursive: true });
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const failures = [];
const records = [];

async function download(url) {
  let last;
  for (let attempt = 1; attempt <= 3; attempt++) {
    try {
      const response = await fetch(url, {
        signal: AbortSignal.timeout(25000),
        headers: {
          "User-Agent": "RC045-radio-assets/1.0 (+https://rc045.nl/radio.html)",
          "Accept": "image/svg+xml,image/png,image/jpeg,image/webp,image/*;q=0.8"
        },
        redirect: "follow"
      });
      if (!response.ok) throw Error("HTTP " + response.status + " (" + response.url + ")");
      const len = Number(response.headers.get("content-length") || 0);
      if (len > 4000000) throw Error("Afbeelding te groot: " + len);
      const bytes = Buffer.from(await response.arrayBuffer());
      if (bytes.length > 4000000 || bytes.length < 50) throw Error("Ongeldige omvang: " + bytes.length);
      const mime = response.headers.get("content-type") || "";
      if (mime.includes("text/html")) throw Error("HTML ontvangen in plaats van afbeelding");
      return { bytes, mime, finalUrl: response.url };
    } catch (error) {
      last = error;
      if (attempt < 3) await sleep(900 * attempt);
    }
  }
  throw last;
}

for (const [url, info] of assets) {
  try {
    const source = await download(url);
    // PNG voorkomt het direct serveren van eventueel onveilige externe SVG-code.
    const isSvg = /\.svg(?:$|\?)/i.test(source.finalUrl) ||
      source.mime.includes("svg") || source.bytes.subarray(0, 500).toString().includes("<svg");
    const image = sharp(source.bytes, { density: isSvg ? 450 : 72, limitInputPixels: 35000000 });
    const result = await image
      .resize({ width: 512, height: 512, fit: "inside", withoutEnlargement: !isSvg })
      .png({ compressionLevel: 9, effort: 7 })
      .toBuffer();
    await writeFile(info.file, result);
    // Alleen het logo-URL veld wordt gewijzigd; de overige HTML blijft identiek.
    const needle = 'logo: "' + url + '"';
    const replacement = 'logo: "/' + info.file + '"';
    if (!section.includes(needle)) throw Error("Logo-referentie onverwacht verdwenen: " + url);
    section = section.split(needle).join(replacement);
    records.push({ file: info.file, source: url, stations: info.stations, bytes: result.length });
    console.log("OK " + info.file + " (" + result.length + " bytes)");
  } catch (error) {
    failures.push({ source: url, stations: info.stations, error: String(error.message || error) });
    console.error("MISLUKT " + info.stations.join(", ") + ": " + String(error.message || error));
  }
}

if (failures.length) {
  // Zonder volledige set GEEN verwijzingen wijzigen: zo blijven logo's werken.
  console.error("\nNiet opgehaald (" + failures.length + "/43):\n" +
    JSON.stringify(failures, null, 2));
  process.exitCode = 1;
} else {
  const output = raw.slice(0, sectionStart) + section + raw.slice(sectionEnd);
  await writeFile(htmlPath, output);
  await writeFile(folder + "/sources.json", JSON.stringify({
    description: "Oorspronkelijke stationlogo-URLs; vaste lokale snapshots, niet periodiek opgehaald.",
    generatedAt: new Date().toISOString(),
    assets: records
  }, null, 2) + "\n");
  for (const item of records) {
    const status = await stat(item.file);
    if (!status.size) throw Error("Leeg logo " + item.file);
  }
  console.log("\nGereed: 56 stations, 43 unieke lokale PNG-logo's.");
}