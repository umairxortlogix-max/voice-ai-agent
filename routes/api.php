<?php

use App\Http\Controllers\VoiceAgentController;
use Illuminate\Support\Facades\Route;

Route::post('/voice/converse', [VoiceAgentController::class, 'converse']);
