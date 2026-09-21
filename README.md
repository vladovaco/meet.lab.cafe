# Meet Lab.cafe – nahrávanie, prepis a triedenie porád

Mobile‑first webová aplikácia (PHP 8.1+, MySQL 8 / MariaDB 10.6+, bez frameworku) na:

- **nahrávanie porady priamo z mobilu** (mikrofón cez `MediaRecorder`) alebo **upload** audio súboru (mp3, m4a, wav, webm, ogg, flac…),
- **automatický prepis s rozpoznávaním rečníkov** (diarizácia),
- **databázu účastníkov** – rečníci z nahrávky sa priradia ku konkrétnym ľuďom, aplikácia si pamätá kto sa čoho zúčastnil, koľko hovoril a aké má úlohy,
- **štruktúrovaný zápis**: súhrn, priebeh podľa tém s časovými značkami, kľúčové body, rozhodnutia, otvorené otázky a **úlohy s priradenou osobou, termínom a prioritou**,
- **triedenie**: priečinky (tím / projekt / klient), štítky (AI ich navrhuje automaticky), filtrovanie podľa účastníka, fulltextové hľadanie v prepisoch,
- export zápisu do Markdown / prepisu do TXT, kopírovanie do schránky, PWA (ikona na ploche telefónu).

---

## 1. Výber AI API

| Krok | Služba | Prečo |
|------|--------|-------|
| Prepis reči + diarizácia | **ElevenLabs Scribe v2** (`POST /v1/speech-to-text`) – predvolené | 90+ jazykov vrátane **slovenčiny a češtiny**, diarizácia až 32 rečníkov, časové značky na úrovni slov, jedno synchrónne volanie bez pollingu, súbory do 5 GB. Cena cca **0,22 USD / hodinu** audia. Podpora EU data residency a zero‑retention. |
| Prepis reči (alternatíva) | **AssemblyAI** (EU endpoint `api.eu.assemblyai.com`) | Silná diarizácia (`speaker_labels`), automatická detekcia jazyka, dáta ostávajú v EÚ. Prepína sa jednou premennou `STT_PROVIDER=assemblyai`. |
| Štruktúrovanie zápisu | **Claude API** (`claude-opus-5`, oficiálne PHP SDK `anthropic-ai/sdk`) | Najlepšie rozumie dlhému kontextu (1M tokenov – aj viachodinová porada v jednom volaní), výborná slovenčina, **structured outputs** (JSON podľa schémy) – zápis, úlohy, rozhodnutia aj odhad mien rečníkov prídu v garantovanom formáte. |

Prečo dve služby a nie jedna: špecializované STT modely majú výrazne lepšiu diarizáciu a WER ako univerzálne modely, zatiaľ čo Claude je lepší na porozumenie obsahu, priradenie úloh ľuďom a odvodenie mien z kontextu („Peťo, čo ty na to?“). Ďalšie zvažované možnosti: Deepgram Nova‑3 (rýchly, slovenčina áno, slabšia interpunkcia), Speechmatics (výborná diarizácia, drahší), Gladia, OpenAI `gpt-4o-transcribe-diarize` (bez EU residency). Adaptéry sú oddelené v `src/Services/Transcription/`, pridanie ďalšieho poskytovateľa je jedna trieda implementujúca `TranscriberInterface`.

### Ako funguje rozpoznanie rečníkov a účastníkov

1. STT vráti slová s `speaker_id` (`speaker_0`, `speaker_1`…). Aplikácia ich zlúči do segmentov a spočíta, koľko každý hovoril.
2. Claude dostane prepis + zoznam **očakávaných účastníkov** (zaškrtnutých pri vytváraní porady) + celú databázu mien a prezývok. Z kontextu odhadne, kto je ktorý label, s mierou istoty.
3. Ak je zhoda s databázou dostatočne istá, rečník sa priradí automaticky; inak sa zobrazí ako návrh a používateľ ho jedným ťuknutím potvrdí alebo vytvorí nového účastníka. Ručne potvrdené priradenie sa pri opakovanej analýze neprepisuje.
4. Priradenie sa prenesie do prepisu, úloh (úloha „speaker_1 pošle bannery“ → Jana Kováčová) a do štatistík účastníka.

---

## 2. Inštalácia

### A) Docker (najrýchlejšie)

```bash
cp .env.example .env          # doplňte ELEVENLABS_API_KEY a ANTHROPIC_API_KEY
docker compose up -d --build
docker compose exec app php bin/install.php --email=admin@lab.cafe --password=TajneHeslo --name="Admin"
```

Aplikácia beží na http://localhost:8080. Prepis a analýza sa spracúvajú v službe `worker` (`bin/worker.php --loop`).

### B) Klasický hosting (Apache/nginx + PHP 8.1+ + MySQL)

