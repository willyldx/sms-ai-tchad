<?php

/*
|--------------------------------------------------------------------------
| SMS AI Configuration
|--------------------------------------------------------------------------
|
| Centralise toutes les variables liées au webhook SMS et au service IA.
| Utiliser config('sms.xxx') dans le code — JAMAIS env() dans un contrôleur.
|
*/

return [
    // Secret partagé avec l'app Android Gateway (header X-SMS-Webhook-Secret)
    'webhook_secret' => env('SMS_WEBHOOK_SECRET'),

    // Quota journalier par numéro de téléphone
    'daily_limit' => (int) env('SMS_DAILY_LIMIT', 3),

    // Fenêtre anti-doublon en secondes (ignore le même SMS renvoyé dans ce délai)
    'dedup_window_seconds' => (int) env('SMS_DEDUP_WINDOW_SECONDS', 120),

    // Longueur max de la réponse SMS (en caractères)
    'max_response_length' => (int) env('MAX_SMS_RESPONSE_LENGTH', 150),

    // URL interne du microservice IA (FastAPI)
    'ai_service_url' => env('PYTHON_AI_SERVICE_URL', 'http://ai-service:8000/ask'),

    // Timeout HTTP vers le service IA (secondes)
    'ai_timeout_seconds' => (int) env('AI_HTTP_TIMEOUT_SECONDS', 20),

    // Token d'authentification inter-services
    'ai_internal_token' => env('AI_INTERNAL_TOKEN'),
];
