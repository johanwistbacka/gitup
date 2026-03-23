# GitUp Quality TODO

## 1. Saker att fixa direkt

- [x] URL-encoda alltid releasetaggar i alla GitHub/codeload-URL:er.
  Detta gäller auto-uppdateringar, plugin/theme info-popups och alla andra ställen som bygger `.../zip/refs/tags/<tag>`.
  Maalet är att taggar som innehåller `/`, mellanslag eller andra specialtecken ska fungera utan 404.
  Status: genomfört i kod, men ska verifieras med manuell uppdatering i WordPress.

- [x] Sluta lita på postade `repo`- och `tag`-värden i manuella installationsflöden.
  Hämta repo från installerat plugins `UpdateURI` eller temats `UpdateURI` på serversidan.
  Verifiera att vald tagg faktiskt finns i repoets releaselista innan installation startas.
  Status: genomfört i kod för både plugins och teman, men ska verifieras i WordPress-miljö.

- [x] Byt från `strpos(..., 'github.com')` till strikt host-validering.
  Använd `parse_url(..., PHP_URL_HOST)` och tillåt bara riktiga GitHub-hosts som `github.com`, `api.github.com`, `codeload.github.com` och eventuella explicita subdomäner som behövs.
  Maalet är att undvika falska positiva matchningar som `notgithub.com`.
  Status: genomfört i centrala flöden och listning/row-meta, men bör testas mot faktiska plugin- och temakonfigurationer.

## 1b. Verifiera det som redan är gjort

- [x] Testa manuell pluginuppdatering via GitUps UI efter hårdningen av pluginflödet.
  Kontrollera att pluginet fortfarande är aktivt, att versionen uppdateras och att inga WordPress-fel visas.
  Status: verifierat fungerande.

- [x] Testa manuell temauppdatering via GitUps UI och kontrollera att aktivt tema inte tappar status.
  Kontrollera att temat fortfarande är aktivt efteråt, att sajten inte faller tillbaka till annat tema och att inga PHP- eller WordPress-fel visas.
  Status: verifierat fungerande efter fix av temarot med trailing slash och korrigerad destination i theme-upgraderflödet.

- [x] Testa ordinarie WordPress-uppdatering för både plugin och tema via `plugins.php`, `themes.php` eller `update-core.php`.
  Bekräfta att uppdateringen fungerar även utan GitUps manuella knapp.
  Status: verifierat fungerande, inklusive aktivt tema efter stabilisering av theme-upgraderflödet.

- [ ] Bekräfta att repo med specialtecken i taggnamn nu laddas ner korrekt.
  Exempel: taggar med `/` eller mellanslag ska inte ge 404 vid nedladdning.

## 2. Stabilisera uppdateringslogiken

- [x] Centralisera URL-byggande för GitHub API och codeload.
  Lägg hjälpfunktioner för:
  `repo_path`-normalisering, host-kontroll, tagg-encoding och pakethämtning.
  Då minskar duplicerad logik mellan plugin-, tema- och adminflöden.
  Status: genomfört i kod via gemensamma helpers för repo-normalisering, paket-URL och releasehämtning.

- [x] Normalisera repo-URL:er konsekvent i hela pluginet.
  Bestäm ett format internt, till exempel full GitHub-URL utan trailing slash, och använd samma normalisering överallt.
  Det gör cache-nycklar och jämförelser stabilare.
  Status: genomfört i centrala flöden, adminlistor och manuella installationsflöden.

- [x] Gå igenom fallback-logiken för releases vs tags.
  Dokumentera exakt när pluginet använder `/releases/latest`, `/releases`, respektive `/tags`.
  Säkerställ att prerelease-inställningen respekteras likadant i UI och auto-uppdateringar.
  Status: genomfört i kod och centraliserat runt gemensam releasehämtning med konsekvent prerelease-beteende.

- [x] Se över felhantering för privata repos, rate limiting och ogiltig token.
  Samma scenario ska ge samma status i admin, Site Health och uppdateringsmotor.
  Status: genomfört i kod med gemensamma helpers för releasefel och förbättrad Site Health-bedömning.

## 3. Förbättra säkerhet och robusthet

- [x] Validera att manuella installationsrequests gäller en faktisk installerad komponent.
  För plugins: kontrollera att pluginfilen finns i `get_plugins()`.
  För teman: kontrollera att stylesheet finns i `wp_get_themes()`.
  Status: genomfört i kod för både plugin- och temaflöden.

- [x] Begränsa vad som får installeras via manuella actions.
  Om repo eller tagg inte matchar installerad komponent ska requesten avvisas tydligt.
  Status: genomfört i kod via server-side repo/tag-validering och borttagna dolda `repo`-fält i formulären.

- [x] Gå igenom alla admin notices, redirects och felmeddelanden.
  Säkerställ att de är konsekvent escaped, översättningsbara och inte läcker intern debug-information.
  Status: genomfört i kod via centraliserade settings-redirects/notices och snävare notice-visning på rätt adminskärm.

## 4. Lägg till tester

- [x] Inför automatiserade tester för versions- och URL-logik.
  Prioritera enhetstester för:
  tagg-normalisering, versionsjämförelse, host-validering och paket-URL-byggande.
  Status: genomfört med körbar testsvit i `tests/` som verifierar versionsnormalisering, versionsjämförelse, repo-normalisering och paket-URL-byggande.

- [ ] Lägg till tester för manuella installflöden.
  Testa att felaktigt `repo`/`tag` avvisas och att giltiga värden leder till rätt paket-URL och destination.

- [ ] Lägg till tester för releasehämtning och cache.
  Mocka GitHub-svar för:
  `200`, `401`, `403`, tom release-lista och tags-fallback.
  Status: delvis genomfört med tester för cache, `403`/`rate_limit` och tags-fallback. `401` och fler tom-/fel-scenarier återstår.

- [ ] Lägg till åtminstone ett integrations-/acceptanstest i WordPress-miljö.
  Verifiera att:
  ett plugin med GitHub `UpdateURI` får en uppdatering,
  en vald tagg kan installeras,
  och att aktivt plugin inte tappar korrekt destinationsmapp.

## 5. Förbättra kodkvalitet och underhåll

- [ ] Minska duplicering mellan plugin- och temaflöden.
  Det finns flera nästan identiska block för releasehämtning, URL-byggande och manuell installation.
  Flytta gemensam logik till återanvändbara hjälpare.

- [ ] Renodla Site Health-logiken.
  Säkerställ att returformatet följer WordPress förväntningar konsekvent och att status bygger på samma källor som övriga pluginet.

- [ ] Rensa debug-loggning och kommentarsnivå.
  Behåll användbar felsökningsloggning, men samla den runt tydliga hjälpare så att signal/brus blir bättre.

- [ ] Dokumentera de viktigaste besluten i README.
  Beskriv stödda GitHub-URL-format, hur prereleases fungerar, hur token valideras och vad som gäller för privata repos.

## 6. Verifiering innan release

- [ ] Testa manuellt mot minst fyra scenarier:
  publikt pluginrepo, privat pluginrepo, publikt tema och repo med specialtecken i taggnamn.

- [ ] Bekräfta att uppdatering fungerar både via WordPress update checks och via GitUps manuella installations-UI.

- [ ] Kör en sista genomgång av backward compatibility mot den WordPress-version ni siktar på.
  Kontrollera särskilt upgrader-hooks, Site Health och admin-UI.
