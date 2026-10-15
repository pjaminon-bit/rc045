/* Alleen de radio-shell cachen; externe streams en trackinfo blijven live. */
const CACHE_NAME = "radio-shell-v1";
/* Bewaar uitsluitend vaste zenderlogo's; audio en metadata nooit. */
const LOGO_CACHE_NAME = "radio-station-logos-v1";
const MAX_LOGO_ENTRIES = 80;
const LOGO_SOURCES = [
  ["https://commons.wikimedia.org", "/wiki/Special:Redirect/file/"],
  ["https://static.bnr.nl", "/assets/bnr-next/logo.png"],
  ["https://plug.kink.nl", "/img/top-logo.png"],
  ["https://www.vrt.be", "/nl/assets/images/"],
  ["https://images.seeklogo.com", "/logo-png/"],
  ["https://www.google.com", "/s2/favicons"]
];

function isStationLogo(request, url) {
  return request.destination === "image" &&
    url.protocol === "https:" &&
    LOGO_SOURCES.some(([origin, path]) =>
      url.origin === origin && url.pathname.startsWith(path));
}

async function storeLogo(request, response) {
  try {
    const cache = await caches.open(LOGO_CACHE_NAME);
    await cache.put(request, response);

    // Cache API geeft sleutels in invoegvolgorde; oudste logo's eerst weg.
    const keys = await cache.keys();
    const excess = keys.length - MAX_LOGO_ENTRIES;
    if (excess > 0) {
      await Promise.all(keys.slice(0, excess).map(key => cache.delete(key)));
    }
  } catch {
    // Opslagquota of privacy-instellingen mogen een afbeelding nooit blokkeren.
  }
}

const SHELL_FILES = [
  "/radio.html",
  "/radio-manifest.json",
  "/apple-touch-icon.png",
  "/android-chrome-192x192.png",
  "/android-chrome-512x512.png"
];

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => cache.addAll(SHELL_FILES))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys().then(async (names) => {
      await Promise.all(names
        .filter((name) =>
          (name.startsWith("radio-shell-") && name !== CACHE_NAME) ||
          (name.startsWith("radio-station-logos-") && name !== LOGO_CACHE_NAME))
        .map((name) => caches.delete(name))
      );
      await self.clients.claim();
    })
  );
});

self.addEventListener("fetch", (event) => {
  const request = event.request;
  if (request.method !== "GET") return;

  const url = new URL(request.url);

  // Alleen daadwerkelijk door de radiopagina gevraagde zenderlogo's cachen.
  // Een externe SVG/PNG heeft vaak een opaque response; Cache API ondersteunt die.
  if (isStationLogo(request, url)) {
    event.respondWith((async () => {
      try {
        const cache = await caches.open(LOGO_CACHE_NAME);
        const saved = await cache.match(request);
        if (saved) return saved;
      } catch {
        // Zonder opslag blijven de originele logo-URL's gewoon werken.
      }

      const response = await fetch(request);
      if (response.ok || response.type === "opaque") {
        event.waitUntil(storeLogo(request, response.clone()));
      }
      return response;
    })());
    return;
  }

  if (url.origin !== self.location.origin) return;

  // Alleen navigatie naar de radiopagina: netwerk eerst,
  // offline de laatst opgehaalde versie van de radio-shell.
  if (request.mode === "navigate" && url.pathname === "/radio.html") {
    event.respondWith(
      fetch(request)
        .then(async (response) => {
          if (response.ok) {
            const cache = await caches.open(CACHE_NAME);
            await cache.put("/radio.html", response.clone());
          }
          return response;
        })
        .catch(async () =>
          (await caches.match("/radio.html")) || Response.error()
        )
    );
    return;
  }

  // Alleen statische PWA-bestanden: geen audio, API's of metadata.
  if (SHELL_FILES.includes(url.pathname) && url.pathname !== "/radio.html") {
    event.respondWith(
      caches.match(request).then((cached) => cached || fetch(request))
    );
  }
});
