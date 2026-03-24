<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\UserBudget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class BudgetAlertService
{
    public function __construct(
        private readonly FirebasePushService $pushService
    ) {
    }

    public function sendForTransaction(Transaction $transaction, bool $force = false): array
    {
        $transactionDate = $transaction->transaction_date instanceof Carbon
            ? $transaction->transaction_date
            : Carbon::parse($transaction->transaction_date);

        $budget = UserBudget::query()
            ->with(['user.notificationPreference'])
            ->where('user_id', $transaction->user_id)
            ->where('month', $transactionDate->month)
            ->where('year', $transactionDate->year)
            ->first();

        if ($budget === null) {
            return ['status' => 'no_budget'];
        }

        return $this->sendForBudget($budget, $force);
    }

    public function sendForBudget(UserBudget $budget, bool $force = false): array
    {
        $user = $budget->relationLoaded('user')
            ? $budget->user
            : $budget->user()->with('notificationPreference')->first();

        if ($user === null || (float) $budget->limit <= 0.0) {
            return [
                'status' => 'no_user_or_limit',
                'sent' => 0,
                'failed' => 0,
                'skipped' => false,
                'threshold' => null,
                'used_percent' => null,
                'expense' => null,
            ];
        }

        $periodStart = Carbon::create((int) $budget->year, (int) $budget->month, 1)->startOfMonth();
        $periodEnd = $periodStart->copy()->endOfMonth();
        $expense = $this->calculateExpense((string) $user->id, $periodStart, $periodEnd);
        $usedPercent = round(($expense / (float) $budget->limit) * 100, 2);
        $threshold = $this->resolveThreshold($usedPercent);

        if ($threshold === null) {
            return [
                'status' => 'no_threshold',
                'sent' => 0,
                'failed' => 0,
                'skipped' => false,
                'threshold' => null,
                'used_percent' => $usedPercent,
                'expense' => $expense,
            ];
        }

        $cacheKey = sprintf(
            'budget-alert:%s:%04d-%02d:%d',
            $user->id,
            (int) $budget->year,
            (int) $budget->month,
            $threshold
        );

        if (! $force && Cache::has($cacheKey)) {
            return [
                'status' => 'cache_hit',
                'sent' => 0,
                'failed' => 0,
                'skipped' => false,
                'threshold' => $threshold,
                'used_percent' => $usedPercent,
                'expense' => $expense,
            ];
        }

        $result = $this->pushService->sendToUser(
            $user,
            'Peringatan Anggaran',
            sprintf('Pemakaian anggaran kamu sudah %s%% bulan ini.', $usedPercent),
            [
                'month' => (int) $budget->month,
                'year' => (int) $budget->year,
                'threshold' => $threshold,
                'used_percent' => $usedPercent,
                'limit' => (float) $budget->limit,
                'used' => round($expense, 2),
            ],
            'budget_alert'
        );

        if (($result['sent'] ?? 0) > 0) {
            Cache::put($cacheKey, true, $periodEnd->copy()->endOfDay());

            return [
                'status' => 'sent',
                'sent' => (int) ($result['sent'] ?? 0),
                'failed' => (int) ($result['failed'] ?? 0),
                'skipped' => false,
                'threshold' => $threshold,
                'used_percent' => $usedPercent,
                'expense' => $expense,
            ];
        }

        if (($result['skipped'] ?? false) === true) {
            return [
                'status' => 'skipped',
                'sent' => 0,
                'failed' => 0,
                'skipped' => true,
                'threshold' => $threshold,
                'used_percent' => $usedPercent,
                'expense' => $expense,
            ];
        }

        return [
            'status' => 'failed',
            'sent' => (int) ($result['sent'] ?? 0),
            'failed' => (int) ($result['failed'] ?? 0),
            'skipped' => false,
            'threshold' => $threshold,
            'used_percent' => $usedPercent,
            'expense' => $expense,
        ];
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
