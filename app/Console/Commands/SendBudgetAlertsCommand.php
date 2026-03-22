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
    protected $signature = 'notifications:budget-alerts {--month=} {--year=}';

    protected $description = 'Send budget threshold alerts (80%, 100%, 120%) to users.';

    public function handle(FirebasePushService $pushService): int
    {
        $month = (int) ($this->option('month') ?: now()->month);
        $year = (int) ($this->option('year') ?: now()->year);
        $periodStart = Carbon::create($year, $month, 1)->startOfMonth();
        $periodEnd = $periodStart->copy()->endOfMonth();

        $budgets = UserBudget::query()
            ->with(['user.notificationPreference'])
            ->where('month', $month)
            ->where('year', $year)
            ->get();

        $sentCount = 0;

        foreach ($budgets as $budget) {
            $user = $budget->user;

            if ($user === null || (float) $budget->limit <= 0.0) {
                continue;
            }

            $expense = $this->calculateExpense((string) $user->id, $periodStart, $periodEnd);
            $usedPercent = round(($expense / (float) $budget->limit) * 100, 2);
            $threshold = $this->resolveThreshold($usedPercent);

            if ($threshold === null) {
                continue;
            }

            $cacheKey = sprintf('budget-alert:%s:%04d-%02d:%d', $user->id, $year, $month, $threshold);

            if (Cache::has($cacheKey)) {
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
            }
        }

        $this->info("Budget alerts sent: {$sentCount}");

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
