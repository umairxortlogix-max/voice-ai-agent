# Voice AI Agent — Laravel + React (Inertia)

Ek real voice AI agent: mic dabao, boliye, speech input ko process karta hai,
LLM reply sochta hai, aur TTS us reply ko awaz mein wapas bolta hai — modern
animated UI (breathing orb, live waveform rings, chat transcript) ke sath.

Project ab multi-provider fallback architecture support karta hai, taaki OpenAI
credits khatam hone ya rate-limit/timeout errors par system automatic next
provider par switch ho sake. Provider chain is tarah hai:

- Primary: Groq
- Secondary: Gemini
- Tertiary: OpenRouter (free endpoints)
- STT fallback: local Whisper
- TTS fallback: Edge TTS / gTTS

Is zip mein **sirf custom files** hain (controllers, services, React UI). Isse
ek fresh Laravel project ke andar overlay karna hai, kyunke poora Laravel
skeleton (artisan, public/index.php, config/app.php, etc.) yahan se generate
nahi ho sakta tha (Packagist tak network access nahi tha).

## Setup (5 steps)

### 1. Fresh Laravel project banayen
```bash
composer create-project laravel/laravel voice-ai-agent
cd voice-ai-agent
```

### 2. Packages install karen
```bash
composer require inertiajs/inertia-laravel guzzlehttp/guzzle
php artisan inertia:middleware   # agar available na ho to Step 4 dekhein

npm install @inertiajs/react framer-motion lucide-react react react-dom
npm install -D @vitejs/plugin-react
```

### 3. Is zip ki files copy karen
Is zip ke andar jo folders/files hain (`app/`, `routes/`, `resources/`,
`config/services.php`, `bootstrap/app.php`, `tailwind.config.js`,
`postcss.config.js`, `vite.config.ts`, `tsconfig.json`, `.env.example`) — inhe
apne naye Laravel project ke root mein copy/overwrite kar dein.

```bash
# zip extract karne ke baad, us folder ke andar se:
cp -r app resources routes config bootstrap ../voice-ai-agent/
cp tailwind.config.js postcss.config.js vite.config.ts tsconfig.json ../voice-ai-agent/
cp .env.example ../voice-ai-agent/.env.example
```

Tailwind agar already install nahi hai:
```bash
npm install -D tailwindcss postcss autoprefixer
```

### 4. `bootstrap/app.php` confirm karen
Laravel 11 mein `laravel new` already `bootstrap/app.php` banata hai — bas is
line ko `withMiddleware()` ke andar add kar dein (humari copy mein already hai):

```php
$middleware->web(append: [
    \App\Http\Middleware\HandleInertiaRequests::class,
]);
```

### 5. Environment aur run
```bash
cp .env.example .env      # agar pehle se .env nahi hai
php artisan key:generate
touch database/database.sqlite   # sqlite use kar rahe hain by default
php artisan migrate

# .env mein required provider keys daalen:
# GROQ_API_KEY=...
# GEMINI_API_KEY=...
# OPENROUTER_API_KEY=...
# optional local fallbacks: python + whisper + edge-tts / gtts

npm run dev        # ek terminal
php artisan serve  # dusra terminal
```

Ab `http://localhost:8000` kholein, mic button dabayen, browser mic
permission allow karen, boliye, aur agent fallback chain ke through
transcribe → LLM → jawab → TTS ke roop mein response dega.

## Multi-provider fallback strategy

System ke andar fallback logic is tarah kaam karta hai:

1. LLM inference primary rahega Groq (`openai/gpt-oss-20b`; fallback `openai/gpt-oss-120b`).
2. Agar Groq 429, quota error, timeout, ya 5xx aata hai to Gemini (`gemini-2.5-flash`) par switch hota hai.
3. Agar Gemini quota ya connection failure hota hai to OpenRouter free endpoint (`openai/gpt-oss-20b:free`) use hota hai.
4. `ShouldRetryWithFallback()` try-catch layer ke andar exceptions detect karta hai aur `[FALLBACK_EVENT] Primary failed: ... Switching to ...` format mein log karta hai.
5. Conversation system prompt, instructions, aur memory context provider switch ke baad bhi synchronized rakhte hain.

## Speech pipeline fallback

- **STT**: Primary Groq Whisper (`whisper-large-v3`), fallback local Whisper binary.
- **TTS**: Primary Edge TTS (zero-cost), fallback gTTS.

## Kaise kaam karta hai

