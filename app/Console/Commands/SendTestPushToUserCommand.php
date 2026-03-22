<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\UserDevice;
use App\Services\FirebasePushService;
use Illuminate\Console\Command;

class SendTestPushToUserCommand extends Command
{
    protected $signature = 'notifications:test-user
        {email : Email user tujuan}
        {--title=Test Notifikasi : Judul notifikasi}
        {--body=Ini adalah notifikasi percobaan dari backend. : Isi notifikasi}
        {--type=manual_test : Tipe notifikasi pada payload}
        {--data=* : Data tambahan format key=value, bisa diulang}';

    protected $description = 'Send test push notification to a specific user by email.';

    public function handle(FirebasePushService $pushService): int
    {
        $email = trim((string) $this->argument('email'));

        $user = User::query()
            ->with('notificationPreference')
            ->where('email', $email)
            ->first();

        if ($user === null) {
            $this->error("User dengan email {$email} tidak ditemukan.");

            return self::FAILURE;
        }

        $activeTokenCount = UserDevice::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->count();

        if ($activeTokenCount === 0) {
            $this->warn("User {$email} belum punya device token aktif.");

            return self::FAILURE;
        }

        $title = (string) $this->option('title');
        $body = (string) $this->option('body');
        $type = (string) $this->option('type');
        $data = $this->parseDataOptions((array) $this->option('data'));

        $result = $pushService->sendToUser(
            $user,
            $title,
            $body,
            $data,
            $type
        );

        if (($result['skipped'] ?? false) === true) {
            $this->warn('Push tidak terkirim (kemungkinan preference user memblokir atau token tidak tersedia).');
            $this->line('Hasil: '.json_encode($result));

            return self::FAILURE;
        }

        $this->info("Push test selesai untuk {$email}.");
        $this->line('Hasil: '.json_encode($result));

        return (($result['sent'] ?? 0) > 0) ? self::SUCCESS : self::FAILURE;
    }

    private function parseDataOptions(array $pairs): array
    {
        $data = [];

        foreach ($pairs as $pair) {
            $raw = trim((string) $pair);

            if ($raw === '' || ! str_contains($raw, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $raw, 2);
            $key = trim($key);

            if ($key === '') {
                continue;
            }

            $data[$key] = trim($value);
        }

        return $data;
    }
}
