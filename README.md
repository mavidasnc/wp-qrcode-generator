# WP QRCode Generator

Plugin WordPress per creare codici QR che, alla scansione, fanno un **redirect**
oppure mostrano **contenuti e allegati**, e che tracciano le visite giorno per
giorno. Versione `0.3.0`, autore Mavida.

Il plugin è una riscrittura: non è compatibile con la prima versione (`0.1`) né
con la logica QR del tema bioclarma, e non importa nulla da essi.

## Requisiti

- WordPress 6.4+ e PHP 8.2+.
- Permalink "belli" consigliati (con i permalink semplici funziona, ma gli URL
  sono del tipo `?qr_code=codice`).
- Nessuna estensione PHP particolare: SVG e PNG si generano con la sola libreria
  e zlib. Non servono GD né Imagick, né ACF.
- La cartella `vendor/` (Composer) va distribuita con il plugin. Dopo un
  checkout pulito: `composer install --no-dev`.

## Come si usa

1. **Codici QR → Aggiungi.** Il titolo è solo il nome interno.
2. Al primo salvataggio il plugin assegna come slug un **codice casuale di 6
   caratteri** (es. `k7m2xp`): è la parte finale dell'URL pubblico
   `/qr/k7m2xp/`. Lo slug si può modificare a mano, ma cambia il contenuto del
   QR e quello già stampato smette di funzionare.
3. Nel box **Cosa succede alla scansione** scegli la modalità (sotto).
4. Nel box **Codice QR** vedi l'anteprima, copi l'URL e scarichi **SVG** o
   **PNG** (1024 px).
5. Pubblica: il QR funziona solo a post pubblicato.
6. Nel box **Statistiche** vedi le scansioni.

Il QR codifica sempre `/qr/{codice}/`: modalità, destinazione e contenuti si
cambiano in qualsiasi momento senza ristampare il codice.

## Modalità

| Modalità | Cosa fa alla scansione |
|---|---|
| **Redirect** | Risponde con un `302` verso l'URL impostato: esterno (`https://…`) o interno (`/pagina`). Si usa 302 e non 301 perché la destinazione è modificabile e il browser non deve ricordarla. Se l'URL manca o non è valido (schemi diversi da http/https) mostra la pagina del tema. |
| **Pagina del tema** | Usa la pagina single del tema (`single-qr_code.php`, altrimenti `single.php`). Il contenuto è quello dell'editor, seguito dall'elenco degli allegati. |
| **Pagina minimale** | Pagina con solo titolo, contenuto e allegati, senza header e footer del tema. Carica `wp_head()`/`wp_footer()` (stili dei blocchi inclusi). Un tema può sostituirla copiando `templates/minimal.php` in `{tema}/wpqr/minimal.php`. |

Gli **allegati** si scelgono dalla libreria media e compaiono come elenco di link
(titolo, tipo e peso del file) in coda al contenuto. Le pagine dei QR sono
`noindex` e sono escluse dalle cache di pagina (`DONOTCACHEPAGE`, header
no-cache e hook di LiteSpeed Cache): altrimenti una pagina in cache non
verrebbe contata.

## Immagini QR

Endpoint pubblico, utilizzabile anche per incorporare il QR in altre pagine:

```
/qr-image/{codice}.svg
/qr-image/{codice}.png
/qr-image/{codice}.png?size=2048&download=1
```

- `size`: lato in pixel, da 64 a 4096 (PNG predefinito 1024; per l'SVG
  aggiunge `width`/`height`, altrimenti scala sul contenitore).
  Nel PNG il lato reale è il multiplo intero di moduli più vicino per difetto,
  così i moduli restano nitidi.
- `download=1`: forza il salvataggio del file.
- Correzione errori livello **M** (15%) e margine bianco di 4 moduli.
- Il QR di una bozza si vede solo a chi può modificarla; per gli altri è 404.