- **Frontend** (`resources/js/`): `MediaRecorder` se aapki awaz record karta
  hai, `AnalyserNode` se live volume level nikalta hai jo orb ki animation
  (`VoiceOrb.tsx`) drive karta hai. Recording ruknay par audio blob
  `/api/voice/converse` ko bheja jata hai.
- **Backend** (`app/Http/Controllers/VoiceAgentController.php` +
  `app/Services/OpenAIService.php`): audio ko provider fallback chain ke sath
  transcribe karta hai, full conversation history ke sath next available model
  se reply leta hai, aur result ko TTS layer se mp3 mein convert kar ke ek hi
  JSON response mein wapas bhejta hai (`transcript`, `reply`, `audio_base64`).
- Frontend wapas mile mp3 ko play karta hai aur playback ke waqt bhi
  `AnalyserNode` se orb ko "speaking" animation deta hai.
- Voice responses concise rakhte hain (1–3 sentences) taaki real-time chat speed
  maintain rahe aur latency lower ho.

## Customize karna ho to

- **AI ki personality**: `VoiceAgentController::$systemPrompt` edit karen.
- **Provider config**: `.env` mein `GROQ_API_KEY`, `GEMINI_API_KEY`, `OPENROUTER_API_KEY` aur model names set karen.
- **Rang/theme**: `tailwind.config.js` ke `colors` block (`ink`, `violet`,
  `teal`, etc.) aur `resources/js/Components/VoiceOrb.tsx`.
- Chahen to `OpenAIService` ko replace kar ke Anthropic/ElevenLabs jaisi
  koi aur API use kar sakte hain — sirf `transcribe()`, `chat()`, `speak()`
  methods ka contract same rakhna hoga.

## Production note

Provider keys server-side `.env` mein hi rakhein. Frontend kabhi direct API ko
call nahi karta; sab request Laravel backend se jaati hai, isliye key browser
me expose nahi hoti.

## Windows system-control tools

AIVA ke Windows tools `app/Services/OpenAIService.php` mein allowlisted hain:

- Audio: `set_volume`, `toggle_mute`
- Display and connectivity: `set_brightness`, `toggle_wifi`, `toggle_bluetooth`
- System: `system_power_action`, `open_settings_page`
- Clipboard: `get_clipboard`, `set_clipboard`
- Processes: `list_running_processes`, `kill_process`

`set_brightness` laptop displays par WMI ke zariye kaam karta hai. External
monitors WMI brightness control support na bhi kar sakte hain, isliye un par
yeh tool fail ho sakta hai.

Wi-Fi aur Bluetooth adapters ko enable/disable karne ke liye administrator
privileges zaroori ho sakti hain. Laragon par right-click karein, **Run as
administrator** select karein, phir PHP/Laravel server restart karein. Failure
par AIVA yeh message return karta hai: `Requires administrator privileges. Run
Laragon/PHP as Administrator.`

Shutdown aur restart hamesha 60-second delay ke saath schedule hote hain. Inhe
cancel karne ke liye Windows Command Prompt mein `shutdown /a` chalayein.

### Confirmation flow

`system_power_action` aur `kill_process` pehle request par actual action nahi
karte. Pending action Laravel cache mein 60 seconds ke liye store hoti hai aur
AIVA confirmation maangti hai. Agle message mein `YES`, `haan`, ya `confirm`
hone par hi action execute hota hai.

Example commands:

- `volume 50 kar do` -> volume 50 percent set.
- `brightness 70 kar do` -> laptop brightness 70 percent set.
- `Wi-Fi band kar do` -> administrator permission required ho sakti hai.
- `PC shutdown kar do` -> confirmation maangega; `YES` ke baad 60-second shutdown schedule hoga.
- `Chrome process band karo` -> confirmation maangega.
- `explorer.exe band karo` -> hard-block hoga. `explorer.exe`, `csrss.exe`, `winlogon.exe`, `services.exe`, `System`, aur `svchost.exe` kabhi terminate nahi kiye ja sakte.

## Communication & Messaging Tools (Implemented)

System mein direct voice communication tools integrate kiye gaye hain:

- **`send_whatsapp_message`**: Contact name ya direct phone number par WhatsApp message draft ya auto-send karta hai. Pakistani format (`03...`) ko automatically `+92...` international format mein convert karta hai.
- **`save_contact`**: Voice se contact ka naam, phone number aur email address `storage/app/contacts.json` mein save karta hai.
- **`list_contacts`**: Saved address book dekhne aur search karne ke liye.
- **`compose_email`**: Recipient, subject aur body ke sath Gmail Web compose ya default OS mail client open karta hai.

