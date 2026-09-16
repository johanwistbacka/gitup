# GitUp Plugin Readiness Report 001

**Version:** 2026.09.15.01-rc  
**Commit:** RC-kandidat; exakt commit och checksumma dokumenteras i releaseunderlaget  
**Testmiljö:** Isolerad lokal `https://dev.local`; macOS Darwin 25.6.0; WordPress 7.1; single-site; WP-CLI med MAMP PHP 8.4.17; MySQL 8.0.44; `WP_DEBUG=true`. Webbserverns PHP-version: **ej verifierat**. WordPress rapporterar `WP_ENVIRONMENT_TYPE=production` trots lokal `dev.local`-miljö.

**Status:** GRÖN FÖR RC / GUL FÖR STABIL RELEASE

**Critical:** 0 öppna (baseline 0)  
**High:** 0 öppna (baseline 3, åtgärdade i arbetskopian)  
**Medium:** 0 öppna säkerhetsfynd (baseline 2, åtgärdade i arbetskopian)  
**Low:** 0 öppna säkerhetsfynd (baseline 3, åtgärdade i arbetskopian)

Det tidigare Medium-fyndet i failure handling har åtgärdats och täcks av riktade tester. Två dokumentations- och releaseförbättringar ligger i backlog.

## Kvar före stabil release

1. PHPCS med WordPress Coding Standards och PHPCompatibilityWP har inte körts eftersom verktygen inte finns installerade. Resultat: **ej verifierat**.
2. PHPStan eller Psalm med WordPress-stubbar har inte körts. Resultat: **ej verifierat**.
3. Fullvärdig secret scanning av git-historik och arbetskopia med exempelvis Gitleaks eller TruffleHog har inte körts. Den lokala mönsterbaserade kontrollen gav inga träffar.
4. Autentiserat end-to-end-flöde mot ett särskilt GitHub-testkonto och ett privat testrepo har inte körts. Saknad/felaktig token, timeout och API-fel är verifierade med mocks. Riktiga credentials användes inte.
5. GitUps egen uppgradering från en färdig release-ZIP har inte körts mot denna arbetskopia eftersom det skulle skriva över det pågående git-repot. Installation, uppgradering och rollback har i stället verifierats med ett separat fixture-plugin.

## Struktur och entry points

- `index.php:1-82` är pluginbootstrap med metadata, översättningar, adminassets och includes.
- `gitup-updater.php` innehåller GitHub-klient, versionskontroll, uppdateringstransienter och WordPress Upgrader-filter.
- `options.php` innehåller adminvyn, settings, tokenhantering, manuella plugin-/temauppdateringar och cacheåtgärder.
- `install-from-url.php` inspekterar GitHub-repon och installerar plugin eller tema efter bekräftelse.
- `update-hooks.php` lägger till metadata- och informationslänkar i WordPress admin.
- `site-health.php` exponerar GitUp-status i Site Health.
- `release-prefetch.php` innehåller cache- och repo-inventeringshelpers.
- `uninstall.php:1-41` rensar GitUp-options och tokenstatus, även per site i multisite.
- `tests/run.php:5-8` är CLI-only och svarar 404 via webbläsare/CGI.

## Attackyta

Verifierad attackyta:

- En adminskärm under Tools.
- Åtta `admin_post_*`-flöden: tokentest, vald pluginrelease, vald temarelease, cachetömning, refresh samt install-from-URL preview/confirm/reset (preview, confirm och reset är tre separata actions).
- En autentiserad AJAX-endpoint: `wp_ajax_gitup_test_github_token`.
- Uppdateringsfilter för `update_plugins`, `update_themes`, `plugins_api`, `themes_api` och WordPress Upgrader.
- Utgående HTTPS till `api.github.com`; releasepaket hämtas från `codeload.github.com`. Användarinmatade repo-URL:er normaliseras till `github.com/{owner}/{repo}`.
- Filsystemskrivning sker i WordPress tempkatalog och via `Plugin_Upgrader`/`Theme_Upgrader` till validerade plugin- eller temadestinationer.
- Options och transients i WordPress-databasen lagrar token, tokenstatus, inställningar, releasecache och kortlivad install-preview.

