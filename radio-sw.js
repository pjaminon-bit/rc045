/* Alleen de radio-shell cachen; externe streams en trackinfo blijven live. */
const CACHE_NAME = "radio-shell-v3";
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
      .then(async (cache) => {
        // De offline radio-interface is essentieel, maar optionele iconen
        // mogen een update van de serviceworker niet blokkeren.
        await cache.add(new Request("/radio.html", { cache: "reload" }));
        await Promise.allSettled(
          SHELL_FILES.filter((path) => path !== "/radio.html")
            .map((path) => cache.add(path))
        );
      })
      .then(() => self.skipWaiting())
  );
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys().then(async (names) => {
      await Promise.all(names
        .filter((name) => name.startsWith("radio-shell-") && name !== CACHE_NAME)
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
  if (url.origin !== self.location.origin) return;

  // Alleen navigatie naar de radiopagina: netwerk eerst,
  // offline de laatst opgehaalde versie van de radio-shell.
  if (request.mode === "navigate" && url.pathname === "/radio.html") {
    event.respondWith(
      // Sla de HTTP-cache over: na een merge nooit stilzwijgend een oude HTML-shell.
      // De Cache Storage-kopie blijft beschikbaar als offline-fallback.
      fetch(request, { cache: "no-store" })
        .then(async (response) => {
          if (response.ok) {
            try {
              const cache = await caches.open(CACHE_NAME);
              await cache.put("/radio.html", response.clone());
            } catch (error) {
              // Een opslagfout mag een geslaagde online navigatie niet
              // vervangen door de offline-versie.
              console.warn("Radio PWA-cache kon niet worden bijgewerkt:", error);
            }
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