**Commands Examples:**
- *"Ali ka number save karo 03001234567"*
- *"Ali ko WhatsApp par message karo: Main 10 minute mein pohanch raha hoon"*
- *"Mere contacts dikhao"*
- *"Ali ko email draft karo: Subject 'Project Update', Body 'Meeting kal subah 11 baje hai'."*

---

## 🚀 Voice Automation Roadmap & Feature Ideas

Yeh system ko full **Jarvis-style Autonomous Assistant** banane ke liye comprehensive feature ideas aur task categories hain:

### 1. Macro Routines (Single Voice Command $\rightarrow$ Multi-step Actions)
- **Developer / Work Mode:**
  - *"Work mode on karo"* $\rightarrow$ VS Code, Laragon/Docker, Chrome tabs (GitHub, Localhost) open kare, Spotify par lo-fi play kare, aur volume/brightness adjust kare.
- **Movie / Chill Mode:**
  - *"Movie mode"* $\rightarrow$ Screen brightness 30% kare, YouTube/Netflix khole, work apps minimize kare.
- **Good Night / Wrap Up:**
  - *"Good night Jarvis"* $\rightarrow$ Unsaved temp files clean kare, brightness 0% kare, aur 5 minute timer ke baad PC sleep/shutdown par lagaye.
- **Meeting / Focus Mode:**
  - *"Meeting mode"* $\rightarrow$ Music mute kare, Do Not Disturb on kare, aur unnecessary background processes close kare.

### 2. Developer Productivity & Laravel Workflows
- **Voice Error Log Reader:**
  - *"Latest error kya aya hai?"* $\rightarrow$ `storage/logs/laravel.log` parh kar summarize kare aur voice mein bataye ke kis file/line par issue hai.
- **Git Voice Assistant:**
  - *"Changes check karke quick commit aur push kar do"* $\rightarrow$ Git status scan kare, auto-commit message banaye aur push kare.
- **Voice Test Runner:**
  - *"Tests run karo"* $\rightarrow$ Background mein `php artisan test` execute kare aur passed/failed summary sunaye.

### 3. Screen Vision & Troubleshooting (Multimodal)
- **Screen Error Diagnostics:**
  - *"Screen par kya error hai dekho"* $\rightarrow$ `take_screenshot` ke zariye current screen capture kare aur GPT-4o Vision se diagnose karke solution bataye.

### 4. Smart File Organizer & Cleanup Automation
- **Organize Downloads Folder:**
  - *"Downloads folder organize karo"* $\rightarrow$ Files unki extension ke hisaab se auto-move kare (`.pdf` to Documents, `.png/.jpg` to Pictures, `.exe/.zip` to Installers).
- **Large Files Hunter:**
  - *"C drive mein barhi files dhundo"* $\rightarrow$ 1GB se barhi unnecessary files scan karke report de.

### 5. Desktop Voice Dictation & RPA (Mouse/Keyboard Control)
- **Active Window Auto-Typing:**
  - *"Type karo: Meeting notes finalize ho gaye hain"* $\rightarrow$ Active window (Notepad, Word, VS Code) mein direct text type kar de (using PowerShell `SendKeys`).
- **Presentation Controller:**
  - Presentation ke waqt hands-free control: *"Next slide"*, *"Previous slide"*, *"Full screen"*.

### 6. Voice Expense Tracker & Personal Notes
- **Daily Expense Logger:**
  - *"Expense add karo: 1500 petrol"* $\rightarrow$ SQLite database mein date aur category ke sath save kare.
  - *"Is haftay total kitna kharcha hua?"* $\rightarrow$ Total calculate karke bol kar sunaye.
- **Quick Voice Notes:**
  - *"Note likho: Kal bank se cheque collect karna hai"* $\rightarrow$ Markdown / Notion file mein add kare.

### 7. Live Web Intelligence & Tracking
- **Currency & Crypto Rates:**
  - *"Dollar aur Bitcoin ka live rate kya hai?"* $\rightarrow$ API se live rate fetch karke bataye.
- **YouTube Video Summarizer:**
  - Open YouTube link ka summary mangne par transcription analyze karke 2 minute mein khulasa sunaye.

### 8. AI Interview & Practice Partner
- **Laravel / Tech Mock Interview:**
  - *"Laravel interview mode start karo"* $\rightarrow$ Ek ek question puche aur candidate ke voice answers par constructive feedback de.