Ingen `register_rest_route`, `wp_ajax_nopriv_*`, `admin_post_nopriv_*`, shortcode eller publik frontend-endpoint hittades.

## Security findings

Följande åtta fynd stöddes av baselinekoden. Samtliga är åtgärdade i den aktuella arbetskopian.

### H-01: Root-level plugin kunde ge destruktiv destinationsmapp

- **Typ:** Säkerhetsproblem.
- **Severity:** High.
- **Fil och relevant baselinekod:** `gitup-updater.php:1055-1069`: `$expected_dir = dirname($plugin_basename)` följt av `destination = WP_PLUGIN_DIR . $expected_dir` och `clear_destination = true`.
- **Faktisk risk:** För ett single-file-plugin i pluginroten blir `dirname('plugin.php')` värdet `.`. Uppgraderaren kunde då få WordPress pluginrot som destination och samtidigt instrueras att rensa destinationen. En administratörsutlöst uppgradering kunde därmed påverka andra plugins.
- **Åtgärd:** `gitup_get_plugin_directory()` i `gitup-updater.php:250` avvisar root-level, absoluta och traversal-liknande basenames. Uppdateringskontroll, manuell installation och package options avbryter när säker komponentmapp saknas. Regressionstester finns i `tests/GitupSecurityRemediationTest.php`.
- **Status:** Åtgärdad i arbetskopian.

### H-02: Token kunde skickas till för breda GitHub-URL:er

- **Typ:** Säkerhetsproblem/credential exposure.
- **Severity:** High.
- **Fil och relevant baselinekod:** `gitup-updater.php:710-740` lade globalt `Authorization: Bearer` på alla WordPress-anrop till flera GitHub-relaterade hosts. `gitup-updater.php:831-859` tog över paket när URL-strängen bara innehöll `codeload.github.com` eller `api.github.com/repos` och tillät fem redirects.
- **Faktisk risk:** Ett annat plugin eller en manipulerad paket-URL kunde få GitUps sparade PAT bifogad. Substring-matchning och redirects gjorde mottagaren bredare än GitUps egna, verifierade paket.
- **Åtgärd:** Den globala tokeninjektionen är borttagen. `gitup_classify_github_request_url()` i `gitup-updater.php:96`, exakt paketregistrering i `:147`, matchning mot aktuell WordPress-update i `:185` och authorization i `:233` begränsar hanteringen. Paketnedladdning använder `redirection => 0` i `:999`, och befintliga `pre_download`-svar lämnas orörda.
- **Status:** Åtgärdad i arbetskopian och verifierad med negativa URL-, redirect-, ownership- och pre-download-tester.

### H-03: Användare med `update_core` kunde få tokenvärdet renderat

- **Typ:** Säkerhetsproblem/permissions och credential exposure.
- **Severity:** High.
- **Fil och relevant baselinekod:** `options.php:444-452` gav adminsidan capability `update_core`; `options.php:1027-1034` läste och skrev ut hela sparade tokenvärdet i lösenordsfältet.
- **Faktisk risk:** En roll som kunde öppna GitUp-sidan men saknade `manage_options` kunde få PAT-värdet i HTML-svaret. Ett lösenordsfält maskerar bara presentationen, inte DOM-värdet.
- **Åtgärd:** Settingsfliken och tokenfältet kräver `manage_options` (`options.php:558`, `:984`, `:1033`). Det lagrade värdet renderas inte. Tom inskickad token bevarar befintligt värde och explicit clear krävs.
- **Status:** Åtgärdad i arbetskopian.

### M-01: `ThemeURI` fungerade som implicit update source

