# GitUp
> **Version:** 2026.03.24.01
 Hanterar automatiska uppdateringar för GitHub-hostade plugins och teman.

# GitUp

En WordPress-plugin som hanterar **automatiska uppdateringar** för plugins och teman via **GitHub Releases**.

## Funktioner

- 🔄 **Plugin-uppdateringar** – Hookar in i WordPress egna uppdateringssystem och jämför installerad version med senaste release-tag.
- 🎨 **Tema-uppdateringar** – Stöd även för teman (visas på *wp-admin/update-core.php*).
- 📦 **Säker installation** – Ser till att zip-filen packas upp i rätt mapp (utan att mappnamnet får med taggen).
- 🪵 **Loggning** – Skriver detaljerad logg till `debug.log` när `WP_DEBUG` är på.
- 🔑 **Privata repos** – Stöd för GitHub Personal Access Token (PAT) så att privata repos kan uppdateras.
- ⚙️ **Inställningssida** – Admin-sida som visar installerade plugins/teman, senaste release och möjlighet att manuellt installera valfri tag.
- 🧪 **Testknapp** – Verifiera att token fungerar direkt i admin.
- ✅ **Förhandsreleaser (valfritt)** – Kan inkludera prereleases (beta/rc) i både UI och automatiska uppdateringar.
- 📧 **E-postvarning** – Skickar mail till admin om token blir ogiltig (max en gång per dygn).
- 🟢 **Statusindikatorer** – Visar senast verifierad tid och när token uppdaterades direkt i admin.

## Installation

1. Klona eller ladda ner detta repo till `wp-content/plugins/gitup`.
2. Aktivera tillägget i WordPress admin.
3. Navigera till **Verktyg → GitHub-uppdateringar** för att konfigurera.

## Skärmdumpar

1. Inställningssidan – visar installerade plugins/teman, versioner och dropdown för releaser.  
   ![Inställningssidan](assets/images/screenshots/settings.png)

2. Uppdateringsfliken – hur pluginet visas under **wp-admin/update-core.php** med release notes.  
   ![Update-core](assets/images/screenshots/update-core.png)

3. Pluginlistan – ikon, version, "Visa uppgifter"-länk och tokenvarning.  
   ![Pluginlistan](assets/images/screenshots/plugin-list.png)

## Konfiguration

| Inställning | Beskrivning |
|-------------|-------------|
| **GitHub Token** | (Valfritt) Personal Access Token för att hämta privata repos eller öka API-limit. |
| **Tillåt förhandsreleaser** | När ibockad inkluderas även prereleases i jämförelse och UI. |

