<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Exception\FirebaseException;
use Kreait\Firebase\Exception\MessagingException;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Throwable;

class FirebasePushService
{
    public function sendToUser(
        User $user,
        string $title,
        string $body,
        array $data = [],
        ?string $notificationType = null
    ): array {
        $preference = $user->notificationPreference;

        if ($preference !== null) {
            if (! $preference->push_enabled) {
                return ['sent' => 0, 'failed' => 0, 'skipped' => true];
            }

            if (! $this->isTypeEnabled($notificationType, $preference->toArray())) {
                return ['sent' => 0, 'failed' => 0, 'skipped' => true];
            }

            if ($this->isInQuietHours($preference->toArray())) {
                return ['sent' => 0, 'failed' => 0, 'skipped' => true];
            }
        }

        $tokens = UserDevice::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->pluck('fcm_token')
            ->unique()
            ->values()
            ->all();

        if ($tokens === []) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => true];
        }

        $messaging = $this->buildMessaging();

        if ($messaging === null) {
            return ['sent' => 0, 'failed' => count($tokens), 'skipped' => false];
        }

        $message = CloudMessage::new()
            ->withNotification(Notification::create($title, $body))
            ->withData($this->normalizeDataPayload($data + ['type' => $notificationType ?? 'general']));

        try {
            $report = $messaging->sendMulticast($message, $tokens);
            $invalidTokens = array_values(array_unique(array_merge($report->invalidTokens(), $report->unknownTokens())));

            if ($invalidTokens !== []) {
                UserDevice::query()
                    ->whereIn('fcm_token', $invalidTokens)
                    ->update([
                        'is_active' => false,
                    ]);
            }

            return [
                'sent' => $report->successes()->count(),
                'failed' => $report->failures()->count(),
                'skipped' => false,
            ];
        } catch (MessagingException|FirebaseException $exception) {
            Log::warning('Failed to send FCM notification.', [
                'user_id' => $user->id,
                'notification_type' => $notificationType,
                'message' => $exception->getMessage(),
            ]);

            return ['sent' => 0, 'failed' => count($tokens), 'skipped' => false];
        }
    }

    private function buildMessaging(): ?\Kreait\Firebase\Contract\Messaging
    {
        $serviceAccount = $this->resolveServiceAccountConfig();

        if ($serviceAccount === null) {
            return null;
        }

        $factory = (new Factory())->withServiceAccount($serviceAccount);
        $projectId = trim((string) config('services.firebase.project_id'));

        if ($projectId !== '') {
            $factory = $factory->withProjectId($projectId);
        }

        return $factory->createMessaging();
    }

    private function resolveServiceAccountConfig(): array|string|null
    {
        $credentialsPath = trim((string) config('services.firebase.credentials'));

        if ($credentialsPath !== '' && is_file($credentialsPath)) {
            return $credentialsPath;
        }

        $jsonString = trim((string) config('services.firebase.credentials_json'));

        if ($jsonString !== '') {
            $decoded = json_decode($jsonString, true);

            if (is_array($decoded)) {
                return $decoded;
            }

            Log::warning('Firebase credentials JSON in env is invalid.');
        }

        $base64String = trim((string) config('services.firebase.credentials_base64'));

        if ($base64String !== '') {
            $decodedBase64 = base64_decode($base64String, true);

            if ($decodedBase64 !== false) {
                $decodedJson = json_decode($decodedBase64, true);

                if (is_array($decodedJson)) {
                    return $decodedJson;
                }
            }

            Log::warning('Firebase credentials base64 in env is invalid.');
        }

        Log::warning('Firebase credentials are missing.', [
            'credentials_path' => $credentialsPath,
            'has_json' => $jsonString !== '',
            'has_base64' => $base64String !== '',
        ]);

        return null;
    }

    private function normalizeDataPayload(array $data): array
    {
        $normalized = [];

        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_scalar($value)) {
                $normalized[(string) $key] = (string) $value;

                continue;
            }

            $encoded = json_encode($value);

            if ($encoded !== false) {
                $normalized[(string) $key] = $encoded;
            }
        }

        return $normalized;
    }

    private function isTypeEnabled(?string $notificationType, array $preference): bool
    {
        return match ($notificationType) {
            'weekly_summary' => (bool) ($preference['weekly_summary_enabled'] ?? true),
            'budget_alert' => (bool) ($preference['budget_alert_enabled'] ?? true),
            'dss_tip' => (bool) ($preference['dss_tips_enabled'] ?? false),
            default => true,
        };
    }

    private function isInQuietHours(array $preference): bool
    {
        $quietStart = $preference['quiet_hours_start'] ?? null;
        $quietEnd = $preference['quiet_hours_end'] ?? null;

        if ($quietStart === null || $quietEnd === null) {
            return false;
        }

        $timezone = (string) ($preference['timezone'] ?? 'Asia/Jakarta');

        try {
            $now = Carbon::now($timezone);
            $start = Carbon::createFromFormat('H:i:s', substr((string) $quietStart, 0, 8), $timezone);
            $end = Carbon::createFromFormat('H:i:s', substr((string) $quietEnd, 0, 8), $timezone);
        } catch (Throwable) {
            return false;
        }

        if ($start->equalTo($end)) {
            return true;
        }

        if ($start->lessThan($end)) {
            return $now->betweenIncluded($start, $end);
        }

        return $now->greaterThanOrEqualTo($start) || $now->lessThanOrEqualTo($end);
    }
}