**Libreria:** [`chillerlan/php-qrcode`](https://github.com/chillerlan/php-qrcode)
v6 (MIT/Apache-2.0, solo PHP) per matrice e SVG. Il PNG è scritto dal plugin
(`Generator::png()`) a partire dalla matrice: immagine a 1 bit con zlib,
verificata decodificandola con un lettore di QR esterno.

## Statistiche

Ogni richiesta alla pagina `/qr/{codice}/` viene registrata prima del redirect
o del rendering.

- **Tabelle:** `{prefix}wpqr_hits` (una riga per QR e giorno: `hits`,
  `uniques`) e `{prefix}wpqr_visitors` (hash dei visitatori già visti per QR e
  giorno). Le scritture sono atomiche (`INSERT IGNORE` e
  `ON DUPLICATE KEY UPDATE`), quindi due scansioni contemporanee non si perdono.
- **Giorno:** segue il fuso orario del sito.
- **Visitatore unico:** si identifica con un hash dell'IP
  (`HMAC-SHA256` con il salt `auth` del sito, troncato a 32 caratteri); l'IP non
  viene mai salvato. È stabile, quindi riconosce lo stesso visitatore anche in
  giorni diversi, ma resta un dato personale pseudonimizzato: va citato
  nell'informativa privacy. Si usa solo `REMOTE_ADDR`; dietro a un proxy fidato
  si filtra con `wpqr_client_ip`.
- **Cosa mostra la pagina del QR:** visite totali, visitatori unici, oggi,
  ultimi 7 e 30 giorni, grafico a barre degli ultimi 30 giorni e tabella
  giorno per giorno (ultimi 365 giorni, solo i giorni con visite). Nel totale
  gli unici sono IP distinti; nelle righe giornaliere sono quelli del giorno,
  quindi la somma delle righe può superare il totale.
- **Non vengono contati:** richieste `HEAD`, user agent vuoti, bot, crawler e
  anteprime dei link (WhatsApp, Telegram, Facebook, ...) e le visite di chi può
  modificare il QR, per non sporcare i numeri con le prove in admin. Per
  cambiare le regole c'è il filtro `wpqr_should_track`.
- L'elenco dei QR ha le colonne Visite e Unici. Eliminando un QR (non dal
  cestino) si eliminano anche le sue statistiche.

### Filtri

| Filtro | Argomenti | Uso |
|---|---|---|
| `wpqr_should_track` | `bool $track, int $qr_id` | forzare o escludere il conteggio di una richiesta |
| `wpqr_client_ip` | `string $ip` | leggere l'IP da un header di un proxy fidato |

## Struttura

```
wp-qrcode-generator/
├── wp-qrcode-generator.php   header, costanti, autoload Composer, hook di attivazione
├── composer.json             dipendenza chillerlan/php-qrcode e autoload PSR-4
├── includes/                 namespace Mavida\QRCodeGenerator
│   ├── Plugin.php            bootstrap e attivazione (tabelle, rewrite)
│   ├── Code.php              costanti, modalità, generazione codice, URL pubblici
│   ├── PostType.php          CPT qr_code (slug /qr/) e assegnazione del codice
│   ├── Generator.php         SVG, PNG e endpoint /qr-image/
│   ├── Tracker.php           tabelle, registrazione e query delle statistiche
│   ├── Frontend.php          redirect / pagina del tema / pagina minimale, allegati
│   ├── Admin.php             meta box, salvataggio, colonne dell'elenco
│   ├── Updater.php           controllo delle release su GitHub (plugin-update-checker)
│   └── UpdatesPage.php       pagina Codici QR > Aggiornamenti
├── templates/minimal.php     pagina minimale
├── assets/                   admin.css, admin.js (modalità, allegati, copia URL), frontend.css
└── vendor/                   dipendenze Composer
```

CSS con classi BEM e prefisso `wpqr-`. Le stringhe sono in italiano con text
domain `wp-qrcode-generator` (niente file di traduzione).

## Aggiornamenti

Il plugin si aggiorna dalle **release di GitHub**
([mavidasnc/wp-qrcode-generator](https://github.com/mavidasnc/wp-qrcode-generator),
repository pubblico) con [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker):
WordPress controlla ogni 12 ore e l'aggiornamento compare in *Plugin*. In
**Codici QR → Aggiornamenti** (serve la capability `update_plugins`) si vedono
versione installata e ultima release, si può forzare il controllo e installare
con un clic. L'updater scarica lo zip allegato alla release, che contiene
`vendor/`, e non il sorgente di GitHub.

### Pubblicare una release

1. Alzare la versione nell'header di `wp-qrcode-generator.php` (`Version:`) e in
   `WPQR_VERSION`, committare e fare push su `main`.
2. Creare il tag e pubblicarlo: `git tag vX.Y.Z && git push origin vX.Y.Z`.
3. Il workflow `.github/workflows/release.yml` controlla che il tag coincida con
   la versione, esegue i controlli di CI, installa le dipendenze di produzione e
   allega alla release lo zip `wp-qrcode-generator-X.Y.Z.zip`.

## Disattivazione e disinstallazione

La disattivazione ripulisce le rewrite rule. Le tabelle e i QR **non** vengono
eliminati da nessuna delle due operazioni (non c'è un `uninstall.php`): per
rimuovere i dati bisogna eliminare a mano i post `qr_code`, le due tabelle
`wpqr_*` e l'opzione `wpqr_db_version`.

## Da fare

- Pagina di opzioni (titolo e testi della pagina minimale, titolo "Allegati",
  colori, modalità predefinita, regole di conteggio).
- Conservazione dei dati: la tabella `wpqr_visitors` cresce di una riga per QR,
  giorno e visitatore; serve una pulizia periodica (WP-Cron) oltre una soglia.
- Test automatici (PHPUnit) e verifica con PHPCS/WPCS e PHPStan, come negli
  altri plugin Mavida.
- Colori e logo personalizzati del QR, e altre destinazioni oltre all'URL
  (telefono, vCard, Wi-Fi).
