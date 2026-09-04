# Voice AI Agent — Laravel + React (Inertia)

Ek real voice AI agent: mic dabao, boliye, Whisper transcribe karta hai, LLM reply
sochta hai, aur TTS us reply ko awaz mein wapas bolta hai — modern animated UI
(breathing orb, live waveform rings, chat transcript) ke sath.

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

# .env mein apni OpenAI API key daal dein:
# OPENAI_API_KEY=sk-...

npm run dev        # ek terminal
php artisan serve  # dusra terminal
```

Ab `http://localhost:8000` kholein, mic button dabayen, browser mic
permission allow karen, boliye, aur agent transcribe → soch → jawab bol kar
dega.

## Kaise kaam karta hai

- **Frontend** (`resources/js/`): `MediaRecorder` se aapki awaz record karta
  hai, `AnalyserNode` se live volume level nikalta hai jo orb ki animation
  (`VoiceOrb.tsx`) drive karta hai. Recording ruknay par audio blob
  `/api/voice/converse` ko bheja jata hai.
- **Backend** (`app/Http/Controllers/VoiceAgentController.php` +
  `app/Services/OpenAIService.php`): audio ko Whisper se transcribe karta hai,
  poori conversation history ke sath chat model ko bhejta hai, aur reply ko
  TTS se mp3 mein convert kar ke ek hi JSON response mein wapas bhejta hai
  (`transcript`, `reply`, `audio_base64`).
- Frontend wapas mile mp3 ko play karta hai aur playback ke waqt bhi
  `AnalyserNode` se orb ko "speaking" animation deta hai.

## Customize karna ho to

- **AI ki personality**: `VoiceAgentController::$systemPrompt` edit karen.
- **Model/voice**: `.env` mein `OPENAI_CHAT_MODEL`, `OPENAI_TTS_VOICE` change
  karen.
- **Rang/theme**: `tailwind.config.js` ke `colors` block (`ink`, `violet`,
  `teal`, etc.) aur `resources/js/Components/VoiceOrb.tsx`.
- Chahen to `OpenAIService` ko replace kar ke Anthropic/ElevenLabs jaisi
  koi aur API use kar sakte hain — sirf `transcribe()`, `chat()`, `speak()`
  methods ka contract same rakhna hoga.

## Production note

`OPENAI_API_KEY` hamesha server (`.env`) mein hi rakhein — frontend kabhi
directly OpenAI ko call nahi karta, hamesha aapke Laravel backend se hota hai,
isliye key kabhi browser mein expose nahi hoti.
