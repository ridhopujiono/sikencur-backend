<?php

namespace App\Console\Commands;

use App\Models\TransactionItem;
use App\Models\UserBudget;
use App\Services\FirebasePushService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SendBudgetAlertsCommand extends Command
{
    protected $signature = 'notifications:budget-alerts {--month=} {--year=} {--force} {--debug}';

    protected $description = 'Send budget threshold alerts (80%, 100%, 120%) to users.';

    public function handle(FirebasePushService $pushService): int
    {
        $month = (int) ($this->option('month') ?: now()->month);
        $year = (int) ($this->option('year') ?: now()->year);
        $force = (bool) $this->option('force');
        $debug = (bool) $this->option('debug');
        $periodStart = Carbon::create($year, $month, 1)->startOfMonth();
        $periodEnd = $periodStart->copy()->endOfMonth();

        $budgets = UserBudget::query()
            ->with(['user.notificationPreference'])
            ->where('month', $month)
            ->where('year', $year)
            ->get();

        $sentCount = 0;
        $reasonCounters = [
            'sent' => 0,
            'no_user_or_limit' => 0,
            'no_threshold' => 0,
            'cache_hit' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        foreach ($budgets as $budget) {
            $user = $budget->user;

            if ($user === null || (float) $budget->limit <= 0.0) {
                $reasonCounters['no_user_or_limit']++;
                if ($debug) {
                    $this->line(sprintf(
                        '[SKIP] user=%s reason=no_user_or_limit limit=%s',
                        $user?->email ?? 'unknown',
                        (string) $budget->limit
                    ));
                }
                continue;
            }

            $expense = $this->calculateExpense((string) $user->id, $periodStart, $periodEnd);
            $usedPercent = round(($expense / (float) $budget->limit) * 100, 2);
            $threshold = $this->resolveThreshold($usedPercent);

            if ($threshold === null) {
                $reasonCounters['no_threshold']++;
                if ($debug) {
                    $this->line(sprintf(
                        '[SKIP] user=%s reason=no_threshold used_percent=%.2f',
                        (string) $user->email,
                        $usedPercent
                    ));
                }
                continue;
            }

            $cacheKey = sprintf('budget-alert:%s:%04d-%02d:%d', $user->id, $year, $month, $threshold);

            if (! $force && Cache::has($cacheKey)) {
                $reasonCounters['cache_hit']++;
                if ($debug) {
                    $this->line(sprintf(
                        '[SKIP] user=%s reason=cache_hit threshold=%d',
                        (string) $user->email,
                        $threshold
                    ));
                }
                continue;
            }

            $result = $pushService->sendToUser(
                $user,
                'Peringatan Anggaran',
                sprintf('Pemakaian anggaran kamu sudah %s%% bulan ini.', $usedPercent),
                [
                    'month' => $month,
                    'year' => $year,
                    'threshold' => $threshold,
                    'used_percent' => $usedPercent,
                    'limit' => (float) $budget->limit,
                    'used' => round($expense, 2),
                ],
                'budget_alert'
            );

            if (($result['sent'] ?? 0) > 0) {
                Cache::put($cacheKey, true, $periodEnd->copy()->endOfDay());
                $sentCount++;
                $reasonCounters['sent']++;

                if ($debug) {
                    $this->line(sprintf(
                        '[SENT] user=%s threshold=%d sent=%d failed=%d skipped=%s',
                        (string) $user->email,
                        $threshold,
                        (int) ($result['sent'] ?? 0),
                        (int) ($result['failed'] ?? 0),
                        ($result['skipped'] ?? false) ? 'true' : 'false'
                    ));
                }
                continue;
            }

            if (($result['skipped'] ?? false) === true) {
                $reasonCounters['skipped']++;
                if ($debug) {
                    $this->line(sprintf(
                        '[SKIP] user=%s reason=push_skipped_by_preferences_or_tokens threshold=%d',
                        (string) $user->email,
                        $threshold
                    ));
                }
                continue;
            }

            $reasonCounters['failed']++;

            if ($debug) {
                $this->line(sprintf(
                    '[FAIL] user=%s threshold=%d sent=%d failed=%d',
                    (string) $user->email,
                    $threshold,
                    (int) ($result['sent'] ?? 0),
                    (int) ($result['failed'] ?? 0)
                ));
            }
        }

        $this->info("Budget alerts sent: {$sentCount}");

        if ($debug) {
            $this->line('Budget alerts debug summary: '.json_encode($reasonCounters));
        }

        return self::SUCCESS;
    }

    private function resolveThreshold(float $usedPercent): ?int
    {
        if ($usedPercent >= 120) {
            return 120;
        }

        if ($usedPercent >= 100) {
            return 100;
        }

        if ($usedPercent >= 80) {
            return 80;
        }

        return null;
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
