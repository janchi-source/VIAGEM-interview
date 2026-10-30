# README – Mapa parcel, okres Jičín

---

Webová aplikace, která zobrazí katastrální parcely okresu Jičín na mapě. Po kliknutí na parcelu ukáže její údaje: číslo, katastrální území, obec, druh pozemku, způsob využití, výměru, BPEJ, stavby s adresami a odkaz do Nahlížení do KN. Backend je v čistém PHP bez frameworku a bez závislostí. Frontend používá MapLibre GL JS.

## **Předpoklady**

- **PHP 8.1+** s  `pdo_sqlite`, `xmlreader`, `dom`, `zip`, `curl`
- Při importu **přístup na internet** k `vdp.cuzk.cz`
- Prohlížeč s WebGL (například aktuální Chrome)

## **Spuštění**

---

```
# 1. Stáhnout data z ČÚZK a naimportovat je do SQLite (celý okres)
php bin/import.php

# 2. Vygenerovat všechny dlaždice předem, aby byl první pohled na okres okamžitý
php bin/warm-tiles.php

# 3. Spustit server a otevřít http://localhost:8000
php -S localhost:8000 -t public public/index.php
```

## **Zdroje dat**

Parcely beru z výměnného formátu RÚIAN (VFR) z `vdp.cuzk.cz`, konkrétně ze stavu k 30. 9. 2026. Soubor `ST_UKSG` obsahuje jen strukturu území, parcely jsou až v souborech `OB_<kód obce>_UKSH`, jeden za každou obec. Import (`bin/import.php`) proto z `ST_UKSG` vezme obce okresu Jičín (kód okresu 3604), stáhne soubor každé z nich, uloží parcely, katastrální území, stavby a adresní místa do SQLite a stažený soubor hned smaže. Geometrie jsou v S-JTSK (EPSG:5514), na WGS84 je převádí vlastní třída `Krovak`.

Data jsem si stáhl předem a živé služby ČÚZK pro parcely nepoužívám. WFS je omezené počtem prvků a rozsahem dotazu, takže by při každém pohybu mapy vracelo jen kousek území a plynulost by záležela na dostupnosti cizí služby. Nevýhoda je, že data stárnou. Aktualizace je opakovaný běh `bin/import.php`.

Podkladová mapa je Esri World Street Map. Volitelně jde zapnout ortofoto a katastrální mapu (WMS KN) z ČÚZK, ty se na rozdíl od parcel načítají živě. Údaje o vlastnících v otevřených datech nejsou a aplikace je nezískává. K parcele je jen odkaz do Nahlížení do KN.

## **Architektura projektu**

```
bin/import.php              import z ČÚZK
bin/warm-tiles.php          předgenerování dlaždic (paralelně přes pcntl_fork)
public/index.php            vstupní bod webu
public/index.html, assets/  frontend
src/Import/                 stahování a čtení VFR, parsování GML
src/Geo/                    převod souřadnic (Křovák), dlaždicová matematika, binární uložení geometrie
src/Tiles/                  generování MVT: protobuf, ořez, zjednodušení, cache
src/Domain/                 dotazy pro API, číselníky, parcelní čísla
src/Http/                   router a odpovědi
tests/                      testy + generátor syntetických VFR dat
docs/PRUVODCE-KODEM.md      podrobný průchod kódem
```

# Postup práce

---

Začal jsem studiem problému a následným plánováním. Protože jsem si pomáhal AI, je plánování nejpodstatnější věc, aby se zachoval pořádek, efektivita a přehlednost. Také je dobré uchovávat kontext pro další práci s AI. Řešení problému jsem si rozdělil na 9 částí:

- Příprava: zadání, rozhodnutí, CLAUDE.md
- Kostra, testy, převod souřadnic
- Reálná data ČÚZK a čtení VFR
- Databáze a import
- Pokus: GeoJSON a měření
- Vektorové dlaždice
- HTTP API
- Frontend
- Celý okres, výkon, kontrola s ČÚZK

Většinu projektu jsem dělal v GitHub Codespaces kvůli jednotnosti a organizovanosti. Kvůli výkonnostnímu omezení u vektorových dlaždic jsem se ale přesunul na lokál, kde jsem měl potíže to rozjet kvůli staršímu OS a nedostatku RAM.&#32;

## Práce s AI

---