```bash
composer install --no-dev
cp .env.example .env            # nastavte DB_*, API kľúče, APP_URL, SESSION_SECRET
php bin/install.php --email=admin@lab.cafe --password=TajneHeslo
```

- DocumentRoot nastavte na `public/` (je tam `.htaccess` s rewrite pravidlami). Pri nginx smerujte všetko na `public/index.php`.
- Adresár `storage/` musí byť zapisovateľný webserverom (aj workerom).
- PHP limity pre veľké nahrávky: `upload_max_filesize=300M`, `post_max_size=310M`, `max_execution_time=600`.
- **Spracovanie na pozadí** – dve možnosti (`PROCESS_MODE` v `.env`):
  - `cron` (odporúčané): `* * * * * php /cesta/k/aplikacii/bin/worker.php >> storage/logs/worker.log 2>&1`
  - `web`: shared hosting bez cronu – úlohu spustí prehliadač, ktorý čaká na výsledok (požiadavka beží na pozadí aj po zatvorení stránky vďaka `ignore_user_abort`).

Prihláste sa a v **Nastaveniach** skontrolujte stav služieb (zelené ✅ pri prepise aj AI).

### C) Zdieľaný hosting bez SSH (napr. Websupport) – nasadenie cez FTP z GitHubu

Workflow `.github/workflows/deploy.yml` po každom pushi do `main`:
1. spustí `composer install` a skontroluje syntax PHP,
2. zabalí aplikáciu **vrátane `vendor/`** do `release.zip` (na hostingu netreba Composer ani git),
3. cez FTP(S) nahrá iba tri súbory: `release.zip`, `public/deploy.php` a `.deploy-token` (skript `bin/deploy-ftp.sh`, nástroj `lftp` s automatickým opakovaním),
4. zavolá `https://DOMENA/deploy.php?token=…`, ktorý archív na serveri rozbalí a zmaže.

`.env` ani `storage/` sa nikdy neprepíšu (v archíve nie sú). Celé nasadenie trvá asi minútu.

**Nastavenie (raz):**

1. Na GitHube v **Settings → Secrets and variables → Actions** vytvorte secrets (alebo naraz cez `gh secret set -f secrets.env`):

   | Secret | Hodnota |
   |---|---|
   | `FTP_SERVER` | napr. `ftp.websupport.sk` (bez `ftp://`) |
   | `FTP_USERNAME` | FTP používateľ |
   | `FTP_PASSWORD` | FTP heslo |
   | `FTP_REMOTE_DIR` | koreň aplikácie na serveri, napr. `/sub/meet` (adresár, kde bude `composer.json`) |
   | `DEPLOY_URL` | verejná adresa aplikácie, napr. `https://meet.lab.cafe` |
   | `DEPLOY_TOKEN` | dlhý náhodný reťazec (napr. `openssl rand -hex 32`) |

   Voliteľné: `FTP_PORT` (21), `FTP_SSL` (`false` = bez TLS, ak FTPS na hostingu zlyháva), `FTP_VERIFY_CERT` (`true` = overovať certifikát).
2. DocumentRoot subdomény nastavte na `…/public` (napr. `sub/meet/public`).
3. Spustite nasadenie: push do `main`, alebo **Actions → Deploy (FTP) → Run workflow**.
4. Cez FTP nahrajte do koreňa aplikácie súbor `.env` (podľa `.env.example`) s DB údajmi, API kľúčmi, `SESSION_SECRET`, `CRON_TOKEN` a `SETUP_TOKEN`.
5. Otvorte `https://DOMENA/setup.php?token=SETUP_TOKEN` – vytvorí tabuľky, skontroluje PHP a vytvorí admina. Potom `SETUP_TOKEN` z `.env` odstráňte.
6. Cron bez SSH: v paneli hostingu nastavte cron (každú minútu) na URL `https://DOMENA/cron/run?token=CRON_TOKEN` a v `.env` dajte `PROCESS_MODE=cron`. Bez cronu nechajte `PROCESS_MODE=web`.

Pri ďalšej zmene stačí push do `main`. Ak sa zmenila databázová schéma, otvorte znova `setup.php?token=…` (schéma je idempotentná). Nasadenú verziu vidno v súbore `VERSION` na serveri a vo výstupe `deploy.php`.

### Mikrofón na mobile

Nahrávanie z mikrofónu vyžaduje **HTTPS** (okrem `localhost`). iOS Safari nahráva do `audio/mp4`, Android Chrome do `audio/webm` – oba formáty backend prijíma. Počas nahrávania aplikácia drží obrazovku zapnutú (Wake Lock) a ukladá dáta po 5‑sekundových blokoch.

---

## 3. Štruktúra projektu

