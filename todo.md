# GitUp TODO

## Nuvarande fokus

- [x] Bekräfta att repo med specialtecken i taggnamn laddas ner korrekt.
  Exempel: taggar med `/` eller mellanslag ska inte ge 404 vid nedladdning.
  Status: verifierat både i logg och i verkligt WordPress-flöde med tagg innehållande `/`, korrekt URL-encoding i package-URL och lyckad nedladdning från GitHub utan 404.

- [ ] Lägg till tester för manuella installflöden.
  Testa att felaktigt `repo`/`tag` avvisas och att giltiga värden leder till rätt paket-URL och destination.
  Status: förberedelselogiken för plugin och tema testas nu i regressionssviten, inklusive saknad komponent, ogiltig tagg, korrekt paket-URL och känsliga source-selection-hooks. Själva fulla admin-post-/redirect-/upgraderkedjan återstår.

- [ ] Lägg till fler tester för releasehämtning och cache.
  Prioritera fler tom-/fel-scenarier utöver det som redan täcks för `401`, `403`/`rate_limit`, tags-fallback och admin-statusmeddelanden.
  Status: sviten täcker nu även tomt/malformed latest-svar, cache av tomsvar efter HTTP-fel samt prerelease-läget med draft-filtrering och limit-hantering.

- [ ] Lägg till åtminstone ett integrations-/acceptanstest i WordPress-miljö.
  Verifiera att:
  ett plugin med GitHub `UpdateURI` får en uppdatering,
  en vald tagg kan installeras,
  och att aktivt plugin inte tappar korrekt destinationsmapp.

- [ ] Testa manuellt mot minst fyra scenarier:
  publikt pluginrepo, privat pluginrepo, publikt tema och repo med specialtecken i taggnamn.
  Status: publikt pluginrepo, privat pluginrepo, publikt tema och repo med specialtecken i taggnamn verifierade.

- [ ] Bekräfta att uppdatering fungerar både via WordPress update checks och via GitUps manuella installations-UI.
  Status: verifierat för publikt pluginrepo och publikt tema via ordinarie WordPress-flöden och GitUps egen options-sida.

- [ ] Kör en sista genomgång av backward compatibility mot den WordPress-version ni siktar på.
  Kontrollera särskilt upgrader-hooks, Site Health och admin-UI.
  Notering: verifiera också att GitUps Site Health-tester faktiskt syns tydligt under `Tools -> Site Health -> Status`.

- [ ] Kör manuell verifieringsrunda i WordPress-miljö.
  Checklista:
  publikt pluginrepo: verifierat. Uppdatering, ominstallation och nedgradering fungerar, med rätt knappstatus/bekräftelse samt fungerande flöden via `update-core`, tilläggssidan och GitUps egen options-sida.
  privat pluginrepo: verifierat. Token, felmeddelanden, UI-status och Site Health beter sig korrekt.
  publikt tema: verifierat. Manuell uppdatering, ordinarie WordPress-uppdatering och aktivt tema fungerar som tänkt.
  specialtecken i taggnamn: verifierat. Paket hämtas korrekt och installeras utan 404.
  backward compatibility-snabbkoll: update checks, info-popup, admin-notices och Site Health.

## Senare förbättringar
- [ ] Lägg till stöd för installation från GitHub-URL.
  Gör det möjligt att installera plugin eller tema direkt från en GitHub-URL, med server-side validering av repo, korrekt pakethämtning och tydlig hantering av måltyp.


- [ ] Flytta vissa API-anrop till cron eller AJAX för att snabba upp admin-sidor vid större installationer.

- [ ] Stöd för att välja release direkt från `wp-admin/update-core.php`.
  Det avviker från WordPress-standard och är därför inte prioriterat först.

- [ ] Förbättra Details-vyn med README-baserat innehåll.
  Utforska att bygga plugin-/tema-popupen från README-innehåll via kontrollerade sektioner i stället för dagens ganska tunna details-vy. Bör inte vara en rå direktvisning av hela README, utan en cachad och sanerad mappning till WordPress-popupens sektioner.

- [ ] Stöd för GitHub-webhooks för att trigga uppdateringskontroll vid ny release.

- [ ] Lägg till WP-CLI-kommandon, t.ex. `wp gitup check` och `wp gitup update`.

- [ ] Fallback till GitHub commits om inga releasetaggar finns.

- [ ] Möjlighet att uppdatera alla plugins/teman i en batch från options-sidan.

- [ ] Utforska ett utvecklarläge för repo-baserad installation.
  Tanken är ett separat development mode för att installera plugin eller tema direkt från repo-URL, eventuellt senare även via klon-liknande flöde. Kraven är inte färdigtänkta ännu och punkten är därför bara en framtida utvecklingsmöjlighet.

## Nyligen klart

- [x] Lagt till extra varning vid nedgradering.
  Nedgradering markeras nu med röd knapp och extra bekräftelse innan installation för både plugin och teman. Uppdatering visas med grön knapp och ominstallation med neutral grå knapp.
  Status: löst utan extra varningstext i listan, så att UI:t förblir kompakt.

- [x] Dokumenterat de viktigaste besluten i README.
  Stödda GitHub-URL-format, prerelease-beteende, tokenstatus, manuella installationer och teststrategi finns nu beskrivna.

- [x] Renodlat Site Health-logiken.
  Gemensamma hjälpare används nu för tokenstatus, releasecache och Site Health-resultat.

- [x] Rensat debug-loggning och kommentarsnivå.
  Loggningen är nu mer koncentrerad kring HTTP, source selection, package options och upgraderresultat.

- [x] Minskat duplicering mellan plugin- och temaflöden.
  Manuella admin-flöden delar nu hjälpare för prerelease-val, taggverifiering, paket-URL och package-options-filter.

- [x] Stabiliserat uppdateringslogiken för plugin och tema.
  Repo-normalisering, strikt host-kontroll, releasefallback och felhantering för privata repos/rate limiting är genomförda.

- [x] Förbättrat säkerhet och robusthet i manuella actions.
  Server-side validering används för installerad komponent, repo och releasetagg, och notices/redirects är centraliserade.

- [x] Byggt upp en körbar regressionssvit i `tests/`.
  Sviten täcker versionslogik, URL-byggande, release/cache, admin-status, Site Health, HTTP-hooks, update checks/info-popups och känsliga plugin-/theme-upgrader-hooks.

- [x] Verifierat kärnflöden i WordPress.
  Manuell pluginuppdatering, manuell temauppdatering och ordinarie WordPress-uppdatering har testats fungerande.

## Historik

- `75cd31e` `Stabilize updates and add regression tests`
  Versionsbump, dokumentationsuppdateringar, första regressionssviten och stabilisering av uppdateringsflödena.

- `d5559cb` `Release 2026.03.16.01`
  Tidigare releasepunkt innan den större stabiliseringsrundan.