Všechny prompty jsem si připravil předem, včetně věcí, na které jsem dohlížel, aby vše správně fungovalo. Po každém promptu jsem si prošel kód a opravil pár testů. Používal jsem nástroje GitHub Copilot a Claude Code. S PHP mám jen minimální zkušenosti, jinak jsem zvyklý na Flask, a proto mi šlo hlavně o to, abych každému řešení rozuměl a uměl ho vysvětlit.

## **Výkon a plynulost**

---

Mapa zůstává plynulá, protože prohlížeč dostává jen dlaždice aktuálního výřezu, každá je malá a po prvním sestavení se čte ze souboru. Data jsou stažená předem (okres Jičín, 111 obcí, 240 katastrálních území, 271 995 parcel) a leží v jedné SQLite databázi o velikosti 93 MB. Data parcel se za běhu z ČÚZK nenačítají.

Dlaždice ve formátu MVT staví backend sám v PHP (`src/Tiles`). Geometrie jsou v databázi uložené binárně jako celá čísla. Každá dlaždice se ořízne na svůj výřez s okrajem 64 jednotek, zkvantizuje na rozsah 4096 a zjednoduší algoritmem Douglas-Peucker. Parcely se do dlaždic dávají až od úrovně z13. Pod ní nese mapu jen vrstva hranic a názvů katastrálních území, takže pohled na celý okres žádné parcely nenačítá. Na z13 se navíc vynechají parcely menší než přibližně jeden pixel. Čísla parcel jsou v dlaždicích jen na z15, což je nejvyšší úroveň, výš prohlížeč dlaždice zvětšuje. V dlaždici je u parcely jen druh pozemku a výměra, ostatní údaje se načtou až po kliknutí z `/api/parcels/{id}`. Zvýraznění pod kurzorem řeší MapLibre přes `feature-state`, bez překreslování zdroje.

Hledání parcel ve výřezu dělá R-strom `parcel_rtree` nad obálkami parcel. Hotové dlaždice ukládá `TileCache` do `public/tiles/{z}/{x}/{y}.pbf`, zápis jde přes dočasný soubor a `rename`, takže souběžný požadavek nikdy nevidí napůl zapsaný soubor. Přečtení dlaždice z cache trvá asi 0,005 ms.

Naměřeno na 40 náhodných dlaždicích z obálky okresu pro každou úroveň, jeden běh, PHP 8.3 CLI, výchozí nastavení, dlaždice bez cache:

| Úroveň | Dlaždic v obálce okresu | Sestavení bez cache (průměr) | Velikost dlaždice (průměr) |
| --- | --- | --- | --- |
| z12 | 54 | 13 ms | 3,3 KB |
| z13 | 176 | 669 ms | 96 KB |
| z14 | 600 | 578 ms | 25 KB |
| z15 | 2 301 | 573 ms | 12 KB |

## **Informace o parcele**

Po kliknutí na parcelu si prohlížeč vezme její identifikátor z dlaždice a zavolá `GET /api/parcels/{id}`. Odpověď sestaví `ParcelRepository::find` jedním dotazem nad tabulkami `parcel`, `ku`, `obec` a `parcel_rtree` a doplní k ní stavby. Všechna data pocházejí z výměnného formátu RÚIAN (VFR) od ČÚZK a při importu se uložila do databáze, takže se detail načítá lokálně.

V detailu se zobrazuje:

- číslo parcely (kmenové číslo, případně s pododdělením, rozlišené pozemkové a stavební parcely), katastrální území a obec
- druh pozemku a způsob využití, každé s názvem z číselníku (když název neznáme, zobrazí se kód)
- výměra v m²
- BPEJ: kód ve tvaru X.XX.XX rozepsaný na klimatický region, hlavní půdní jednotku, sklonitost s expozicí a skeletovitost s hloubkou půdy, u každé výměra a její podíl na parcele
- druhy ochrany a datum, od kdy údaj platí
- stavby na parcele s čísly popisnými a orientačními a s adresními místy
- odkaz na stejnou parcelu v Nahlížení do KN

Odpověď nese také geometrii parcely a její obálku. Geometrie slouží ke zvýraznění vybrané parcely na mapě a k exportu do GeoJSON, obálka k přiblížení mapy na parcelu. Parcelu jde najít i podle čísla, volitelně v rámci jednoho katastrálního území (`/api/parcels/search`, nejvýš 100 výsledků).

