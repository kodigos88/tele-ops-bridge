<?php
/**
 * TeleOps Bridge - Configuration Template
 * 
 * Instructions:
 * 1. Copy this file to 'config.php'
 * 2. Fill in your environment credentials
 * 3. Never commit 'config.php' to public source control
 */

// Bot Gateway Configuration
define('GATEWAY_TOKEN', 'YOUR_TELEGRAM_BOT_TOKEN_HERE');
define('OPERATOR_CHAT_ID', 'YOUR_NUMERIC_CHAT_ID_HERE');
define('SECRET_AUTH_KEY', 'CHANGE_THIS_TO_A_STRONG_RANDOM_SECRET_KEY');

// NLP Engine Configuration (Optional - set if you want conversational intelligence)
define('NLP_ENGINE_KEY', 'YOUR_API_KEY_HERE');
define('PRIMARY_MODEL', 'gemini-3.1-flash-lite-preview');
define('FALLBACK_MODEL', 'gemini-3.6-flash');

// Audio Synthesis Configuration (Optional - set if you want voice responses)
define('AUDIO_SYNTH_KEY', 'YOUR_AUDIO_SYNTH_KEY_HERE');
define('VOICE_PROFILE_ID', 'YOUR_VOICE_PROFILE_ID_HERE');
define('AUDIO_SYNTH_MODEL', 's2.1-pro-free');

// Custom System Persona / Directive (Optional)
define('SYSTEM_INSTRUCTION', 'You are a senior technical assistant and DevOps copilot. Keep answers direct, friendly, and practical.');

// Queue Storage
define('TASK_QUEUE_FILE', __DIR__ . '/task_queue.json');
