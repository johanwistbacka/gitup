# GitUp Done

## Senast klart

- Skarp release `2026.03.24.01`.
  WordPress-rundan verifierades för publikt pluginrepo, privat pluginrepo, publikt tema och taggar med specialtecken.

- Nedgraderingsflöde i UI.
  Nedgradering markeras nu med röd knapp och extra bekräftelse innan installation för både plugin och teman.
  Uppdatering visas med grön knapp och ominstallation med neutral grå knapp.

- Dokumentation och struktur.
  README beskriver nu viktiga beslut kring GitHub-URL:er, prereleases, manuella installationer, tokenstatus och teststrategi.
  TODO-strukturen förenklades och fokus ligger nu på kvarvarande arbete.

- Site Health-logik.
  Gemensamma hjälpare används nu för tokenstatus, releasecache och Site Health-resultat.

- Debug-loggning.
  Loggningen är nu mer koncentrerad kring HTTP, source selection, package options och upgraderresultat.

- Minskad duplicering mellan plugin- och temaflöden.
  Manuella admin-flöden delar nu hjälpare för prerelease-val, taggverifiering, paket-URL och package-options-filter.

- Stabiliserad uppdateringslogik för plugin och tema.
  Repo-normalisering, strikt host-kontroll, releasefallback och felhantering för privata repos/rate limiting är genomförda.

- Förbättrad säkerhet och robusthet i manuella actions.
  Server-side validering används för installerad komponent, repo och releasetagg, och notices/redirects är centraliserade.

- Regressionssvit i `tests/`.
  Sviten täcker versionslogik, URL-byggande, release/cache, admin-status, Site Health, HTTP-hooks, update checks/info-popups och känsliga plugin-/theme-upgrader-hooks.

## Verifierat i WordPress

- Publikt pluginrepo.
  Verifierat via `update-core`, tilläggssidan och GitUps egen options-sida, inklusive uppdatering, ominstallation och nedgradering.

- Privat pluginrepo.
  Verifierat för token, felmeddelanden, UI-status och Site Health-beteende.

- Publikt tema.
  Verifierat för manuell uppdatering, ordinarie WordPress-uppdatering och att aktivt tema förblir aktivt.

- Repo med specialtecken i taggnamn.
  Verifierat både i logg och i verkligt WordPress-flöde med tagg innehållande `/`, korrekt URL-encoding i package-URL och lyckad nedladdning från GitHub utan 404.

## Historik

- `6a35409` `Release 2026.03.24.01`
- `86d966b` `Prepare 2026.03.23.02-beta`
- `75cd31e` `Stabilize updates and add regression tests`
- `d5559cb` `Release 2026.03.16.01`