```
public/            front controller, CSS, JS (recorder.js = mikrofón/upload, meeting.js = detail), PWA
src/Core/          mini‑framework: Router, Request, Database (PDO), Auth, Csrf, View, Env
src/Models/        Meeting, Participant, ActionItem, Tag, Job
src/Services/
  Transcription/   TranscriberInterface, ElevenLabsScribe, AssemblyAI, TranscriptResult
  Analysis/        MeetingAnalyzer (Claude API, JSON schema výstup)
  JobProcessor.php fronta: transcribe → analyze, retry, zámok cez GET_LOCK
  Storage.php      ukladanie nahrávok mimo public/
src/Controllers/   Auth, Dashboard, Meeting, Api (AJAX), Participant, Tag, Search, Settings
src/Views/         PHP šablóny (mobile first)
database/schema.sql
bin/worker.php     cron / loop worker
bin/install.php    vytvorenie DB schémy a admina
```

Tok spracovania: upload → `meetings.status=queued` → job `transcribe` (STT, segmenty, štatistika rečníkov) → job `analyze` (Claude → témy, body, rozhodnutia, úlohy, štítky, mená rečníkov) → `done`. Chyby sa zobrazia priamo pri porade s tlačidlom „Skúsiť znova“; neúspešné úlohy vidno v Nastaveniach.

---

## 4. Návrh ďalších funkcií (roadmapa)

**Rýchle výhry**
- **Sledovanie úloh naprieč poradami** – pripomienky termínov e‑mailom / push, denný digest otvorených úloh pre každého účastníka.
- **Odoslanie zápisu e‑mailom** účastníkom hneď po spracovaní (šablóna z Markdown exportu), export do PDF/DOCX.
- **Vlastné šablóny zápisu** podľa typu porady (stand‑up, retrospektíva, stretnutie s klientom, board) – iný prompt a iná štruktúra výstupu.
- **Prepojenie s kalendárom** (Google Calendar / Outlook): automatické predvyplnenie názvu, účastníkov a času porady z udalosti; po porade zápis späť do udalosti.
- **Kapitolové značky v prehrávači** a klik na vetu v zápise → skok v audiu na miesto, kde to zaznelo (dáta už sú k dispozícii v `meeting_topics.start_sec` a `action_items.source_quote`).

**Presnejšie rozpoznávanie ľudí**
- **Hlasové profily účastníkov** (speaker identification cez embeddingy hlasu, napr. pyannote / AssemblyAI custom speaker profiles) – rečník sa spozná podľa hlasu, nie len podľa kontextu.
- **Slovník firemných pojmov a mien** (keyterm prompting v Scribe / word boost) – lepší prepis interných názvov produktov, klientov a skratiek.
- Automatické zlučovanie duplicitných účastníkov („Peter“, „Peťo Novák“).

**Práca so znalosťami**
- **Chat nad poradami** („Čo sme sľúbili klientovi X v auguste?“) – Claude s prístupom k prepisom, citácie so skokom do audia.
- **Sémantické vyhľadávanie** (embeddingy) namiesto LIKE; súvisiace porady, história rozhodnutí k téme.
- **Týždenný prehľad** – čo sa rozhodlo, ktoré úlohy meškajú, kto je preťažený (podľa počtu úloh), rečový čas na porade (kto dominuje).

**Integrácie a tím**
- Export úloh do Todoist / Asana / Trello / Jira, notifikácie do Slacku / Teams.
- Webhook z ElevenLabs (asynchrónny prepis bez blokovania workera) a live prepis (Scribe realtime) počas porady.
- Import nahrávok z Google Meet / Zoom / Teams cloud recordings.
- Práva a zdieľanie: súkromné porady, tímy/workspace, hosťovský odkaz na zápis, audit log.
- Viacjazyčné porady (code‑switching), preklad zápisu do angličtiny.

**Prevádzka**
- Kompresia nahrávok (ffmpeg → opus 32 kbps) pred uložením a pred odoslaním do STT – 10× menšie súbory.
- Retencia: automatické mazanie audia po X dňoch (zápis ostáva), GDPR export/vymazanie účastníka.
- Chunkovaný upload a pokračovanie po výpadku siete pri dlhých nahrávkach z mobilu.

---

## 5. Bezpečnosť

- Prihlásenie (bcrypt), session cookie `HttpOnly`/`SameSite=Lax`, CSRF token na každý POST (formuláre aj AJAX).
- Nahrávky sa ukladajú mimo `public/` a streamujú sa cez aplikáciu iba prihláseným.
- API kľúče sú len v `.env` na serveri, nikdy sa neposielajú do prehliadača.
- Odporúčanie: HTTPS, pravidelné zálohy DB a `storage/audio`, prípadne EU data residency / zero retention u poskytovateľov STT.
