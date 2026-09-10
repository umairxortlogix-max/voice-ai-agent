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
