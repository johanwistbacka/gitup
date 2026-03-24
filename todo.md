# GitUp TODO

## Nästa fokus

- [ ] Lägg till åtminstone ett integrations-/acceptanstest i WordPress-miljö.
  Verifiera att:
  ett plugin med GitHub `UpdateURI` får en uppdatering,
  en vald tagg kan installeras,
  och att aktivt plugin inte tappar korrekt destinationsmapp.

- [ ] Lägg till tester för manuella installflöden hela vägen.
  Förberedelselogiken för plugin och tema testas redan i regressionssviten, inklusive saknad komponent, ogiltig tagg, korrekt paket-URL och känsliga source-selection-hooks.
  Det som återstår är själva fulla admin-post-/redirect-/upgraderkedjan.

- [ ] Utöka testerna för releasehämtning och cache där det fortfarande finns luckor.
  Sviten täcker redan `401`, `403`/`rate_limit`, tags-fallback, malformed latest-svar, cache av tomsvar efter HTTP-fel samt prerelease-läget med draft-filtrering och limit-hantering.

- [ ] Kör en sista genomgång av backward compatibility mot den WordPress-version ni siktar på.
  Kontrollera särskilt upgrader-hooks, admin-UI och att GitUps Site Health-tester faktiskt syns tydligt under `Tools -> Site Health -> Status`.

## Senare förbättringar
- [ ] Lägg till stöd för installation från GitHub-URL.
  Gör det möjligt att installera plugin eller tema direkt från en GitHub-URL, med server-side validering av repo, korrekt pakethämtning och tydlig hantering av måltyp.

- [ ] Begränsa GitHub-anrop till explicita uppdateringsflöden.
  Utforska att bara göra GitHub-anrop vid manuell uppdatering i GitUp eller när WordPress självt kör sina ordinarie update checks, i stället för att belasta vanliga adminvyer i onödan.

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