## **Vlastní iniciativa**

Zadání chtělo povinné minimum a já jsem pokryl celý okres Jičín (111 obcí, 240 katastrálních území). Navíc jsem udělal tohle:

- vyhledávání parcely podle čísla, volitelně v jednom katastrálním území, včetně stavebních parcel (`st. 56`)
- legendu s barvami podle druhu pozemku, kterou jde zapínat a vypínat po druzích
- v detailu rozepsané BPEJ a stavby s adresními místy
- export vybrané parcely do GeoJSON a odkaz na ni v Nahlížení do KN
- odkaz na polohu mapy a na vybranou parcelu v adrese stránky
- přepínání podkladu (ortofoto ČÚZK) a vrstvu katastrální mapy ČÚZK
- testy (`php tests/run.php`, 34 kontrol) a generátor syntetických VFR dat, aby šel import i tile builder zkoušet bez stahování z ČÚZK
- `Dockerfile` a `render.yaml` pro nasazení, zatím jen připravené

## **R****ozhodnutí**

Zadání nechává volbu na mně, tak tady je, co jsem rozhodl a proč.

- SQLite místo PostGIS. Aplikace se spustí jedním příkazem bez databázového serveru, stačí PHP s `pdo_sqlite`. Prostorové hledání zajišťuje R-strom, který SQLite umí sám. Za to platím tím, že nemám prostorové funkce a dlaždice si musím počítat sám.
- Čisté PHP bez frameworku a bez balíčků. Zadání říká, že čisté PHP je nejlepší, a kód se pak dá celý projít. Třídy načítá jednoduchý PSR-4 autoloader v `bootstrap.php`.
- Vlastní generátor vektorových dlaždic v PHP, ne hotový tile server.&#32;
- GeoJSON podle výřezu jsem zkusil a zavrhl (`public/pokus.php`). Jedna dlaždice z centra Jičína na úrovni z13 má 10 860 parcel a jako GeoJSON 4,2 MB (913 KB po gzipu), jako MVT 379 KB. Pro celý okres by to nebylo plynulé.
- Parcely se kreslí až od z13. Pod touto úrovní jsou vidět jen hranice a názvy katastrálních území, takže pohled na celý okres zůstává levný.
- Souřadnice ukládám jako celá čísla (stupně krát 10 milionů) v binárním formátu. Je to kompaktní a nepotřebuju rozšíření pro geometrie. Z S-JTSK převádím do WGS84 vlastní třídou `Krovak` a ověřil jsem ji testem na známém bodě.
- Celý okres místo minima. Import už stejně běží po obcích, takže další obce nic nestály, jen čas importu.

## **Co mě překvapilo**

Nejvíc mě překvapilo, že soubor `ST_UKSG` parcely vůbec neobsahuje. Je v něm jen struktura území, parcely jsou ve zvláštním souboru pro každou obec, takže import okresu je ve skutečnosti sto jedenáct menších stažení.

Druhá věc byly souřadnice. S-JTSK se v EPSG zapisuje se zápornými hodnotami a inverzní Křovákovo zobrazení k tomu ještě prohazuje osy. Bez testu na známém bodě by se parcely objevily na špatném místě a nebylo by jasné proč.

## **Co bych s více časem řešil jinak**

- Dlaždice bych posílal s `Cache-Control` a komprimované. Teď to aplikace sama nedělá.
- Aktualizaci dat bych dělal z VFR změnových souborů, ne celým novým importem. Import teď staví novou databázi vedle staré, na konci ji přepne a smaže cache dlaždic, takže běží bez odstávky, ale vždy celý.

## **Časová náročnost**

Celkem to vyšlo okolo 10 hodin. Rozpis po fázích je v tabulce.

| Fáze | Čas |
| --- | --- |
| Příprava prostředí | 1 h |
| Načtení zadání, research a rozhodnutí | 2 h |
| Kostra, testy, převod souřadnic | 1 h |
| Reálná data ČÚZK a čtení VFR | 30 min |
| Databáze a import | 30 min |
| Pokus: GeoJSON a měření | 40 min |
| Vektorové dlaždice | 1 h |
| HTTP API | 20 min |
| Frontend | 1 h |
| Celý okres, výkon, kontrola s ČÚZK | 2 h |
| README | 2 h |