- **Typ:** Säkerhetsproblem/supply-chain boundary.
- **Severity:** Medium.
- **Fil och relevant baselinekod:** `gitup-updater.php:156` föll tillbaka till `$theme->get('ThemeURI')`; `install-from-url.php:714-717` accepterade samma fallback.
- **Faktisk risk:** Ett temas allmänna homepage-metadata kunde oavsiktligt aktivera GitUps updateflöde. Temat behövde inte uttryckligen deklarera att GitUp/GitHub var dess uppdateringskälla.
- **Åtgärd:** Endast explicit `UpdateURI` accepteras för plugin och teman; `install-from-url.php:716` dokumenterar samma krav.
- **Status:** Åtgärdad i arbetskopian.

### M-02: Global HTTP-loggning omfattade andra komponenters anrop

- **Typ:** Säkerhetsproblem/informationsläckage.
- **Severity:** Medium.
- **Fil och relevant baselinekod:** `gitup-updater.php:710-740` körde för varje WordPress HTTP-request och loggade URL samt headers. Authorization maskerades, men andra query-parametrar och headers tillhörande andra komponenter kunde fortfarande loggas.
- **Faktisk risk:** Med `WP_DEBUG` kunde tokens, signerade URL-parametrar eller annan känslig metadata från orelaterade plugins hamna i loggfil.
- **Åtgärd:** GitUps egna anrop märks med `gitup_request`; `http_response` i `gitup-updater.php:949` ignorerar allt annat. Råa headers, bodies och fulla paket-URL:er loggas inte längre.
- **Status:** Åtgärdad i arbetskopian.

### L-01: State-changing refresh/reset använde nonce-fri GET

- **Typ:** Säkerhetsproblem/CSRF-hardening.
- **Severity:** Low.
- **Fil och relevant baselinekod:** `options.php:548-590` använde `?gitup_refresh=1`; `install-from-url.php:963-978` raderade preview-transient via `?reset=1`.
- **Faktisk risk:** En inloggad behörig användare kunde förmås att utlösa cachebypass eller transient-radering via en länk från annan sida.
- **Åtgärd:** Båda flödena använder POST, capabilitykontroll och nonce (`options.php:1439-1445`, `install-from-url.php:946-954`).
- **Status:** Åtgärdad i arbetskopian.

### L-02: Token och tokenmetadata rensades inte vid uninstall

- **Typ:** Säkerhetsproblem/secret lifecycle.
- **Severity:** Low.
- **Fil och relevant baselinekod:** Baseline saknade `uninstall.php` och annan uninstall-hook.
- **Faktisk risk:** PAT och verifieringsmetadata låg kvar i options efter att pluginet avinstallerats, inklusive i multisite-bloggar.
- **Åtgärd:** `uninstall.php:1-41` rensar en uttrycklig allowlist av options och transienter per site och återställer blog context.
- **Status:** Åtgärdad i arbetskopian; single-site och simulerat multisiteflöde verifierade.

### L-03: Test- och utvecklingsfiler kunde följa med i releasepaketet

- **Typ:** Releasehygien/kodkvalitet med mindre attackyteffekt.
- **Severity:** Low.
- **Fil och relevant baselinekod:** Baseline `.gitattributes` saknade `export-ignore` för tester, utvecklingsanteckningar, SCSS och lokala arbetsfiler. `tests/run.php` saknade web guard och tests skapade tempinnehåll utan garanterad teardown.
- **Faktisk risk:** Körbara testfiler och utvecklingsartefakter kunde exponeras i installerade plugins och lämna temporära filer efter testfel.
- **Åtgärd:** `.gitattributes:3-14` exkluderar dessa artefakter. `tests/run.php:5-8` är CLI-only och teardown har lagts till i testsviten.
- **Status:** Åtgärdad i arbetskopian; `git archive --worktree-attributes` och CGI-404 har verifierats.

## Permissions

