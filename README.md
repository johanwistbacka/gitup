# rg-git-updater
> **Version:** 2025.09.12.04-beta
 Hanterar automatiska uppdateringar för Ratt Grafiskas plugins via GitHub.

# RG Git Updater

En WordPress-plugin som hanterar **automatiska uppdateringar** för Rätt Grafiskas plugins och teman via **GitHub Releases**.

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

1. Klona eller ladda ner detta repo till `wp-content/plugins/rg-git-updater`.
2. Aktivera tillägget i WordPress admin.
3. Navigera till **Verktyg → GitHub-uppdateringar** för att konfigurera.

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
- Updateringslogiken finns i `rg-git-updater.php`.
- Admin/UI i `options.php`.

## Kända Begränsningar

- Kräver att plugins/teman deklarerar `UpdateURI` i plugin-headern eller `ThemeURI` i `style.css`.
- Endast GitHub-stöd (ej GitLab, Bitbucket etc).
- Tar alltid första icke-draft release (eller prerelease om tillåtet), inte "latest commit" på branch.

## Roadmap / TODO

- [ ] Lägg till full översättningsstöd (textdomän i alla strängar, POT-fil).
- [ ] Generera `.pot` via WP-CLI (`wp i18n make-pot`).
- [ ] Förbättra UI för mobil (gör tabellen mer responsiv och knapparna bättre placerade).
- [ ] Eventuell fallback till GitHub commits om inga releasetaggar finns.
- [ ] Stöd för att välja release direkt från wp-admin/update-core.php.

## Changelog

### 2025.09.12.04-beta
- Förberett språkstöd: lagt till `load_plugin_textdomain()` och fallback för att säkerställa att översättningar laddas korrekt.
- Alla UI-strängar på options-sidan översatta till engelska och wrappade med textdomän `rg-git-updater`.
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