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
     */
    public function handleIncomingSms(Request $request): JsonResponse
    {
        if (app()->environment('production') && !env('SMS_WEBHOOK_SECRET')) {
            Log::critical('SMS webhook secret is missing in production environment.');

            return response()->json(['error' => 'Service indisponible'], 503);
        }

        $configuredSecret = env('SMS_WEBHOOK_SECRET');
        if ($configuredSecret) {
            $receivedSecret = $request->header('X-SMS-Webhook-Secret');
            if (!$receivedSecret || !hash_equals($configuredSecret, $receivedSecret)) {
                return response()->json(['error' => 'Non autorisé'], 401);
            }
        }

        $validator = Validator::make($request->all(), [
            'from' => ['required', 'string', 'max:40'],
            'message' => ['required', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation échouée',
                'details' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        $phone = $this->normalizePhoneNumber($validated['from']);
        if (!$phone) {
            return response()->json(['error' => 'Numéro invalide'], 422);
        }

        $message = trim($validated['message']);
        if ($message === '') {
            return response()->json(['error' => 'Message vide'], 422);
        }

        $today = Carbon::today()->toDateString();
        $now = Carbon::now();
        $dailyLimit = (int) env('SMS_DAILY_LIMIT', 3);
        $maxSmsLength = (int) env('MAX_SMS_RESPONSE_LENGTH', 150);
        $dedupWindowSeconds = (int) env('SMS_DEDUP_WINDOW_SECONDS', 120);
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
                $user = User::where('phone_number', $phone)->lockForUpdate()->first();

                if (!$user) {
                    $user = User::firstOrCreate([
                        'phone_number' => $phone,
                    ], [
                        'daily_requests_count' => 0,
                        'daily_requests_date' => $today,
                    ]);
                    $user = User::whereKey($user->id)->lockForUpdate()->first();
                }

                if ($user->daily_requests_date !== $today) {
                    $user->daily_requests_count = 0;
                    $user->daily_requests_date = $today;
                }

                $duplicateWindowStart = $now->copy()->subSeconds($dedupWindowSeconds);
                if (
                    $user->last_sms_fingerprint
                    && $user->last_sms_fingerprint === $messageFingerprint
                    && $user->last_sms_received_at
                    && Carbon::parse($user->last_sms_received_at)->greaterThanOrEqualTo($duplicateWindowStart)
                ) {
                    $user->save();

                    return [
                        'status' => 'duplicate',
                        'reply' => $user->last_sms_reply ?: 'Requête déjà traitée récemment.',
                        'user_id' => $user->id,
                    ];
                }

                if ($user->daily_requests_count >= $dailyLimit) {
                    $user->save();

                    return [
                        'status' => 'quota',
                        'reply' => 'Quota quotidien atteint. Réessayez demain.',
                        'user_id' => $user->id,
                    ];
                }

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

            if (($lockResult['status'] ?? null) === 'duplicate' || ($lockResult['status'] ?? null) === 'quota') {
                return response()->json([
                    'reply' => $lockResult['reply'],
                ]);
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

        try {
            $pythonServiceUrl = env('PYTHON_AI_SERVICE_URL', 'http://localhost:8000/ask');
            $timeoutSeconds = (int) env('AI_HTTP_TIMEOUT_SECONDS', 20);
            $requestId = (string) Str::uuid();
            $internalToken = env('AI_INTERNAL_TOKEN');
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
            
            $response = $httpClient
                ->post($pythonServiceUrl, [
                    'question' => $message,
                ]);

            if ($response->successful() && is_string($response->json('answer'))) {
                $aiAnswer = trim($response->json('answer'));
                if ($aiAnswer === '') {
                    throw new \RuntimeException('Réponse IA vide.');
                }

                if (mb_strlen($aiAnswer) > $maxSmsLength) {
                    $safeLength = max(4, $maxSmsLength);
                    $aiAnswer = mb_substr($aiAnswer, 0, $safeLength - 3) . '...';
                }

                if ($userId) {
                    User::whereKey($userId)->update([
                        'last_sms_fingerprint' => $messageFingerprint,
                        'last_sms_reply' => $aiAnswer,
                        'last_sms_received_at' => $now,
                    ]);
                }

                return response()->json([
                    'reply' => $aiAnswer
                ]);
            }

            throw new \RuntimeException('Réponse IA invalide ou indisponible.');

        } catch (\Exception $e) {
            Log::error('Erreur SMS AI', [
                'error' => $e->getMessage(),
                'phone_hash' => $phoneHash ?? null,
                'message_length' => mb_strlen($message),
                'request_id' => $requestId ?? null,
            ]);

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
                'reply' => "Désolé, le service est temporairement indisponible."
            ]);
        }
    }

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
