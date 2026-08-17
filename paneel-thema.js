// Themakeuze (systeem/licht/donker), zelfde opzet als site-i18n.js op
// de publieke pagina's, maar hier zelfstandig omdat beheer.php dat
// bestand niet laadt. Wordt zo vroeg mogelijk uitgevoerd (nog voor de
// rest van de pagina rendert) zodat er geen lichte flits te zien is
// als de bezoeker al voor donker had gekozen.
(function () {
  var SLEUTEL = 'rc045_thema';
  var STANDEN = ['systeem', 'licht', 'donker'];
  var ICONEN = { systeem: '\ud83c\udf13', licht: '\u2600\ufe0f', donker: '\ud83c\udf19' };
  var TEKST = { systeem: 'Thema: systeem', licht: 'Thema: licht', donker: 'Thema: donker' };
  var donkerQuery = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

  function gekozenStand() {
    var opgeslagen = null;
    try { opgeslagen = sessionStorage.getItem(SLEUTEL); } catch (e) { /* privemodus */ }
    return STANDEN.indexOf(opgeslagen) === -1 ? 'systeem' : opgeslagen;
  }

  function werkKnopBij(stand) {
    var knop = document.getElementById('thema-switch');
    if (!knop) return;
    knop.textContent = ICONEN[stand];
    knop.setAttribute('aria-label', TEKST[stand]);
    knop.setAttribute('title', TEKST[stand]);
  }

  function toepassen() {
    var stand = gekozenStand();
    var donker = stand === 'donker' || (stand === 'systeem' && donkerQuery && donkerQuery.matches);
    document.documentElement.setAttribute('data-thema', donker ? 'donker' : 'licht');
    werkKnopBij(stand);
  }

  function volgende() {
    var nu = STANDEN.indexOf(gekozenStand());
    var nieuw = STANDEN[(nu + 1) % STANDEN.length];
    try { sessionStorage.setItem(SLEUTEL, nieuw); } catch (e) { /* privemodus */ }
    toepassen();
  }

  toepassen();

  if (donkerQuery) {
    if (donkerQuery.addEventListener) donkerQuery.addEventListener('change', toepassen);
    else if (donkerQuery.addListener) donkerQuery.addListener(toepassen);
  }

  function init() {
    var knop = document.getElementById('thema-switch');
    if (knop) knop.addEventListener('click', volgende);
    werkKnopBij(gekozenStand());
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();

// ===== Leden: autorisatievelden alleen bewerkbaar met recht Gebruikers =====
// De server weigert ongeoorloofde wijzigingen sowieso al in
// ledenNormaliseer(). Dit blok maakt de interface daarmee consistent: zonder
// het expliciete recht Gebruikers worden de twee keuzelijsten vervangen door
// alleen-lezen tekst. De lijst met andere accounts is dan ook niet meer via
// de gewone interface open te klappen.
(function () {
  function initLedenAutorisatieUx() {
    var rolSelect = document.getElementById('lid-bestuursfunctie');
    var accountSelect = document.getElementById('lid-beheer-account');
    if (!rolSelect && !accountSelect) return;

    // Tot de capability-check klaar is zijn de gevoelige velden alvast niet
    // bedienbaar. Bij een fout blijft de veilige, alleen-lezen toestand staan.
    if (rolSelect) rolSelect.disabled = true;
    if (accountSelect) accountSelect.disabled = true;

    function maakAlleenLezen(select, melding) {
      if (!select) return;
      var veld = select.closest ? select.closest('.veld') : select.parentNode;
      var gekozen = select.options && select.selectedIndex >= 0
        ? select.options[select.selectedIndex].textContent.trim()
        : '';

      var waarde = document.createElement('p');
      waarde.style.margin = '8px 0 0';
      var sterk = document.createElement('strong');
      sterk.textContent = gekozen || 'Niet ingevuld';
      waarde.appendChild(sterk);
      select.parentNode.replaceChild(waarde, select);

      if (veld) {
        var hint = veld.querySelector('.hint');
        if (!hint) {
          hint = document.createElement('p');
          hint.className = 'hint';
          veld.appendChild(hint);
        }
        hint.textContent = melding;
      }
    }

    fetch('leden-autorisatie-status.php', {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    })
      .then(function (response) {
        if (!response.ok) throw new Error('status ' + response.status);
        return response.json();
      })
      .then(function (data) {
        if (data && data.magWijzigen === true) {
          if (rolSelect) rolSelect.disabled = false;
          if (accountSelect) accountSelect.disabled = false;
          return;
        }
        maakAlleenLezen(
          rolSelect,
          'Alleen de hoofdbeheerder of iemand met het recht Gebruikers kan de bestuursrol wijzigen.'
        );
        maakAlleenLezen(
          accountSelect,
          'Alleen de hoofdbeheerder of iemand met het recht Gebruikers kan een inlogaccount koppelen of ontkoppelen.'
        );
      })
      .catch(function () {
        // Bij een onverwachte fout niet terugvallen naar bewerkbaar. De server
        // blijft de bron van waarheid; voor de UX is read-only de veilige kant.
        maakAlleenLezen(
          rolSelect,
          'Deze bestuursrol kan met dit account niet worden gewijzigd.'
        );
        maakAlleenLezen(
          accountSelect,
          'Deze accountkoppeling kan met dit account niet worden gewijzigd.'
        );
      });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initLedenAutorisatieUx);
  } else {
    initLedenAutorisatieUx();
  }
})();
