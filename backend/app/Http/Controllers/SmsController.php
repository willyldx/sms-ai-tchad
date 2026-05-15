<?php

namespace App\Http\Controllers;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class SmsController extends Controller
{
    /**
     * Gère les SMS entrants depuis l'application Android Gateway.
     *
     * Flux :
     * 1. Vérification du webhook secret
     * 2. Validation du payload (from, message)
     * 3. Déduplication + contrôle quota (transaction DB avec verrou)
     * 4. Appel au microservice IA (FastAPI/Gemini)
     * 5. Retour de la réponse à la gateway Android
     */
    public function handleIncomingSms(Request $request): JsonResponse
    {
        // ── 1. Vérification du secret webhook ──────────────────────────
        $configuredSecret = config('sms.webhook_secret');

        if (app()->environment('production') && !$configuredSecret) {
            Log::critical('SMS webhook secret is missing in production environment.');
            return response()->json(['error' => 'Service indisponible'], 503);
        }

        if ($configuredSecret) {
            $receivedSecret = $request->header('X-SMS-Webhook-Secret');
            if (!$receivedSecret || !hash_equals($configuredSecret, $receivedSecret)) {
                return response()->json(['error' => 'Non autorisé'], 401);
            }
        }

        // ── 2. Validation du payload ───────────────────────────────────
        // 1. Logging pour debug (visible dans docker logs)
        \Illuminate\Support\Facades\Log::info('SMS Webhook Received', [
            'payload' => $request->all(),
            'headers' => $request->headers->all()
        ]);

        // 2. Nettoyage et validation souple
        $rawFrom = $request->input('from', '');
        // On ne garde que les chiffres et le +
        $cleanFrom = preg_replace('/[^0-9+]/', '', $rawFrom);

        // On réinjecte le numéro nettoyé pour la validation
        $request->merge(['from' => $cleanFrom]);

        try {
            $validated = $request->validate([
                'from' => 'nullable|string', // On laisse tout passer pour voir ce qui arrive
                'message' => 'nullable|string',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['error' => 'Validation failed', 'details' => $e->errors(), 'raw' => $request->all()], 422);
        }

        // 3. Log du contenu brut pour comprendre
        \Illuminate\Support\Facades\Log::info('RAW REQUEST DATA', $request->all());

        $phone = $this->normalizePhoneNumber($validated['from']);
        if (!$phone) {
            // Si la normalisation échoue, on garde quand même le numéro brut nettoyé au lieu de bloquer
            $phone = $cleanFrom;
        }

        $message = trim($validated['message']);
        if ($message === '') {
            return response()->json(['error' => 'Message vide'], 422);
        }

        // ── 3. Déduplication + quota (transaction avec verrou) ─────────
        $today = Carbon::today()->toDateString();
        $now = Carbon::now();
        $dailyLimit = config('sms.daily_limit');
        $maxSmsLength = config('sms.max_response_length');
        $dedupWindowSeconds = config('sms.dedup_window_seconds');
        $messageFingerprint = hash('sha256', mb_strtolower($message));
        $quotaReserved = false;
        $userId = null;
        $phoneHash = hash('sha256', $phone);

        try {
            $lockResult = DB::transaction(function () use (
                $phone,
                $today,
                $messageFingerprint,
                $dedupWindowSeconds,
                $now,
                $dailyLimit
            ) {
                // INSERT IGNORE pour créer l'utilisateur sans race condition.
                // Si le numéro existe déjà, l'insert est ignoré silencieusement.
                DB::table('users')->insertOrIgnore([
                    'phone_number' => $phone,
                    'daily_requests_count' => 0,
                    'daily_requests_date' => $today,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                // Maintenant on verrouille la ligne (elle existe forcément)
                $user = User::where('phone_number', $phone)->lockForUpdate()->firstOrFail();

                // Reset quotidien si la date a changé
                if ($user->daily_requests_date?->toDateString() !== $today) {
                    $user->daily_requests_count = 0;
                    $user->daily_requests_date = $today;
                }

                // Détection de doublon (même SMS dans la fenêtre de dédup)
                $duplicateWindowStart = $now->copy()->subSeconds($dedupWindowSeconds);
                if (
                    $user->last_sms_fingerprint
                    && $user->last_sms_fingerprint === $messageFingerprint
                    && $user->last_sms_received_at
                    && $user->last_sms_received_at->greaterThanOrEqualTo($duplicateWindowStart)
                ) {
                    return [
                        'status' => 'duplicate',
                        'reply' => $user->last_sms_reply ?: 'Requête déjà traitée récemment.',
                        'user_id' => $user->id,
                    ];
                }

                // Contrôle du quota journalier
                if ($user->daily_requests_count >= $dailyLimit) {
                    return [
                        'status' => 'quota',
                        'reply' => 'Quota quotidien atteint. Réessayez demain.',
                        'user_id' => $user->id,
                    ];
                }

                // Réservation du quota
                $user->daily_requests_count += 1;
                $user->last_sms_fingerprint = $messageFingerprint;
                $user->last_sms_reply = null;
                $user->last_sms_received_at = $now;
                $user->save();

                return [
                    'status' => 'reserved',
                    'user_id' => $user->id,
                ];
            });

            if (in_array($lockResult['status'] ?? null, ['duplicate', 'quota'])) {
                return response()->json(['reply' => $lockResult['reply']]);
            }

            $quotaReserved = ($lockResult['status'] ?? null) === 'reserved';
            $userId = $lockResult['user_id'] ?? null;
        } catch (\Throwable $e) {
            Log::error('Erreur verrouillage quota SMS', [
                'error' => $e->getMessage(),
                'phone_hash' => $phoneHash,
            ]);

            return response()->json([
                'reply' => 'Désolé, le service est temporairement indisponible.',
            ], 503);
        }

        // ── 4. Appel au microservice IA ────────────────────────────────
        try {
            $pythonServiceUrl = config('sms.ai_service_url');
            $timeoutSeconds = config('sms.ai_timeout_seconds');
            $requestId = (string) Str::uuid();
            $internalToken = config('sms.ai_internal_token');

            $httpClient = Http::retry(2, 300)
                ->timeout($timeoutSeconds)
                ->acceptJson()
                ->withHeaders([
                    'X-Request-Id' => $requestId,
                ]);

            if ($internalToken) {
                $httpClient = $httpClient->withHeaders([
                    'X-AI-Internal-Token' => $internalToken,
                ]);
            }

            $response = $httpClient->post($pythonServiceUrl, [
                'question' => $message,
            ]);

            if ($response->successful() && is_string($response->json('answer'))) {
                $aiAnswer = trim($response->json('answer'));
                if ($aiAnswer === '') {
                    throw new \RuntimeException('Réponse IA vide.');
                }

                // Troncature intelligente (coupe au dernier espace, jamais en plein mot)
                if (mb_strlen($aiAnswer) > $maxSmsLength) {
                    $aiAnswer = $this->truncateSmart($aiAnswer, $maxSmsLength);
                }

                if ($userId) {
                    User::whereKey($userId)->update([
                        'last_sms_fingerprint' => $messageFingerprint,
                        'last_sms_reply' => $aiAnswer,
                        'last_sms_received_at' => $now,
                    ]);
                }

                return response()->json(['reply' => $aiAnswer]);
            }

            throw new \RuntimeException('Réponse IA invalide ou indisponible.');

        } catch (\Exception $e) {
            Log::error('Erreur SMS AI', [
                'error' => $e->getMessage(),
                'phone_hash' => $phoneHash,
                'message_length' => mb_strlen($message),
                'request_id' => $requestId ?? null,
            ]);

            // Rollback du quota si l'IA a planté
            if ($quotaReserved && $userId) {
                User::whereKey($userId)
                    ->where('daily_requests_count', '>', 0)
                    ->decrement('daily_requests_count');

                User::whereKey($userId)
                    ->where('last_sms_fingerprint', $messageFingerprint)
                    ->update([
                        'last_sms_fingerprint' => null,
                        'last_sms_received_at' => null,
                    ]);
            }

            return response()->json([
                'reply' => 'Désolé, le service est temporairement indisponible.',
            ]);
        }
    }

    /**
     * Tronque un texte au dernier espace avant la limite max,
     * pour ne jamais couper un mot en plein milieu.
     */
    private function truncateSmart(string $text, int $maxLength): string
    {
        $safeLength = max(4, $maxLength);
        $truncated = mb_substr($text, 0, $safeLength - 3);

        // Chercher le dernier espace pour ne pas couper un mot
        $lastSpace = mb_strrpos($truncated, ' ');
        if ($lastSpace !== false && $lastSpace > $safeLength / 2) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }

        return $truncated . '...';
    }

    /**
     * Normalise un numéro de téléphone en gardant le format E.164 simplifié.
     */
    private function normalizePhoneNumber(string $rawPhone): ?string
    {
        $trimmed = trim($rawPhone);
        $hasPlusPrefix = str_starts_with($trimmed, '+');
        $digitsOnly = preg_replace('/\D+/', '', $trimmed) ?? '';

        if ($digitsOnly === '' || strlen($digitsOnly) < 8 || strlen($digitsOnly) > 15) {
            return null;
        }

        return $hasPlusPrefix ? '+' . $digitsOnly : $digitsOnly;
    }
}
