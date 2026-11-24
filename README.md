# Dynamo-Liste

Gemeinsame Abhakliste für die Spiele der SG Dynamo Dresden.
Läuft unter <https://dynamo.sergelaurance.tech> auf dem eigenen VPS
in einem PHP-Container hinter dem Caddy des Leasing-Projekts.

## Was die Liste kann

- **Spielplan aktualisiert sich selbst** aus OpenLigaDB (2. Bundesliga + DFB-Pokal),
  alle 6 Stunden beim nächsten Seitenaufruf. Kein Cronjob nötig.
- **Zu- und Absagen**: Auf den eigenen Namen tippen — 1× dabei, 2× nicht dabei, 3× wieder offen.
- **Zugangscode**: 6 Ziffern, danach ein Jahr lang gemerkt. Nach 5 Fehlversuchen 15 Minuten gesperrt.
- **Threema-Text**: fertiger Textblock zum Einfügen in die Gruppe.
- **Bilanz** (zweiter Reiter): Zusagen je Person, getrennt nach Heim und Auswärts.
  Drei Sichten — Gespielt, Ganze Saison, Alle Saisons.
- **Ewigkeitszähler**: `data/archiv.json` schreibt die Bilanz jeder Saison fort.
  Beim Saisonwechsel bleibt der alte Stand stehen, die alten Haken werden gelöscht.
- **Sternchen** `*` bei Spielen, deren Tag und Uhrzeit die DFL noch nicht angesetzt hat.

## Dateien

| Datei | Zweck |
|---|---|
| `index.php` | Seite, Anmeldung, API |
| `update.php` | holt den Spielplan von OpenLigaDB |
| `manifest.webmanifest`, `icon-*.png` | App-Symbol für den Startbildschirm |
| `config.php` | **nicht in diesem Repo** – liegt nur auf dem Server, enthält den Zugangscode |
| `data/` | **nicht in diesem Repo** – Spielplan, Haken, Archiv, Fehlversuche |

## Auf dem Server aktualisieren

```bash
cd /opt/dynamo-liste
cp -a . /root/dynamo-backup-$(date +%Y%m%d-%H%M)
curl -fsSLO https://raw.githubusercontent.com/SergeLaurance187/dynamo-liste/main/index.php
curl -fsSLO https://raw.githubusercontent.com/SergeLaurance187/dynamo-liste/main/update.php
```

`config.php` und `data/` bleiben dabei unberührt.

## Einstellungen (`config.php`)

| Einstellung | Bedeutung |
|---|---|
| `PEOPLE` | Namen und ihre Reihenfolge |
| `ACCESS_CODE` | 6-stelliger Zugangscode, leer = offen |
| `CODE_IN_LINK` | `true` erlaubt `…/?c=123456` zum Öffnen ohne Eingabe |
| `MAX_TRIES`, `LOCK_MINUTES` | Sperre nach Fehlversuchen |
| `SEASON` | Startjahr, `2026` = Saison 2026/27 |
| `SOURCES` | `bl2` = 2. Bundesliga, `dfb` = Pokal; bei Auf-/Abstieg `bl1` oder `bl3` |
| `TBD_DAYS` | Rückfallregel fürs Sternchen |
| `TBD_OVERRIDE` | Handkorrekturen je Spiel-ID |
| `TRUST_PROXY` | `true`, weil Caddy davorsteht |

## Saisonwechsel (Sommer 2027)

```bash
sed -i 's/const SEASON = 2026;/const SEASON = 2027;/' /opt/dynamo-liste/config.php
```

Bei Auf- oder Abstieg zusätzlich `SOURCES` anpassen. Den Rest erledigt die Liste selbst.

## Grenzen

- Ohne Login gibt es keine Identität: Wer den Code kennt, kann bei jedem Namen haken.
- Die Bilanz zählt **Zusagen**, keine bestätigten Besuche.
- Die Spieldaten stammen von OpenLigaDB, gepflegt von Freiwilligen. Vor langen Fahrten gegenprüfen.
