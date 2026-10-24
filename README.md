# Ladestyring

Tænder et laderelæ i døgnets billigste timer - til fx et hjemmebatteri, en elbil eller en varmtvandsbeholder.
Styringen kører **lokalt på hjemmenettet** (Synology, Raspberry Pi eller en PC) og taler direkte med relæet - uden cloud.

Bygget med [php2html](https://github.com/EV-soft/Clever-Html-Engine). Kun på dansk, til prisområderne **DK1 / DK2**.

## Hvad den gør

- Henter **spotprisen** pr. kvarter fra [Energi Data Service](https://www.energidataservice.dk/) (DayAheadPrices) og **nettariffen**
  (Radius eller Cerius C-kunde, eller selvvalgt GLN og tarifkode) - viser prisen med eller uden moms og faste tillæg.
- Vælger de billigste kvarterer:
  - **Billigst blandt alle kendte priser fra nu** (standard): når morgendagens priser kommer (ca. kl. 13), vælges de billigste timer
    frem til sidste kendte pris (op til ca. 35 timer). Valget står fast til næste prisudmelding.
  - **Hver periode for sig**: fx de billigste 3 timer hvert døgn, eller kun om natten (18 - 07).
  - Én sammenhængende blok, eller de billigste kvarterer spredt.
- Tænder og slukker relæet hvert kvarter efter planen - og sender tilstanden igen hver time, hvis nogen har trykket på relæet.
- **Tænd nu / Sluk nu** i et antal timer, derefter automatik igen.
- Diagram over i dag og i morgen, **historik for den seneste uge** (hvornår der blev ladet, og til hvilken snitpris), log med filter.
- Login med adgangskode; spærring der vokser ved forkerte forsøg (5 s, 10 s, 20 s ... højst 15 min).

## Relæer

| Type | Hvordan | Status |
|---|---|---|
| **Tuya** (Smart Life / Tuya Smart, fx T34-MINI) | Lokalt med [tinytuya](https://github.com/jasonacox/tinytuya) - kræver enheds-id og *local key* | Testet |
| **Shelly, OpenBeken, Tasmota** | En http-adresse til tænd og en til sluk | Ikke testet |
| eWeLink (Sonoff) | Webhook-scener eller eWeLink API (cloud) | Ikke testet |

### Tuya: hent enheds-id og local key (én gang)

1. Opret en gratis konto på [iot.tuya.com](https://iot.tuya.com) - *Cloud - Development - Create Cloud Project*
   (Smart Home, data center **Central Europe**). Aktiver den gratis prøve af **IoT Core**.
2. I projektet: *Devices - Link App Account - Add App Account* - scan QR-koden i Smart Life-appen (Mig - scanner-ikonet øverst).
3. På PC'en: `pip install tinytuya` og `python -m tinytuya wizard` med projektets Access ID og Secret, region `eu`.
4. Skriv enheds-id, local key, IP og version (fx 3.4) under **Laderelæ - Tuya (lokalt)** på siden, og tryk *Prøv: tænd*.

Giv relæet en fast IP-adresse (DHCP-reservation) i routeren. Local key skifter, hvis relæet parres igen.

## Installation

### Synology (Docker) - anbefalet

Kræver en model med Docker (Intel / AMD, fx DS918+).

1. Kopier mappen til `/volume1/docker/ladestyring` (fx `\\NAS\docker\ladestyring`).
2. Over SSH:
   ```
   cd /volume1/docker/ladestyring
   sudo docker-compose up -d --build
   sudo docker exec -it ladestyring php ladestyring-setpass.php
   ```
3. Åbn `http://<NAS-ip>:8890/`, log ind og vælg relæet under **Laderelæ**.

Containeren (PHP 8.3 + Python med tinytuya) kører planlægningen hvert kvarter og viser siden på port 8890.
Indstillinger og log ligger i `data/` i mappen og overlever en ny container.

### Windows

PHP 8.1+ i `C:\php` og Python med `pip install tinytuya`. Dobbeltklik på `start-ladestyring.cmd`: den beder om en adgangskode første
gang, opretter en opgave i Opgavestyring (hvert 15. minut) og åbner siden på `http://localhost:8890/`. PC'en skal være tændt.

### Linux / Raspberry Pi

```
php ladestyring-setpass.php
crontab -e      # */15 * * * *  php /sti/til/ladestyring/ladestyring-cron.php
php -S 0.0.0.0:8890 -t /sti/til/ladestyring /sti/til/ladestyring/router.php
```

## Adgang udefra

Åbn **ikke** port 8890 i routeren - den er ukrypteret. Brug enten en VPN (fx Tailscale), eller DSM's reverse proxy med HTTPS
(fx `https://<navn>.synology.me:8443` til `http://localhost:8890`) og et Let's Encrypt-certifikat. Brug en god adgangskode.

## Sikkerhed

- `router.php` (til `php -S`, som ikke læser `.htaccess`) udleverer kun siden, favicon og `_cache/` - aldrig `data/`, scripts eller nøgler.
- Under Apache / LiteSpeed beskytter `data/.htaccess` mappen.
- Local key gives til Python på stdin, så den ikke ses i proceslisten. Adgangskoden gemmes som `password_hash`.
- `data/` og filerne fra tinytuya-guiden (`tinytuya.json`, `devices.json`, `snapshot.json`) er i `.gitignore` - læg dem aldrig i git.

## Filer

| Fil | Hvad |
|---|---|
| `ladestyring.php` | Siden |
| `ladestyring.lib.php` | Priser, tariffer, planen og relæerne |
| `ladestyring-cron.php` | Ét tjek - køres hvert 15. minut |
| `ladestyring-setpass.php` | Sætter adgangskoden (kun fra kommandolinjen) |
| `tuya_switch.py` | Tænder / slukker et Tuya-relæ lokalt (tinytuya) |
| `router.php` | Router til PHP's indbyggede webserver |
| `Dockerfile`, `docker-compose.yml`, `docker-entrypoint.sh` | Containeren |
| `start-ladestyring.cmd` | Start på Windows |
| `php2html.lib.php`, `_trans.sys.json`, `favicon.svg` | php2html 1.8.1 |

## Licens

GPL-3.0 - se [LICENSE](LICENSE).