| Flöde | Capability | CSRF-skydd | Bedömning |
|---|---|---|---|
| Adminsida/uppdateringslista | `update_core` | Läsning | Verifierat |
| Settings och token | `manage_options` | Settings API nonce | Verifierat |
| Synkront/AJAX tokentest | `manage_options` | `check_admin_referer` / `check_ajax_referer` | Verifierat |
| Vald pluginrelease | `update_plugins` | Objektbundet nonce | Verifierat |
| Vald temarelease | `update_themes` | Objektbundet nonce | Verifierat |
| Install from URL | `install_plugins` eller `install_themes` efter typ | Preview- och confirm-nonce | Verifierat |
| Cache clear | `manage_options` | Nonce | Verifierat |
| Refresh | `update_core` | Nonce | Verifierat |

Inga nopriv-handlers hittades. Capability- och noncefel testas främst i den fristående testsuiten; full webbaserad rollmatris i riktig WordPress-admin är **ej verifierat** eftersom ingen inloggning eller riktiga kontocredentials användes.

## Secrets/Credentials

- En GitHub-token lagras i WordPress option `gitup_github_token`. Tokenen finns i den lokala testdatabasen, men dess värde har aldrig lästs ut, loggats, ändrats eller skickats under granskningen.
- Token renderas inte tillbaka i adminfältet och blank submit ersätter inte befintligt secret.
- Utgående Authorization-header skapas endast för uttryckliga GitUp-anrop till exakt validerade GitHub API- eller paket-URL:er.
- Repository scan med riktade secretmönster gav inga träffar. Full historikscan med dedikerat verktyg: **ej verifierat**.
- Token lagras fortfarande som vanlig WordPress option och skyddas därmed av databasens och WordPress-installationens åtkomstkontroller. Stöd för constant/environment-baserad credential kan övervägas för driftmiljöer med extern secret management.

## REST/AJAX/API

- REST: inga routes hittades.
- AJAX: endast `wp_ajax_gitup_test_github_token` (`options.php:1204-1239`), med `manage_options`, nonce och JSON-svar.
- Admin POST: alla verifierade muterande flöden har capability- och nonce-kontroll.
- API-hostar: `api.github.com` för metadata och tokentest; `codeload.github.com` för exakt validerade release-ZIP:ar.
- URL-valideringen avvisar fel schema, userinfo, otillåtna portar, lookalike-hostar, fel paketpath, tarball-varianter och kodade/avkodade dot-segment.

## Input validation, sanitization och output escaping

- Requestfält går genom `wp_unslash()` och typanpassad sanitization (`sanitize_key` eller `sanitize_text_field`).
- GitHub repo-URL normaliseras till HTTPS och exakt host/path innan API- eller paket-URL byggs.
- Taggar verifieras mot hämtad release/taglista innan manuell installation.
- Admin-URL:er, attribut, text och changelog använder `esc_url`, `esc_attr`, `esc_html` respektive `wp_kses_post` där granskade sinks kräver det.
- Inget verifierat stored/reflected XSS-, SQL injection- eller command injection-flöde hittades.

## Databas och filhantering

- Inga egna tabeller skapas.
- Data skrivs till WordPress options och transients. Direkt SQL används endast för cachemönster och parametreras med `$wpdb->prepare()`.
- ZIP-hämtning streamas till `wp_tempnam()`, kräver HTTP 200 och ZIP-signaturen `PK`; felaktig fil tas bort.
- Paketdestinationer binds till validerad pluginmapp eller theme stylesheet. Root-level och traversal-liknande pluginbasenames avvisas.
- Filoperationer finns i source selection och WordPress Upgrader-flöden. Inget godtyckligt användarstyrt lokalt sökvägsflöde verifierades.

## Dependencies

- Runtime: WordPress core, PHP och WordPress HTTP/Filesystem/Upgrader API.
- Frontend/admin-JS: WordPress-bundlad jQuery.
- Inga Composer- eller npm-manifest/lockfiler finns och inga vendorerade PHP-paket hittades.
- WordPress/PHP-minimum deklareras inte i pluginheadern. Kompatibilitetsmatris och formella minimiversioner är **ej verifierat** och bör dokumenteras.
- "Missing dependency" är inte tillämpligt på nuvarande kod eftersom pluginet saknar deklarerade externa pluginberoenden.

