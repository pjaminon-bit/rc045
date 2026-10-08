# Nederlandse radio-uitbreiding — 8 oktober 2026

## Omvang en behoud

**21 nieuwe radiozenders**, samen met de 25 bestaande dus **46**:
- NL: 35 (16 bestaande en 19 nieuwe)
- BE: 9 (alle 9 bestaande, ongewijzigd)
- Lokaal NL: 2 (L1 Radio en Omroep Brabant)

De oorspronkelijke stationrecords en de bestaande TRACK UI- en
TRACK SPLIT-tot-KEYBOARD-programmacode, inclusief providers, polling,
pre-roll, cache, fallback en tracklinks, zijn **byte-voor-byte ongewijzigd**.

Er zijn drie toegankelijke tabs (NL, BE en Lokaal NL). De tabkeuze wordt
lokaal opgeslagen als `radio-country-tab`. Zoekopdrachten tonen
tijdelijk treffers uit alle drie tabs, zonder de favoriete zenders,
ingeklapte landen of andere voorkeuren te veranderen.

## Trackinfo: uitsluitend bestaande providers hergebruikt

- **NPO Klassiek**: `npo-mini`, kanaal `npo-radio-4`.
- **NPO BLEND**: `npo-mini`, kanaal `npo-blend`.
- **NPO Sterren NL**: `npo-mini`, kanaal `npo-sterren-nl`.
- Alle drie de NPO-miniplayerfeeds zijn rechtstreeks geraadpleegd,
  leveren hun eigen channelrecord en programmagegevens en gebruiken
  dus de bestaande correcte NPO-track- en programmaparser.
- **Radio Noordzee, Sublime, Grand Prix Radio, KINK-themakanalen,
  Radio 10-themakanalen, Sky Radio-thema's**: bestaande `triton-track`
  parser, met een eigen mountname per zender (behalve de hieronder
  benoemde gedeelde KINK Classics/80's-bron).

Voor **Qmusic Non-Stop, Easy, Energy, RADIONL, L1 Radio en Omroep Brabant**
is geen afzonderlijke metadatafeed gekoppeld zolang die niet correct
geverifieerd kan worden. Deze kanalen **spelen wel audio af** maar de
bestaande player verbergt trackinfo als er geen metadatafeed is.
Er worden geen titels of artiesten van een andere zender ingevuld.

## Gecontroleerde streams

Alle 21 nieuwe MP3-streams hebben op 8 oktober 2026 in GitHub Actions
een status 200 of 206 en `Content-Type: audio/mpeg` geretourneerd.
De streamchecks worden voor alle 21 kandidaat-URLs herhaald bij
toekomstige wijziging van `radio.html` in deze pull request.

Specifiek:
- Qmusic Easy: `qnl_easy` redirect, succesvol naar
  `https://audio-streaming.qmusic.nl/Qmusic_nl_easy.mp3`.
- Qmusic Energy: `qnl_energy` redirect, succesvol naar
  `https://audio-streaming.qmusic.nl/Qmusic_nl_energy.mp3`.
- Qmusic Non-Stop: succesvol naar
  `https://audio-streaming.qmusic.nl/Qmusic_nl_nonstop.mp3`.
- L1 Radio: `https://d34pj260kw1xmk.cloudfront.net/icecast/l1/radio-bb-mp3`.
- Omroep Brabant: `https://av.omroepbrabant.nl/icecast/omroepbrabant/mp3hq`.
  Een eerder geselecteerde CloudFront-URL gaf HTTP 502 en is vervangen.

**KINK Classics en KINK 80's**: beide zijn afzonderlijke keuzes in de
gebruikersinterface maar gebruiken `KINK_DNA`. KINK publiceert momenteel
dezelfde directe playlist bij Classics en 80's. Dit is dus geen
bevestigde onafhankelijke tweede stream.

## Regressie en resterende testen

De bestaande radiotechnische CI-tests testen 46 unieke station-ID's,
indeling, drie tabs, JavaScript-syntax, huidige PWA/zoeken/miniplayer,
en audioherstel. Trackinfo-blokken zijn byte-voor-byte vergeleken met
de bestaande bron.

HTTP-streamchecks bewijzen dat de audio-URL's bereikbaar zijn, **niet**
dat alle kanalen op fysieke iOS Safari, Cast of AirPlay hoorbaar zijn.

Handmatig vóór merge nalopen:
1. Alle 21 zenders afspelen op een iPhone met Safari.
2. Tabwissel, toetsenbord, zoeken over tabs, favorieten en tabvoorkeur
   na herladen.
3. De 25 bestaande zenders blijven dezelfde (actuele) trackinfo tonen.
4. Nieuwe NPO- en Triton-kanalen tonen alleen eigen, geldige trackinfo.
5. Controle van logo's en zichtbaar gedrag van de miniplayer.
6. Safari/PWA, sleep timer, AirPlay en Chromecast.
