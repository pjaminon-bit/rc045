<?php
// ===== Vaste changelog-regels (ontwikkelaar) =====
//
// Dit bestand hoort bij het tabblad "Changelog" in beheer.php en bevat de
// regels die bij de code horen: alles wat er aan de website zelf is
// gebouwd, gewijzigd of opgelost. Het staat bewust in de repo en niet in
// data/, zodat een nieuwe regel gewoon met de code meegaat bij de volgende
// deploy en niet handmatig in het beheerpaneel hoeft te worden ingetypt.
//
// Regels die het bestuur zelf via beheer.php toevoegt komen in
// data/changelog.json terecht. Beheer.php voegt die twee lijsten samen en
// toont ze op datum. De regels hieronder zijn in het beheerpaneel niet te
// bewerken of te verwijderen: bij de volgende deploy zouden ze toch weer
// terugkomen.
//
// Opbouw per regel:
//   datum  yyyy-mm-dd
//   cat    nieuw | verbeterd | opgelost | beveiliging | onderhoud
//   titel  eén korte zin, geen punt aan het eind
//   tekst  optionele toelichting, mag leeg blijven
//
// Nieuwste regel bovenaan.

return [

  [
    'datum' => '2026-08-13',
    'cat' => 'opgelost',
    'titel' => 'Taalvlaggetjes onzichtbaar in Chrome, Edge en Brave op Windows',
    'tekst' => 'De vlaggen bij de taalkeuze stonden als emoji in de tekst. Windows levert zelf geen vlag-emoji mee aan Chromium-browsers (Chrome, Edge, Brave), waardoor daar twee letters te zien waren in plaats van een vlaggetje; in Firefox ging dit toevallig wel goed omdat die browser zijn eigen emoji-lettertype meelevert. De vlaggen zijn nu kleine afbeeldingen (SVG) die overal hetzelfde tonen.',
  ],
  [
    'datum' => '2026-08-13',
    'cat' => 'verbeterd',
    'titel' => 'IBAN-kopieerknop gelijkgetrokken',
    'tekst' => 'De knop op de bedankt-pagina had een ander uiterlijk en een aparte werking dan dezelfde knop op het aanmeldformulier. Nu identiek.',
  ],
  [
    'datum' => '2026-08-13',
    'cat' => 'verbeterd',
    'titel' => 'Opmaak en teksten van de hele site nagelopen',
    'tekst' => 'Typefouten en ontbrekende leestekens gecorrigeerd, dubbele stijlregels samengevoegd, lazy loading toegevoegd aan de footer-afbeeldingen, en dode code (ongebruikte teksten en CSS) opgeruimd.',
  ],

  [
    'datum' => '2026-08-12',
    'cat' => 'nieuw',
    'titel' => 'Tabblad Operationele taken',
    'tekst' => 'Terugkerende klussen die de club sowieso moet doen, met een uitvoeringsfrequentie (dagelijks tot jaarlijks, of naar behoefte) en desgewenst een verantwoordelijk lid. Bij het afmelden als uitgevoerd komt de datum in de geschiedenis van die taak te staan. Een taak kan op "Bestuursleden" gezet worden: die ziet een lid zonder bestuursfunctie dan niet. Wie toegang tot dit tabblad krijgt, staat net als bij de andere tabbladen bij Gebruikers.',
  ],
  [
    'datum' => '2026-08-12',
    'cat' => 'verbeterd',
    'titel' => 'Bestuurstaak toewijzen aan een lid',
    'tekst' => 'Bij een taak in de Takenlijst staat nu ook een veld "Toegewezen aan", naast de bestaande koppeling met een vergadering of commissie.',
  ],

  [
    'datum' => '2026-08-12',
    'cat' => 'beveiliging',
    'titel' => 'Vergaderingen en takenlijst afgeschermd via .htaccess',
    'tekst' => 'De databestanden van bestuursvergaderingen, ledenvergaderingen/ALV\'s en de takenlijst stonden nog niet in de blokkeerregel, terwijl de code er al wel van uitging.',
  ],

  [
    'datum' => '2026-08-12',
    'cat' => 'nieuw',
    'titel' => 'Tabblad Takenlijst',
    'tekst' => 'Bestuurstaken bijhouden met een status (open, in behandeling, afgerond), desgewenst gekoppeld aan een bestuursvergadering, een ledenvergadering/ALV of een commissie. Staat bij Bestuur, alleen zichtbaar voor leden met een bestuursfunctie.',
  ],

  [
    'datum' => '2026-08-12',
    'cat' => 'nieuw',
    'titel' => 'Tabblad Ledenvergadering, inclusief ALV',
    'tekst' => 'Ledenvergaderingen en ALV\'s los van de bestuursvergaderingen bijhouden, met een eigen nummering. Een ALV is qua opzet gewoon een ledenvergadering met dat label erbij. Datum, tijd, locatie, agendapunten, notulen en een presentielijst tegen de actieve leden, net als bij de bestuursvergadering maar dan voor de hele club.',
  ],

  [
    'datum' => '2026-08-12',
    'cat' => 'nieuw',
    'titel' => 'Tabblad Commissies: bestuurslid en commissiehoofd erbij',
    'tekst' => 'De commissielijst is een eigen tabblad geworden (stond eerst onderaan Leden) en heeft nu ook een verantwoordelijk bestuurslid en een commissiehoofd per commissie. Een commissiehoofd hoeft geen bestuurslid te zijn. Aanmaken, hernoemen en verwijderen werkt zoals voorheen.',
  ],

  [
    'datum' => '2026-08-12',
    'cat' => 'verbeterd',
    'titel' => 'Datums met streepjes, tijd van een vergadering ook meteen herschreven',
    'tekst' => 'Datumvelden in beheer tonen nu dd-mm-jjjj in plaats van dd/mm/jjjj. De aanvangstijd van een vergadering werd bij het opslaan al herkend (2100 werd 21:00), dat gebeurt nu ook meteen bij het verlaten van het veld, net als bij een datum.',
  ],

  [
    'datum' => '2026-08-12',
    'cat' => 'verbeterd',
    'titel' => 'Datumveld schrijft zichzelf meteen om naar dd/mm/jjjj',
    'tekst' => 'Vorige regel loste alleen het opslaan op; typte je bijvoorbeeld 01092026 dan bleef dat zo in beeld staan tot de pagina opnieuw laadde. Elk datumveld in beheer herschrijft de invoer nu meteen zodra je naar een ander veld gaat, ook bij later toegevoegde regels zoals een extra agendapunt of contributiejaar.',
  ],

  [
    'datum' => '2026-08-12',
    'cat' => 'opgelost',
    'titel' => 'Datum aaneengeschreven invullen werkte nergens in beheer',
    'tekst' => 'Een datum als 01092026 gaf de melding "Die datum begrijp ik niet". Zulke invoer wordt nu ook gelezen als 01/09/2026: bij een vergadering, een lid (geboortedatum, inschrijfdatum, contributie), en bij Agenda, Nieuws, Media en Fotoboek.',
  ],

  // ===== augustus 2026 =====
  [
    'datum' => '2026-08-12',
    'cat' => 'opgelost',
    'titel' => 'Import maakte dubbele leden aan bij een lid zonder mailadres en zonder geboortedatum',
    'tekst' => 'Zo iemand werd bij een tweede import niet herkend en kwam er een tweede keer bij te staan, ook als je een export meteen weer inlas. De herkenning kijkt nu ook naar het lidnummer samen met de naam, en als laatste redmiddel naar alleen de naam, mits die maar bij één lid voorkomt en er niets tegenspreekt. Twee leden met dezelfde naam worden dus nooit stiekem samengevoegd. In het controleoverzicht staat per regel waarop hij herkend is, met een waarschuwing als dat alleen de naam was.',
  ],
  [
    'datum' => '2026-08-12',
    'cat' => 'nieuw',
    'titel' => 'Tabblad Bestuursvergadering',
    'tekst' => 'Vergaderingen vastleggen met datum, tijd, locatie, presentielijst, agendapunten en notulen. Bij elk agendapunt kun je na afloop het besluit invullen. Ieder bestuurslid kan een vergadering aanmaken en bijwerken, ongeacht de functie. Het tabblad is alleen zichtbaar voor leden met een bestuursfunctie.',
  ],
  [
    'datum' => '2026-08-12',
    'cat' => 'nieuw',
    'titel' => 'Rol bij een lid: bestuursfunctie en commissies',
    'tekst' => 'Onder Vereniging staat nu een keuzelijst voor voorzitter, penningmeester, secretaris of bestuurslid, plus vinkjes voor de commissies. Voorzitter, penningmeester en secretaris tellen automatisch mee als bestuurslid, en een bestuurslid mag ook in een commissie zitten. De rol bepaalt wie het tabblad Bestuursvergadering ziet.',
  ],
  [
    'datum' => '2026-08-12',
    'cat' => 'nieuw',
    'titel' => 'Commissies zelf samenstellen',
    'tekst' => 'Onderaan het tabblad Leden staat een lijst waarin je commissies toevoegt, hernoemt of verwijdert, met het aantal leden erbij. Hernoemen laat de koppeling met de leden intact; verwijderen haalt de commissie ook bij alle leden weg.',
  ],
  [
    'datum' => '2026-08-12',
    'cat' => 'verbeterd',
    'titel' => 'Ledenlijst: filteren en zoeken op rol',
    'tekst' => 'Nieuwe keuzelijst voor alle bestuursleden, een losse functie, een commissie of juist de leden zonder rol. De rol staat ook onder de naam in het overzicht en je kunt erop zoeken. Bestuursfunctie en commissies zitten daarnaast in de CSV-export en worden bij een import weer herkend.',
  ],
  [
    'datum' => '2026-08-12',
    'cat' => 'opgelost',
    'titel' => 'Beheerpaneel op telefoon: piepkleine invulvelden en pagina buiten beeld',
    'tekst' => 'Bij Vragen, Sponsors, Agenda, Nieuws en de tekstpagina\'s werden invulvelden soms tot één letter breed samengedrukt, met grote lege gaten ertussen, en liep de kaart buiten het scherm. De vertaalvelden staan op een smal scherm nu netjes onder elkaar en de kopregels breken af in plaats van de pagina breder te maken.',
  ],
  [
    'datum' => '2026-08-11',
    'cat' => 'nieuw',
    'titel' => 'Changelog in het beheerpaneel',
    'tekst' => 'Nieuw tabblad met alle wijzigingen aan de website, op datum en per categorie. De historie is opgebouwd uit de commits vanaf de eerste versie. Het bestuur kan zelf regels toevoegen; toegang is per gebruiker in te stellen bij Gebruikers.',
  ],
  [
    'datum' => '2026-08-11',
    'cat' => 'verbeterd',
    'titel' => 'Contributie: snelkeuze bedrag met pro rata',
    'tekst' => 'Bij een contributieregel staan nu knopjes voor het volledige jaarbedrag en het pro-rata bedrag volgens de rekentabel. Handmatig invullen blijft mogelijk.',
  ],
  [
    'datum' => '2026-08-11',
    'cat' => 'verbeterd',
    'titel' => 'Ledenformulier: land als keuzelijst, Gemeente heet nu Woonplaats',
    'tekst' => 'Veelgebruikte landen staan bovenaan de lijst.',
  ],
  [
    'datum' => '2026-08-11',
    'cat' => 'verbeterd',
    'titel' => 'Lidnummer wordt gecontroleerd in plaats van vastgezet',
    'tekst' => 'Een dubbel lidnummer is nu zichtbaar en op te lossen, in plaats van dat het veld helemaal op slot zit.',
  ],
  [
    'datum' => '2026-08-11',
    'cat' => 'opgelost',
    'titel' => 'Knop "Gebruik nummer" deed niets tijdens het bewerken van een lid',
    'tekst' => '',
  ],
  [
    'datum' => '2026-08-11',
    'cat' => 'opgelost',
    'titel' => 'Een leeg lid verwijderen deed niets',
    'tekst' => '',
  ],
  [
    'datum' => '2026-08-11',
    'cat' => 'verbeterd',
    'titel' => 'Ledenlijst: zoeken op jeugdlid of senior',
    'tekst' => '',
  ],
  [
    'datum' => '2026-08-11',
    'cat' => 'nieuw',
    'titel' => 'Contributiestatus van meerdere leden tegelijk aanpassen',
    'tekst' => 'Vink leden aan in de lijst en zet ze in één keer op betaald, open of vrijgesteld.',
  ],
  [
    'datum' => '2026-08-11',
    'cat' => 'verbeterd',
    'titel' => 'Ledenbeheer overzichtelijker',
    'tekst' => 'Het formulier is opgedeeld in secties, de contributiestatus is filterbaar, het nummer staat in een eigen kolom en de gekozen sortering wordt onthouden. Tijdens het bewerken van een lid blijven de lijst en de import verborgen.',
  ],
  [
    'datum' => '2026-08-11',
    'cat' => 'nieuw',
    'titel' => 'Automatisch vertalen naar Engels en Duits',
    'tekst' => 'Bij elk tekstblok in beheer staat een vertaalknop die de Nederlandse tekst via DeepL omzet. De vertaling blijft daarna gewoon met de hand aan te passen.',
  ],
  [
    'datum' => '2026-08-11',
    'cat' => 'verbeterd',
    'titel' => 'Vertalingen standaard ingeklapt',
    'tekst' => 'Per blok is alleen het Nederlands zichtbaar. Engels en Duits schuiven ernaast open met een knop.',
  ],
  [
    'datum' => '2026-08-11',
    'cat' => 'opgelost',
    'titel' => 'Vertaalknop leverde HTML-codes op in gewone tekstvelden',
    'tekst' => '',
  ],
  [
    'datum' => '2026-08-11',
    'cat' => 'nieuw',
    'titel' => 'Navigatiemenu, footer, contactformulier en prijskaarten bewerkbaar',
    'tekst' => 'De laatste vaste teksten op de website staan nu ook in het tabblad Homepage.',
  ],
  [
    'datum' => '2026-08-11',
    'cat' => 'verbeterd',
    'titel' => 'Beheermenu gegroepeerd',
    'tekst' => 'Vier groepen (Pagina\'s, Content, Leden en contributie, Beheer), in dezelfde volgorde als de website zelf. De homepage-velden zitten onder vijf hoofdstukken.',
  ],
  [
    'datum' => '2026-08-11',
    'cat' => 'beveiliging',
    'titel' => 'DeepL-sleutel afgeschermd via .htaccess',
    'tekst' => '',
  ],
  [
    'datum' => '2026-08-11',
    'cat' => 'opgelost',
    'titel' => 'Mailadresveld in ledenbeheer miste opmaak',
    'tekst' => '',
  ],

  [
    'datum' => '2026-08-10',
    'cat' => 'nieuw',
    'titel' => 'Ledenadministratie in beheer',
    'tekst' => 'Ledenlijst met status, sorteerbare kolommen, klikbare statusbadges als snelfilter, meerjarige contributie en import en export via CSV. Een rij aanklikken opent het lid.',
  ],
  [
    'datum' => '2026-08-10',
    'cat' => 'nieuw',
    'titel' => 'Rechten per gebruiker',
    'tekst' => 'Per beheerder is in te stellen welke tabbladen zichtbaar zijn. Gebruikers, Log en Back-ups blijven altijd alleen voor de hoofdbeheerder.',
  ],
  [
    'datum' => '2026-08-10',
    'cat' => 'nieuw',
    'titel' => 'Alle overige paginateksten beheerbaar',
    'tekst' => 'Homepage, Ontstaan, Baanreglement, Aanmelden, Bedankt, Media en Fotoboek zijn nu volledig vanuit beheer aan te passen.',
  ],
  [
    'datum' => '2026-08-10',
    'cat' => 'verbeterd',
    'titel' => 'Lidmaatschapsprijzen op de homepage komen uit de rekentabel',
    'tekst' => 'Eén plek aanpassen is genoeg; de bedragen op de website volgen automatisch.',
  ],
  [
    'datum' => '2026-08-10',
    'cat' => 'verbeterd',
    'titel' => 'Beheer op mobiel bruikbaar',
    'tekst' => 'Menu links met hamburgerknop, tabellen als kaarten op een smal scherm en datums overal in dd/mm/jjjj.',
  ],
  [
    'datum' => '2026-08-10',
    'cat' => 'opgelost',
    'titel' => 'Een veld leegmaken overschreef de standaardtekst niet',
    'tekst' => '',
  ],
  [
    'datum' => '2026-08-10',
    'cat' => 'onderhoud',
    'titel' => 'Deploy-workflow naar actions/checkout v4',
    'tekst' => 'De oude versie draaide op een verouderde Node.js.',
  ],

  [
    'datum' => '2026-08-08',
    'cat' => 'nieuw',
    'titel' => 'Aanmeldingen komen binnen in de ledenadministratie',
    'tekst' => 'Het aanmeldformulier slaat de aanmelding op met status "nieuw", met spamfilter, snelheidsbegrenzing en controle op dubbele aanmeldingen.',
  ],
  [
    'datum' => '2026-08-08',
    'cat' => 'onderhoud',
    'titel' => 'Gedeelde opmaak en scripts',
    'tekst' => 'Navigatie, footer, sponsorblok, hamburgermenu en de terug-naar-boven knop staan nu in styles.css en site-i18n.js in plaats van los in elke pagina.',
  ],

  [
    'datum' => '2026-08-01',
    'cat' => 'nieuw',
    'titel' => 'Openingstijden met tijdelijke statussen',
    'tekst' => 'Gesloten, gesloten wegens onderhoud en gesloten wegens slecht weer, met een statusbadge op de homepage. Tijdelijke statussen vervallen automatisch om 20:00.',
  ],

  // ===== juli 2026 =====
  [
    'datum' => '2026-07-29',
    'cat' => 'onderhoud',
    'titel' => 'Sitemap bijgewerkt',
    'tekst' => '',
  ],
  [
    'datum' => '2026-07-28',
    'cat' => 'verbeterd',
    'titel' => 'Vragen, sponsors en media schalen mee met het scherm',
    'tekst' => '',
  ],
  [
    'datum' => '2026-07-26',
    'cat' => 'nieuw',
    'titel' => 'Automatische back-ups van alle beheerdata',
    'tekst' => 'Voor elke opslag gaat er een kopie naar een afgeschermde map. In het tabblad Back-ups is een eerdere versie per onderdeel terug te zetten.',
  ],
  [
    'datum' => '2026-07-26',
    'cat' => 'beveiliging',
    'titel' => 'Blokkade na vijf mislukte inlogpogingen',
    'tekst' => 'Daarnaast wordt het sessie-ID vernieuwd bij het inloggen.',
  ],
  [
    'datum' => '2026-07-26',
    'cat' => 'nieuw',
    'titel' => 'Logboek met filters',
    'tekst' => 'Wie wat wanneer heeft aangepast, te filteren op gebruiker en onderdeel. Bewaartermijn 90 dagen.',
  ],
  [
    'datum' => '2026-07-26',
    'cat' => 'verbeterd',
    'titel' => 'Foto-upload tot 25 MB met voortgangsbalk',
    'tekst' => '',
  ],
  [
    'datum' => '2026-07-25',
    'cat' => 'nieuw',
    'titel' => 'Fotoboek: albums verbergen en omschrijving toevoegen',
    'tekst' => 'Ook foto\'s vanaf een iPhone (HEIC) worden nu geaccepteerd.',
  ],
  [
    'datum' => '2026-07-25',
    'cat' => 'nieuw',
    'titel' => 'Sponsoroproep in de footer',
    'tekst' => '',
  ],
  [
    'datum' => '2026-07-24',
    'cat' => 'nieuw',
    'titel' => 'Watermerk op fotoboekfoto\'s',
    'tekst' => 'Per album in te stellen, ook toe te passen op al geüploade foto\'s.',
  ],
  [
    'datum' => '2026-07-24',
    'cat' => 'verbeterd',
    'titel' => 'Afgelopen agenda-activiteiten vallen minder op',
    'tekst' => '',
  ],
  [
    'datum' => '2026-07-24',
    'cat' => 'verbeterd',
    'titel' => 'Openingstijden per dag instelbaar',
    'tekst' => 'Inclusief de Facebook-link in de footer, die nu ook uit beheer komt.',
  ],
  [
    'datum' => '2026-07-13',
    'cat' => 'nieuw',
    'titel' => 'Beheerpaneel (beheer.php)',
    'tekst' => 'Eerste versie met tabbladen voor openingstijden, agenda, vragen, sponsors en de rekentabel voor de contributie.',
  ],
  [
    'datum' => '2026-07-13',
    'cat' => 'nieuw',
    'titel' => 'Agenda, vragen en sponsors komen uit databestanden',
    'tekst' => 'De website leest ze in, zodat ze zonder code aan te passen te wijzigen zijn.',
  ],
  [
    'datum' => '2026-07-09',
    'cat' => 'nieuw',
    'titel' => 'Meldingsbalk bovenaan de homepage',
    'tekst' => '',
  ],
  [
    'datum' => '2026-07-08',
    'cat' => 'verbeterd',
    'titel' => 'Footer opgeschoond en contributieteksten bijgewerkt',
    'tekst' => '',
  ],
  [
    'datum' => '2026-07-07',
    'cat' => 'nieuw',
    'titel' => 'Sponsorlogo\'s in de footer',
    'tekst' => '',
  ],
  [
    'datum' => '2026-07-06',
    'cat' => 'nieuw',
    'titel' => 'Bezoekersstatistieken via GoatCounter',
    'tekst' => 'Zonder cookies, dus zonder cookiemelding.',
  ],
  [
    'datum' => '2026-07-06',
    'cat' => 'onderhoud',
    'titel' => 'Automatisch publiceren naar de server',
    'tekst' => 'Elke wijziging in GitHub gaat via een workflow direct naar de hosting.',
  ],
  [
    'datum' => '2026-07-06',
    'cat' => 'onderhoud',
    'titel' => 'Oude adressen doorgestuurd',
    'tekst' => 'Bijvoorbeeld /Over-ons naar het juiste onderdeel op de homepage.',
  ],

  // ===== juni 2026 =====
  [
    'datum' => '2026-06-24',
    'cat' => 'nieuw',
    'titel' => 'Mediapagina',
    'tekst' => 'Overzicht van krantenartikelen en tv-items over RC045.',
  ],
  [
    'datum' => '2026-06-22',
    'cat' => 'nieuw',
    'titel' => 'IBAN kopiëren met één klik',
    'tekst' => 'Op de bedankpagina na het aanmelden.',
  ],
  [
    'datum' => '2026-06-21',
    'cat' => 'beveiliging',
    'titel' => 'Spamfilter op contact- en aanmeldformulier',
    'tekst' => 'Een verborgen veld dat alleen bots invullen.',
  ],
  [
    'datum' => '2026-06-21',
    'cat' => 'nieuw',
    'titel' => 'Foto\'s bij de baan en het ontstaansverhaal',
    'tekst' => 'Met vergroting bij aanklikken.',
  ],
  [
    'datum' => '2026-06-17',
    'cat' => 'nieuw',
    'titel' => 'Website in drie talen',
    'tekst' => 'Nederlands, Engels en Duits, met een taalkeuze die onthouden wordt.',
  ],
  [
    'datum' => '2026-06-15',
    'cat' => 'nieuw',
    'titel' => 'Eerste versie van rc045.nl online',
    'tekst' => '',
  ],

];