> **Tips:** Skapa en token via [GitHub → Settings → Developer settings → Personal access tokens](https://github.com/settings/tokens). Scope `repo` krävs för privata repos, annars räcker en token utan extra scope.

> **Notis:** Om token blir ogiltig visas en röd varning i admin och ett mail skickas till sidans admin-adress. Varningen döljs automatiskt efter att en ny token sparats, tills den verifierats via GitHub.

## Så funkar det

- **När WordPress kollar efter uppdateringar** (via admin eller WP-Cron) hämtar tillägget senaste release-tag från GitHub för varje plugin/tema med en giltig `UpdateURI`.
- Resultatet cache:as (1h vid lyckad tag, 5min vid fel/N/A) så att sidan inte blir långsam.
- Om en nyare version hittas skickas den in i WordPress egna uppdaterings-API, så att den syns på *wp-admin/update-core.php*.

## Viktiga beslut

### GitHub-URL:er som stöds

- GitUp normaliserar repo-URL:er till full GitHub-URL utan trailing slash.
- Giltiga repos måste peka på riktiga GitHub-hosts, i praktiken `github.com` eller `www.github.com` för repo-URL:er.
- Paket hämtas via `codeload.github.com` och API-anrop går via `api.github.com`.
- Releasetaggar URL-encodas alltid vid pakethämtning, så även taggar med t.ex. `/` eller mellanslag fungerar.

### Releases och prereleases

- Standardläge använder stabil release-logik och exkluderar prereleases.
- Om `Tillåt förhandsreleaser` är aktiverat används samma prerelease-beteende i både UI och auto-uppdateringar.
- Om vanliga releases saknas faller GitUp tillbaka till Git tags.
- Versionsjämförelser normaliserar ledande `v`, så `v1.2.3` och `1.2.3` behandlas som samma version.

### Manuella installationer

- Manuella plugin- och temainstallationer litar inte på postade `repo`-värden från formuläret.
- GitUp slår i stället upp repo från installerat plugin/tema på serversidan och verifierar att vald tagg faktiskt finns i release-listan innan installation startar.
- Pluginuppdateringar installeras tillbaka till samma pluginmapp.
- Temauppdateringar följer WordPress theme-upgrader-flöde och ser till att temarot, destination och stylesheet hålls stabila även när aktivt tema uppdateras.

### Token och privata repos

- Publika repos fungerar utan token, men privata repos kräver en giltig GitHub-token.
- Tokenstatus normaliseras till samma tillstånd i admin, Site Health och release-/repohantering: `missing`, `unknown`, `valid`, `invalid`, `expired`.
- Vid `401` markeras token som ogiltig och GitUp kan visa admin-notice samt skicka varningsmail högst en gång per dygn.
- Vid `403` behandlas det i första hand som rate limiting och visas separat från vanliga "inga releaser"-fall.

### Loggning och testning

- Debug-loggning är avsiktligt koncentrerad kring HTTP, source selection, package options och upgrader-resultat.
- En lättviktig regressionssvit finns i `tests/` och kan köras med `php tests/run.php`.
- Testsviten täcker bland annat versionslogik, URL-byggande, release/cache, Site Health-status och känsliga theme-upgrader-hooks.

## Manuell installation av release

På inställningssidan visas en tabell med:
- Plugin/tema
- Installerad version
- Senaste release (hämtad från GitHub)
- Dropdown för att välja tag
- **Installera-knapp** för att köra WP Upgrader direkt

## Loggning & Felsökning

När `WP_DEBUG` är på loggar tillägget:
- Vilka API-anrop som görs
- Vilka headers som skickas
- Hittade versioner och jämförelser
- Eventuella fel vid hämtning eller uppackning

Loggen hittar du i `wp-content/debug.log`.

## Utveckling

- Kod följer PSR-4 och är kraftigt kommenterad för att underlätta felsökning.
- Updateringslogiken finns i `gitup-updater.php`.
- Admin/UI i `options.php`.

## Kända Begränsningar

- Kräver att plugins/teman deklarerar `UpdateURI` i plugin-headern eller `ThemeURI` i `style.css`.
- Endast GitHub-stöd (ej GitLab, Bitbucket etc).
- Tar alltid första icke-draft release (eller prerelease om tillåtet), inte "latest commit" på branch.

## Nästa steg

- Bekräfta manuell nedladdning för repo med specialtecken i taggnamn.
- Lägg till tydligare varning vid nedgradering i UI.
- Lägg till stöd för installation av plugin/tema direkt från GitHub-URL.
- Lägg till fler tester för admin-post-flöden och integrationsfall i riktig WordPress-miljö.
- Överväg att flytta vissa API-anrop till cron eller AJAX om admin-sidan blir tung i större installationer.

## Changelog

### 2026.03.24.01
- Skarp release efter verifierad WordPress-runda för publikt pluginrepo, privat pluginrepo, publikt tema och taggar med specialtecken.
- Förbättrad UI för releaseval med färgkodade knappar och extra bekräftelse vid nedgradering.
- Utökad regressionssvit för manuella installförberedelser, update checks, info-popups, HTTP-hooks och plugin-/theme-upgrader-hooks.

### 2026.03.23.02-beta
- Förbättrad dokumentation i README kring GitHub-URL:er, prereleases, manuella installationer, tokenstatus och teststrategi.
- Gjort TODO-listan mer överskådlig genom att flytta avklarade delar till en kort historik och lyfta fram kvarvarande fokusområden.
- Utökat regressionssviten med tester för admin-status, Site Health och fler release-/cache-scenarier.

### 2026.03.23.01
- Stabiliserat plugin- och temauppdateringar mot GitHub med säkrare repo-/URL-hantering och server-side releasevalidering.
- Fixat regressionsbuggar vid uppdatering av aktivt tema, inklusive korrekt temarot, destination och stylesheet-hantering i upgraderflödet.
- Lagt till en körbar regressionssvit för versionslogik, releasehämtning/cache och känsliga theme-upgrader-hooks.
- Renodlat Site Health-logiken och samlat debug-loggning runt tydligare hjälpare.

### 2026.03.16.01
- Bytt pluginidentitet fullt ut till GitUp, inklusive pluginmapp, textdomän och interna `gitup_*`-identifierare.
- Rensat bort kvarvarande `rg*`-rester i kod, språkfiler och dokumentation.
- Förenklat uppdateringskärnan genom att ta bort legacy-alias och dubbla wrapper-funktioner.


### 2025.09.18.01
- Full release (ej beta).
- Förbättrad UI på inställningssidan: verktygsknappar grupperade under en egen sektion för bättre översikt.
- Debug-logik uppdaterad: färre API-anrop, extra loggning för tokenstatus och fel vid hämtning.
- Förbättrad logik för privata/publika repos: visar korrekt "Private repo / 404" endast för privata när token saknas eller är ogiltig, publika repos fungerar som förväntat.
- Alla inline-strängar (PHP och JavaScript) översatta och wrappade för översättningsstöd.

### 2025.09.17.01-beta
- Added support for theme releases in options page (now shows dropdown, latest release, and release notes like plugins).
- Unified release notes handling (consistent across plugins, themes, update-core).
- Improved button labeling in options page (now shows Update, Downgrade, Reinstall depending on selection).
- Added CSS classes (`update`, `downgrade`, `reinstall`) to buttons and rows for easier styling.
- Added left border highlight on rows based on update type.
- Improved details row layout: summary and release notes now in two columns.
- Fixed handling of missing releases: shows clearer messages.
- Various JS improvements for version dropdowns (logs, comparisons, live label updates).

### 2025.09.16.02-beta
- Added cache clearing button in admin to manually flush GitHub API cache (fully implemented and tested).

### 2025.09.16.01-beta
- Förbättringar av UI för options-sidan (ikon, tabbar, mobilvänlighet).
- Mindre justeringar av tabellutseende (randiga rader, highlight för uppdateringar).
- Lagt till debug-läge (kan stänga av loggning via admin).
- Flyttat CSS och JS till externa filer i assets/.
- Token-expiration hanteras och visas i admin.
- Optimized SCSS (variables, striped rows, has-update styling).
- Improved table layout (better responsiveness, icons for latest/installed versions).
- UI polish: integrated SVG icon into options page header.

### 2025.09.15.02-beta
- Ny Site Health-integration: visar tokenstatus och varningar om token inte verifierats på 30 dagar.
- Datumformat för token-verifiering lokaliserat med WordPress `date_i18n()`.
- Lagt till debug-mode-inställning för att slå av/på loggning från admin.
- Ikon tillagd för tillägget (både i pluginlistan och adminmenyn).
- Settings-länk nu synlig på pluginsidan för snabb åtkomst.

### 2025.09.15.01-beta
- E-postmeddelandet vid ogiltig token inkluderar nu sidans titel för tydligare kontext.
- Lagt till klickbar länk i e-postmeddelandet som leder direkt till Tools → GitHub Updates.

### 2025.09.12.04-beta
- Förberett språkstöd: lagt till `load_plugin_textdomain()` och fallback för att säkerställa att översättningar laddas korrekt.
- Alla UI-strängar på options-sidan är wrappade med textdomänen `gitup`.
- Lagt till extra loggning i plugin-uppdateringslogiken: visar när UpdateURI saknas, inte är GitHub eller ingen release hittas.
- Normaliserad versionsjämförelse även för plugins (tar bort `v` vid jämförelse).
- Mindre UI-fix: justerat tabellutseende för bättre responsivitet på små skärmar.

### 2025.09.12.03-beta
- Flyttade inställningssidan till **Verktyg → GitHub-uppdateringar** för mer logisk placering.
- Förbättrad logik för tema-uppdateringar: stöd för både `UpdateURI` och `ThemeURI`, samt normalisering av versionstaggar (tar bort `v`).
- Visar nu "senast verifierad" och "token uppdaterades" under tokenfältet.
- E-postnotis till admin när token blir ogiltig (max en gång per dygn).
- Röd admin-notis med länk när token ogiltig, döljs efter att ny token sparats tills verifiering.
- Utförligare kommentarer i koden och förbättrad README.

## Licens

MIT – se [LICENSE](LICENSE)

## Release Guide

För att göra en release, följ dessa steg:

1. Uppdatera versionsnumret i pluginets huvudfil (`gitup-updater.php`) och i `README.md` under versionstaggen.
2. Commit:a ändringarna med ett beskrivande meddelande, t.ex. "Bump version to x.y.z".
3. Skapa en Git-tag med versionsnumret, t.ex. `git tag -a x.y.z -m "Release x.y.z"`.
4. Pusha både commit och tag till GitHub: `git push origin main --tags`.
5. Gå till GitHub och skapa en ny release baserad på taggen, fyll i release notes.
6. Verifiera att releasen syns korrekt i pluginets inställningssida och att uppdateringar fungerar som förväntat.
7. Testa eventuella ändringar i UI och funktionalitet, samt kontrollera loggar vid behov.
