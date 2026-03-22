<?php

namespace App\Console\Commands;

use App\Models\DssProfile;
use App\Models\TransactionItem;
use App\Services\FirebasePushService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SendWeeklyDssSummaryCommand extends Command
{
    protected $signature = 'notifications:dss-weekly {--force}';

    protected $description = 'Send weekly DSS summary and DSS tips notifications.';

    public function handle(FirebasePushService $pushService): int
    {
        $now = now();
        $force = (bool) $this->option('force');
        $weekKey = $now->format('o-\WW');
        $periodEnd = $now->copy()->endOfDay();
        $periodStart = $now->copy()->subDays(6)->startOfDay();

        $profiles = DssProfile::query()
            ->with(['user.notificationPreference'])
            ->get();

        $sentCount = 0;

        foreach ($profiles as $profile) {
            $user = $profile->user;

            if ($user === null) {
                continue;
            }

            $summaryCacheKey = sprintf('weekly-summary:%s:%s', $user->id, $weekKey);

            if ($force || ! Cache::has($summaryCacheKey)) {
                $weeklyExpense = $this->calculateExpense((string) $user->id, $periodStart, $periodEnd);

                $summaryResult = $pushService->sendToUser(
                    $user,
                    'Ringkasan Mingguan',
                    sprintf(
                        'Pengeluaran 7 hari terakhir Rp %s. Profil finansial: %s.',
                        number_format($weeklyExpense, 0, ',', '.'),
                        $profile->profile_label
                    ),
                    [
                        'profile_key' => $profile->profile_key,
                        'profile_label' => $profile->profile_label,
                        'weekly_expense' => round($weeklyExpense, 2),
                    ],
                    'weekly_summary'
                );

                if (($summaryResult['sent'] ?? 0) > 0) {
                    Cache::put($summaryCacheKey, true, now()->addDays(8));
                    $sentCount++;
                }
            }

            $tipCacheKey = sprintf('weekly-dss-tip:%s:%s', $user->id, $weekKey);

            if (! $force && Cache::has($tipCacheKey)) {
                continue;
            }

            $tipResult = $pushService->sendToUser(
                $user,
                'Tips DSS Mingguan',
                $this->buildTipByProfile((string) $profile->profile_key),
                [
                    'profile_key' => $profile->profile_key,
                ],
                'dss_tip'
            );

            if (($tipResult['sent'] ?? 0) > 0) {
                Cache::put($tipCacheKey, true, now()->addDays(8));
                $sentCount++;
            }
        }

        $this->info("Weekly DSS notifications sent: {$sentCount}");

        return self::SUCCESS;
    }

    private function buildTipByProfile(string $profileKey): string
    {
        return match ($profileKey) {
            'saver' => 'Konsisten bagus. Coba alokasikan sebagian dana ke instrumen investasi berisiko rendah.',
            'spender' => 'Batasi kategori hiburan minggu depan dengan limit khusus supaya pengeluaran lebih terkendali.',
            'investor' => 'Pertahankan kebiasaan investasi. Pastikan dana darurat tetap aman minimal 3-6 bulan biaya hidup.',
            'debtor' => 'Prioritaskan pelunasan utang berbunga tinggi terlebih dulu untuk menurunkan beban finansial.',
            default => 'Kondisi finansial sudah cukup seimbang. Tetap review anggaran mingguan agar konsisten.',
        };
    }

    private function calculateExpense(string $userId, Carbon $periodStart, Carbon $periodEnd): float
    {
        $incomeCategories = [
            'gaji',
            'bonus & thr',
            'penghasilan freelance',
            'penghasilan usaha',
            'pendapatan investasi',
            'penghasilan lain',
            'refund/pengembalian dana',
        ];

        return (float) TransactionItem::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->where('transactions.user_id', $userId)
            ->whereBetween('transactions.transaction_date', [$periodStart, $periodEnd])
            ->where(function ($query) use ($incomeCategories): void {
                $query
                    ->whereNull('transaction_items.category')
                    ->orWhereNotIn(DB::raw('TRIM(LOWER(transaction_items.category))'), $incomeCategories);
            })
            ->sum('transaction_items.price');
    }
}