## Update/Release

- Pluginheaderns version och baseline-taggen matchar: `2026.06.22.01-beta`.
- Uppdateringar upptäcks endast via explicit GitHub `Update URI`.
- Paket binds till den release/tag som GitUp verifierat eller till exakt aktuell WordPress update-transient.
- Release-ZIP-validering ska använda `.gitattributes` exportregler och säkerställa att tester, `.claude`, SCSS och utvecklingsanteckningar saknas.
- Arbetskopian är modifierad och ocommittad. Inget tagg-, push- eller releasearbete har utförts.
- Den isolerade reaktiveringspatchen mot den tidigare åtgärdade arbetskopian bedöms som **Medium risk**: påverkan måttlig, regressionsrisk låg–måttlig, testskydd starkt, återställning enkel och confidence high. Disposition: `human_review_required`, eftersom kodvägen installerar och återaktiverar pluginfiler.
- Den avgränsade diffen omfattar `options.php`, `tests/bootstrap.php` och `tests/GitupPluginReactivationTest.php`. Reviewartefakt: `docs/gitup-reactivation-fix.patch`, SHA-256 `db1a8f61bcd421877bd5f1d2c3c492332765af138a125af8796b3451063600d3`; bas: commit `48f6bf4ec1b25011b611b37a0aaf79e5f6ac1bf8` plus tidigare remediationsändringar i arbetskopian.

## Rollback

- Manuell rollback verifierades lokalt genom installation av fixture `1.0.0`, uppgradering till `2.0.0` och återinstallation av `1.0.0`; aktiv status bevarades.
- GitUps UI kan välja en tidigare verifierad release-tag, vilket ger ett manuellt rollbackflöde.
- Fel före filbyte, exempelvis timeout, HTTP-fel eller ogiltig ZIP, stoppar flödet och lämnar installerad version orörd i de granskade vägarna.
- Automatisk återställning efter avbrott mitt under filkopiering är **ej verifierat**.
- **Åtgärdad tillförlitlighetsobservation (tidigare Medium):** `options.php:1256-1284` fångar nu både `WP_Error` från `activate_plugin()` och fallet där anropet returnerar utan fel men pluginet förblir inaktivt. Adminflödet rapporterar då installationen som ett partiellt fel i stället för lyckad reaktivering. `tests/GitupPluginReactivationTest.php:9-63` täcker inaktivt utgångsläge, redan aktivt plugin, lyckad reaktivering och båda fellägena. Automatisk rollback efter ett sådant reaktiveringsfel erbjuds fortfarande inte; detta är dokumenterad failure handling/kodkvalitet, inte ett verifierat säkerhetsproblem.

## Testability

Verifierat:

- 141/141 fristående PHP-tester passerar med MAMP PHP 8.4.17.
- Fem riktade tester verifierar att tidigare aktiva plugins återaktiveras och att båda reaktiveringsfelen rapporteras.
- Samma suite har passerat med `WP_DEBUG` aktiverat.
- PHP syntax check passerar för samtliga 24 PHP-filer med MAMP PHP 8.4.17.
- Riktig lokal WordPress: `wp-mamp db check` passerar alla 12 WordPress-tabeller. GitUp avaktiverades, aktiverades och bekräftades därefter aktivt med `wp-mamp plugin is-active gitup`.
- Codex normala sandbox blockerar nätanslutning till värddatorns `127.0.0.1:3306`; samma WP-CLI-kontroller passerar med temporär utökad lokal åtkomst. Det är sandlådenätverksisolering, inte projektets DB- eller MAMP-konfiguration. Inga konfigurationsändringar gjordes.
- Frontend laddades efter återaktivering utan browser-console errors.
- WordPress-integrerade mocks passerade för saknad token, ogiltig token/401, timeout, HTTP 500 och ogiltig ZIP; noll riktiga HTTP-anrop gjordes.
- Fixture-plugin: installation, aktivering, upgrade `1.0.0 -> 2.0.0`, rollback `2.0.0 -> 1.0.0`, avaktivering och borttagning passerade.
- WordPress avvisade en trasig ZIP med `PCLZIP_ERR_BAD_FORMAT`.
- Test-fixtur och pluginstatus återställdes efter test.

