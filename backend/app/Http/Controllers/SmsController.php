<?php

namespace App\Http\Controllers;

use App\Models\SmsMessage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsController extends Controller
{
    /**
     * Gère les SMS entrants depuis l'application Android (MacroDroid).
     */
    public function handleIncomingSms(Request $request)
    {
        // ── 1. Vérification du secret webhook ──────────────────────────
        $configuredSecret = config('sms.webhook_secret');

        if ($configuredSecret) {
            $receivedSecret = $request->header('X-SMS-Webhook-Secret');
            if (!$receivedSecret || !hash_equals($configuredSecret, $receivedSecret)) {
                Log::warning('SMS Webhook: Secret invalide reçu.');
                return response('Non autorisé', 401);
            }
        }

        // ── 2. Nettoyage et validation du payload ───────────────────────
        $cleanFrom = preg_replace('/[^0-9+]/', '', $request->input('from', ''));
        $request->merge(['from' => $cleanFrom]);

        try {
            $validated = $request->validate([
                'from'    => 'required|string|min:8',
                'message' => 'required|string|max:1600',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('SMS Webhook: Validation échouée', $e->errors());
            return response('Payload invalide', 422);
        }

        // ── 3. Normalisation du numéro ──────────────────────────────────
        $phone   = $this->normalizePhoneNumber($validated['from']) ?? $validated['from'];
        $message = $validated['message'];

        // ── 4. Quota journalier et anti-doublon ────────────────────────
        $now = now();
        $fingerprint = hash('sha256', $phone.'|'.mb_strtolower(trim($message)));
        $dedupWindowSeconds = max(0, config('sms.dedup_window_seconds', 120));
        $dailyLimit = max(0, config('sms.daily_limit', 3));

        try {
            $quotaState = DB::transaction(function () use ($phone, $now, $fingerprint, $dedupWindowSeconds, $dailyLimit) {
                DB::table('users')->insertOrIgnore([
                    'phone_number' => $phone,
                    'daily_requests_count' => 0,
                    'daily_requests_date' => $now->toDateString(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $user = User::where('phone_number', $phone)->lockForUpdate()->firstOrFail();

                if (!$user->daily_requests_date || !$user->daily_requests_date->isSameDay($now)) {
                    $user->forceFill([
                        'daily_requests_count' => 0,
                        'daily_requests_date' => $now->toDateString(),
                        'last_sms_fingerprint' => null,
                        'last_sms_reply' => null,
                        'last_sms_received_at' => null,
                    ])->save();
                }

                if (
                    $user->last_sms_fingerprint === $fingerprint
                    && $user->last_sms_reply
                    && $user->last_sms_received_at
                    && $user->last_sms_received_at->greaterThanOrEqualTo($now->copy()->subSeconds($dedupWindowSeconds))
                ) {
                    return [
                        'status' => 'duplicate',
                        'reply' => $user->last_sms_reply,
                        'user' => $user,
                    ];
                }

                if ($dailyLimit > 0 && $user->daily_requests_count >= $dailyLimit) {
                    return [
                        'status' => 'quota',
                        'reply' => 'Quota journalier atteint. Reviens demain.',
                        'user' => $user,
                    ];
                }

                $user->forceFill([
                    'daily_requests_count' => $user->daily_requests_count + 1,
                    'daily_requests_date' => $now->toDateString(),
                    'last_sms_fingerprint' => $fingerprint,
                    'last_sms_received_at' => $now,
                    'updated_at' => $now,
                ])->save();

                return [
                    'status' => 'reserved',
                    'user' => $user,
                ];
            });
        } catch (\Throwable $e) {
            Log::error('SMS Webhook: erreur quota', ['message' => $e->getMessage(), 'phone' => $phone]);

            return response('Service temporairement indisponible.', 503)
                ->header('Content-Type', 'text/plain');
        }

        $user = $quotaState['user'];

        if ($quotaState['status'] === 'duplicate') {
            Log::info('SMS Webhook: SMS doublon ignoré, réponse mise en cache renvoyée.', ['phone' => $phone]);

            return response($quotaState['reply'])
                ->header('Content-Type', 'text/plain');
        }

        if ($quotaState['status'] === 'quota') {
            Log::info('SMS Webhook: quota journalier atteint.', ['phone' => $phone]);

            return response($quotaState['reply'])
                ->header('Content-Type', 'text/plain');
        }

        // ── 5. Enregistrement du message utilisateur (Mémoire) ─────────
        SmsMessage::create([
            'phone_number' => $phone,
            'role' => 'user',
            'content' => $message,
        ]);

        // ── 6. Récupération de l'historique (10 derniers messages) ─────
        $history = SmsMessage::where('phone_number', $phone)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->reverse() // Pour avoir l'ordre chronologique
            ->map(function ($msg) {
                return [
                    'role' => $msg->role,
                    'content' => $msg->content,
                ];
            })
            ->values()
            ->toArray();

        // ── 7. Appel au service AI ──────────────────────────────────────
        $aiUrl           = config('sms.ai_service_url', 'http://ai-service:8000/ask');
        $internalToken   = config('sms.ai_internal_token');
        $timeoutSeconds  = max(1, config('sms.ai_timeout_seconds', 20));

        try {
            $httpClient = Http::timeout($timeoutSeconds);
            if ($internalToken) {
                $httpClient = $httpClient->withHeaders(['X-AI-Internal-Token' => $internalToken]);
            }

            $aiResponse = $httpClient->post($aiUrl, [
                'messages' => $history, // On envoie tout l'historique !
            ]);

            if ($aiResponse->successful()) {
                $aiData = $aiResponse->json();
                $reply  = $aiData['answer'] ?? null;

                if ($reply && is_string($reply) && trim($reply) !== '') {
                    $reply = $this->truncateSmart(trim($reply), config('sms.max_response_length', 150));

                    // Enregistrement de la réponse de l'IA (Mémoire)
                    SmsMessage::create([
                        'phone_number' => $phone,
                        'role' => 'assistant',
                        'content' => $reply,
                    ]);

                    $user->forceFill(['last_sms_reply' => $reply])->save();

                    Log::info('SMS AI réponse envoyée avec mémoire', ['phone' => $phone]);
                    return response($reply)->header('Content-Type', 'text/plain');
                }
            }

            Log::error('SMS AI: Réponse invalide ou vide', [
                'status' => $aiResponse->status(),
                'body'   => $aiResponse->body(),
            ]);

        } catch (\Throwable $e) {
            Log::error('SMS AI: Exception', ['message' => $e->getMessage()]);
        }

        $user->forceFill([
            'daily_requests_count' => max(0, $user->daily_requests_count - 1),
            'last_sms_reply' => null,
        ])->save();

        return response('Service temporairement indisponible.', 503)
            ->header('Content-Type', 'text/plain');
    }

    /**
     * Normalise un numéro de téléphone en format E.164 simplifié.
     */
    private function normalizePhoneNumber(string $rawPhone): ?string
    {
        $trimmed      = trim($rawPhone);
        $hasPlusPrefix = str_starts_with($trimmed, '+');
        $digitsOnly   = preg_replace('/\D+/', '', $trimmed) ?? '';

        if ($digitsOnly === '' || strlen($digitsOnly) < 8 || strlen($digitsOnly) > 15) {
            return null;
        }

        return $hasPlusPrefix ? '+' . $digitsOnly : $digitsOnly;
    }

    /**
     * Tronque au dernier espace avant la limite pour éviter de couper un mot.
     */
    private function truncateSmart(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        $safeLength = max(4, $maxLength);
        $truncated = mb_substr($text, 0, $safeLength - 3);
        $lastSpace = mb_strrpos($truncated, ' ');

        if ($lastSpace !== false && $lastSpace > intdiv($safeLength, 2)) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }

        return $truncated.'...';
    }
}