Ej verifierat:

- Riktigt privat GitHub-testrepo med dedikerad testtoken.
- GitUps egen end-to-end-uppgradering från byggd release-ZIP.
- Automatisk rollback vid simulerat filsystemfel mitt under replacement.
- Multisite i riktig WordPress-instans; uninstallflödet har endast körts i simulerad multisite.
- Rollmatris i webbaserad admin.
- Flera PHP-/WordPress-versioner och Windows/Linux.

## CRA/Vulnerability handling

Repo:t saknar `SECURITY.md`, säker rapporteringskanal, supported-version policy, severitymodell, triage-SLA, coordinated disclosure-process, incidentrunbook, supportperiod, SBOM och dokumenterad användarkommunikation vid sårbarhet. Juridisk klassificering av Studio Wistbacka/GitUp som manufacturer, open-source steward eller utanför relevant CRA-roll är **ej verifierat** och kräver separat juridisk bedömning.

Per 14 september 2026 gäller CRA:s rapporteringsplikt för manufacturers sedan 11 september 2026. EU-kommissionens officiella information anger early warning inom 24 timmar och full notification inom 72 timmar för aktivt exploaterade sårbarheter och allvarliga säkerhetsincidenter; rapportering sker via CRA Single Reporting Platform. Huvudkraven börjar tillämpas 11 december 2027. Se [EU-kommissionens rapporteringssida](https://digital-strategy.ec.europa.eu/en/policies/cra-reporting), [CRA-översikten](https://digital-strategy.ec.europa.eu/en/policies/cyber-resilience-act) och [förordning (EU) 2024/2847, artikel 14](https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX%3A32024R2847).

Dokumentera minst:

- säker kontaktkanal och mottagningsbekräftelse;
- scope, supported versions och supportperiod;
- intern owner, triage, severity, beslut och tidslinje;
- process för aktiv exploatering, incidenteskalering och CRA/SRP-bedömning;
- fix, regressionstest, advisory/CVE-beslut och användarnotifiering;
- komponent- och dependencyinventering/SBOM samt buildproveniens;
- bevarande av evidens och post-incident review.

## Recommended automated security gate

Föreslagen blockerande gate i denna ordning:

1. Kontrollera ren diff och genomför dokumenterad maintainersjälvgranskning. För en RC räcker den när samma person äger pluginet; ta in extern security review inför stabil release när riskbedömningen kräver det.
2. Kör `php -l` på alla release-PHP-filer.
3. Kör PHPCS med WordPress Coding Standards och PHPCompatibilityWP mot deklarerad PHP-baseline.
4. Kör PHPStan eller Psalm med WordPress-stubbar och ett versionslåst regelverk.
5. Kör Gitleaks/TruffleHog på commitdiff, arbetskopia och relevant git-historik.
6. Kör dependency audit när Composer/npm-lockfiler finns; generera SBOM även när externa paket saknas så att WordPress/PHP/runtimekrav framgår.
7. Kör de 141 fristående testerna, inklusive HTTP-, nonce-, kontext-, uninstall-, fail-closed- och reaktiveringsfall.
8. Starta en engångs-WordPressinstans och testa install, activation, deactivation, missing/invalid credentials, mocked timeout/401/403/500 och invalid ZIP.
9. Kör fixturebaserat upgrade- och rollbacktest och kontrollera aktiv status före/efter.
10. Bygg ZIP från en ren, reviewad commit med `git archive --worktree-attributes`; avvisa `.git`, `.claude`, tester, credentials, SCSS, devanteckningar, absoluta paths, `../`, symlänkar och oväntade executables.
11. Kontrollera att pluginheaderns version, release-tag, ZIP-root och checksums matchar. Spara manifest, SBOM, checksums och CI-proveniens.
12. Kräv ett uttryckligt maintainerbeslut före extern publicering. För GitUps RC är det den enda manuella godkännandepunkten.

## Åtgärdas före stabil release

1. Kör WPCS/PHPCompatibilityWP, PHPStan/Psalm och full secret scanner.
2. Lägg till `SECURITY.md` och en operativ vulnerability/incident-process med ansvarig kontakt och CRA/SRP-escalation.
3. Deklarera och testa minsta WordPress- och PHP-version.
4. Kör GitUps egen upgrade/rollback samt reaktiveringsfel från release-ZIP i en separat temporär WordPressinstans.
5. Testa privat GitHub-repository med verkliga, begränsade credentials.

## Backlog

- Stöd credential via constant/environment/extern secret manager för miljöer som inte vill lagra PAT i options.
- Lägg till riktig multisite-integrationstestning och testmatris för flera WordPress/PHP-versioner.
- Lägg till felinjektion under filkopiering för att verifiera automatisk rollback.
- Lägg till SBOM, checksums och signerad/reproducerbar buildproveniens.
- `release-prefetch.php:3-8` beskriver WP-cron-worker och AJAX-endpoint som inte finns i filen. Synka dokumentationen med faktisk implementation eller implementera/testa funktionen. Detta är kodkvalitet, inte säkerhetsproblem.
- Sätt `WP_ENVIRONMENT_TYPE=local` eller `development` i den lokala testmiljön så att testklassificeringen inte är missvisande.

## Riktad granskning: tokenstatus

### P2 — Åtgärdat: oautentiserat repoanrop kunde markera en token som giltig

`gitup_repo_visibility()` gör först ett medvetet oautentiserat anrop mot GitHub för att kontrollera ett repos synlighet. Anropet märks ändå som `gitup_request`. Tidigare accepterade det globala `http_response`-filtret varje API-svar med status 200 och sparade då tokenstatusen som `valid`, utan att kontrollera om just detta anrop bar en `Authorization`-header.

En sparad ogiltig token kunde därför se giltig ut när ett offentligt repo kontrollerades. Flera flöden rättade senare statusen om ett autentiserat anrop svarade 401, men resultatet från den enskilda publika kontrollen var missvisande.

Åtgärdat: tokenstatus uppdateras nu endast när `$args['headers']` innehåller en icke-tom `Authorization`-header, skiftlägesokänsligt. Villkoret gäller både status 200 och 401. Regressionstester täcker autentiserade 200- och 401-svar samt att ett oautentiserat 200-svar inte ändrar tokenstatus.

P2-fyndet är löst i den aktuella arbetskopian. RC-paketet verifieras separat i den här releaserundan. Ett komplett GitUp-styrt uppdateringsflöde från release-ZIP och privat GitHub-testrepo återstår inför stabil release.

## Samlad bedömning

Baseline hade tre High, två Medium och tre Low verifierade fynd. De är åtgärdade i arbetskopian och täckta av riktade tester. Den tidigare isolerade WordPress-verifieringen visade att pluginet kunde aktiveras, avaktiveras och samexistera med ett fungerande install/upgrade/rollbackflöde utan riktiga GitHub-anrop. Första remediationspasset är genomfört. Maintainersjälvgranskning och det verkliga livscykeltestet av avaktivering och återaktivering passerade. RC kan publiceras efter att den lokala releaseartefakten har validerats. Stabil release kräver fortfarande WPCS/PHPCompatibilityWP, PHPStan/Psalm, full secret scanner, GitUps eget uppdateringsflöde från release-ZIP i separat WordPress-instans och test mot privat GitHub-repository.
