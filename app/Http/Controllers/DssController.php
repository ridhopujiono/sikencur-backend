<?php

namespace App\Http\Controllers;

use App\Http\Requests\AnalyzeDssProfileRequest;
use App\Models\DssProfile;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\UserBudget;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class DssController extends Controller
{
    private const RULESET_VERSION = 'dss-v1';

    public function analyze(AnalyzeDssProfileRequest $request): JsonResponse
    {
        $userId = (string) auth()->id();
        $month = (int) $request->input('month', now()->month);
        $year = (int) $request->input('year', now()->year);
        $windowMonths = (int) $request->input('window_months', 6);

        $periodEnd = Carbon::create($year, $month, 1)->endOfMonth();

        if (now()->lt($periodEnd)) {
            $periodEnd = now()->copy()->endOfDay();
        }

        $periodStart = $periodEnd->copy()->startOfMonth()->subMonths($windowMonths - 1)->startOfMonth();

        $features = $this->buildFeatureSet($userId, $periodStart, $periodEnd, $windowMonths);
        $classification = $this->classify($features);

        $profile = DssProfile::query()->updateOrCreate(
            ['user_id' => $userId],
            [
                'profile_key' => $classification['profile_key'],
                'profile_label' => $classification['profile_label'],
                'confidence' => $classification['confidence'],
                'window_months' => $windowMonths,
                'features' => $features,
                'scores' => $classification['scores'],
                'reasons' => $classification['reasons'],
                'ruleset_version' => self::RULESET_VERSION,
                'analyzed_at' => now(),
            ]
        );

        return response()->json([
            'data' => $this->toProfilePayload($profile),
            'period' => [
                'start_date' => $periodStart->toDateString(),
                'end_date' => $periodEnd->toDateString(),
            ],
            'is_stale' => false,
            'analyze_required' => false,
        ]);
    }

    public function profile(): JsonResponse
    {
        $profile = DssProfile::query()
            ->where('user_id', auth()->id())
            ->first();

        if ($profile === null) {
            return response()->json([
                'data' => null,
                'is_stale' => false,
                'analyze_required' => true,
            ]);
        }

        $latestTransactionAt = Transaction::query()
            ->where('user_id', auth()->id())
            ->max('updated_at');

        $isStale = $latestTransactionAt !== null
            && Carbon::parse($latestTransactionAt)->gt($profile->analyzed_at);

        return response()->json([
            'data' => $this->toProfilePayload($profile),
            'is_stale' => $isStale,
            'analyze_required' => $isStale,
            'latest_transaction_at' => $latestTransactionAt,
        ]);
    }

    private function toProfilePayload(DssProfile $profile): array
    {
        return [
            'profile_key' => $profile->profile_key,
            'profile_label' => $profile->profile_label,
            'confidence' => (float) $profile->confidence,
            'window_months' => $profile->window_months,
            'features' => $profile->features,
            'scores' => $profile->scores,
            'reasons' => $profile->reasons,
            'ruleset_version' => $profile->ruleset_version,
            'analyzed_at' => optional($profile->analyzed_at)->toDateTimeString(),
        ];
    }

    private function buildFeatureSet(string $userId, Carbon $periodStart, Carbon $periodEnd, int $windowMonths): array
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
        $investmentCategories = ['investasi', 'pendapatan investasi', 'reksadana', 'saham', 'obligasi', 'deposito', 'crypto'];
        $savingsCategories = ['tabungan', 'investasi', 'pendapatan investasi', 'deposito'];
        $debtCategories = ['cicilan & utang', 'utang', 'kartu kredit', 'bunga'];
        $discretionaryCategories = ['hiburan', 'belanja', 'langganan', 'liburan', 'perawatan diri'];
        $transferCategories = ['transfer antar rekening', 'top up e-wallet'];

        $transactionCount = (int) Transaction::query()
            ->where('user_id', $userId)
            ->whereBetween('transaction_date', [$periodStart, $periodEnd])
            ->count();

        $days = max(1, $periodStart->diffInDays($periodEnd) + 1);
        $transactionFreq = $transactionCount / $days;

        $itemRows = TransactionItem::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->where('transactions.user_id', $userId)
            ->whereBetween('transactions.transaction_date', [$periodStart, $periodEnd])
            ->get([
                'transaction_items.category',
                'transaction_items.price',
                'transactions.transaction_date',
            ]);

        $totalIncome = 0.0;
        $totalExpense = 0.0;
        $discretionaryExpense = 0.0;
        $savingsAmount = 0.0;
        $investmentAmount = 0.0;
        $debtAmount = 0.0;
        $monthlyExpense = [];
        $monthlyIncome = [];
        $monthlyInvestment = [];

        foreach ($itemRows as $row) {
            $amount = (float) $row->price;
            $category = strtolower(trim((string) ($row->category ?? '')));
            $monthKey = Carbon::parse($row->transaction_date)->format('Y-m');

            if (in_array($category, $transferCategories, true)) {
                continue;
            }

            if (in_array($category, $incomeCategories, true)) {
                $totalIncome += $amount;
                $monthlyIncome[$monthKey] = ($monthlyIncome[$monthKey] ?? 0.0) + $amount;
            } else {
                $totalExpense += $amount;
                $monthlyExpense[$monthKey] = ($monthlyExpense[$monthKey] ?? 0.0) + $amount;

                if (in_array($category, $discretionaryCategories, true)) {
                    $discretionaryExpense += $amount;
                }

                if (in_array($category, $debtCategories, true)) {
                    $debtAmount += $amount;
                }
            }

            if (in_array($category, $savingsCategories, true)) {
                $savingsAmount += $amount;
            }

            if (in_array($category, $investmentCategories, true)) {
                $investmentAmount += $amount;
                $monthlyInvestment[$monthKey] = ($monthlyInvestment[$monthKey] ?? 0.0) + $amount;
            }
        }

        $monthlyKeys = $this->buildMonthlyKeys($periodStart, $periodEnd);
        $expenseSeries = [];
        $negativeBalanceMonths = 0;

        foreach ($monthlyKeys as $monthKey) {
            $expense = $monthlyExpense[$monthKey] ?? 0.0;
            $income = $monthlyIncome[$monthKey] ?? 0.0;

            $expenseSeries[] = $expense;

            if (($income > 0 && $expense > $income) || ($income <= 0 && $expense > 0)) {
                $negativeBalanceMonths++;
            }
        }

        $expenseVariance = $this->calculateCoefficientOfVariation($expenseSeries);
        $burnRate = $totalIncome > 0 ? ($totalExpense / $totalIncome) : ($totalExpense > 0 ? 1.5 : 0.0);
        $discretionaryRatio = $totalExpense > 0 ? ($discretionaryExpense / $totalExpense) : 0.0;
        $savingsRatio = $totalIncome > 0 ? (($totalIncome - $totalExpense) / $totalIncome) : ($totalExpense > 0 ? -1.0 : 0.0);
        $investmentRatio = $totalIncome > 0 ? ($investmentAmount / $totalIncome) : 0.0;
        $debtRatio = $totalIncome > 0 ? ($debtAmount / $totalIncome) : ($debtAmount > 0 ? 1.0 : 0.0);
        $investmentConsistency = $windowMonths > 0 ? (count($monthlyInvestment) / $windowMonths) : 0.0;
        $budgetAdherence = $this->calculateBudgetAdherence($userId, $monthlyExpense, $periodStart, $periodEnd);

        return [
            'monthly_burn_rate' => round($burnRate, 4),
            'discretionary_ratio' => round($discretionaryRatio, 4),
            'transaction_freq' => round($transactionFreq, 4),
            'savings_amount' => round($savingsAmount, 2),
            'budget_adherence' => $budgetAdherence,
            'savings_ratio' => round($savingsRatio, 4),
            'investment_ratio' => round($investmentRatio, 4),
            'debt_ratio' => round($debtRatio, 4),
            'expense_variance' => round($expenseVariance, 4),
            'negative_balance_months' => $negativeBalanceMonths,
            'investment_consistency' => round($investmentConsistency, 4),
            'window_months' => $windowMonths,
            'total_income' => round($totalIncome, 2),
            'total_expense' => round($totalExpense, 2),
        ];
    }

    private function calculateBudgetAdherence(string $userId, array $monthlyExpense, Carbon $periodStart, Carbon $periodEnd): ?float
    {
        $budgets = UserBudget::query()
            ->where('user_id', $userId)
            ->whereBetween('year', [(int) $periodStart->year, (int) $periodEnd->year])
            ->get(['month', 'year', 'limit']);

        $filteredBudgets = $budgets->filter(function ($budget) use ($periodStart, $periodEnd): bool {
            $budgetMonth = Carbon::create((int) $budget->year, (int) $budget->month, 1)->startOfMonth();

            return $budgetMonth->between(
                $periodStart->copy()->startOfMonth(),
                $periodEnd->copy()->startOfMonth()
            );
        })->values();

        $count = $filteredBudgets->count();

        if ($count === 0) {
            return null;
        }

        $withinBudget = 0;

        foreach ($filteredBudgets as $budget) {
            $key = sprintf('%04d-%02d', (int) $budget->year, (int) $budget->month);
            $expense = (float) ($monthlyExpense[$key] ?? 0.0);

            if ($expense <= (float) $budget->limit) {
                $withinBudget++;
            }
        }

        return round($withinBudget / $count, 4);
    }

    private function classify(array $features): array
    {
        $scores = [
            'saver' => 0,
            'spender' => 0,
            'investor' => 0,
            'debtor' => 0,
            'balanced' => 0,
        ];

        $reasons = [
            'saver' => [],
            'spender' => [],
            'investor' => [],
            'debtor' => [],
            'balanced' => [],
        ];

        $addScore = function (string $type, int $points, string $reason) use (&$scores, &$reasons): void {
            $scores[$type] += $points;
            $reasons[$type][] = $reason;
        };

        $savingsRatio = (float) ($features['savings_ratio'] ?? 0.0);
        $expenseVariance = (float) ($features['expense_variance'] ?? 0.0);
        $budgetAdherence = $features['budget_adherence'];
        $discretionaryRatio = (float) ($features['discretionary_ratio'] ?? 0.0);
        $burnRate = (float) ($features['monthly_burn_rate'] ?? 0.0);
        $investmentRatio = (float) ($features['investment_ratio'] ?? 0.0);
        $investmentConsistency = (float) ($features['investment_consistency'] ?? 0.0);
        $debtRatio = (float) ($features['debt_ratio'] ?? 0.0);
        $negativeBalanceMonths = (int) ($features['negative_balance_months'] ?? 0);
        $windowMonths = max(1, (int) ($features['window_months'] ?? 1));

        if ($savingsRatio > 0.30) {
            $addScore('saver', 3, 'Rasio tabungan tinggi di atas 30%.');
        }
        if ($expenseVariance <= 0.20) {
            $addScore('saver', 2, 'Variansi pengeluaran bulanan rendah.');
        }
        if ($budgetAdherence !== null && $budgetAdherence >= 0.75) {
            $addScore('saver', 2, 'Kepatuhan anggaran tinggi.');
        }
        if ($discretionaryRatio <= 0.30) {
            $addScore('saver', 1, 'Porsi belanja keinginan relatif rendah.');
        }

        if ($savingsRatio < 0.05) {
            $addScore('spender', 3, 'Rasio tabungan sangat rendah.');
        }
        if ($discretionaryRatio >= 0.45) {
            $addScore('spender', 2, 'Pengeluaran kategori sekunder mendominasi.');
        }
        if ($budgetAdherence !== null && $budgetAdherence < 0.50) {
            $addScore('spender', 2, 'Sering melebihi anggaran.');
        }
        if ($burnRate >= 0.95) {
            $addScore('spender', 1, 'Laju pembakaran pendapatan sangat tinggi.');
        }

        if ($investmentRatio >= 0.20) {
            $addScore('investor', 3, 'Proporsi alokasi investasi tinggi.');
        }
        if ((float) ($features['savings_amount'] ?? 0.0) > 0) {
            $addScore('investor', 1, 'Ada alokasi rutin ke tabungan/investasi.');
        }
        if ($investmentConsistency >= 0.50) {
            $addScore('investor', 2, 'Aktivitas investasi cukup konsisten lintas bulan.');
        }
        if ($savingsRatio >= 0.10) {
            $addScore('investor', 1, 'Ada ruang tabungan setelah pengeluaran.');
        }

        if ($burnRate > 1.0) {
            $addScore('debtor', 3, 'Pengeluaran melebihi pemasukan.');
        }
        if ($debtRatio >= 0.20) {
            $addScore('debtor', 2, 'Porsi pembayaran utang/cicilan tinggi.');
        }
        if ($negativeBalanceMonths >= (int) ceil($windowMonths / 2)) {
            $addScore('debtor', 2, 'Sering berada di neraca bulanan negatif.');
        }
        if ($budgetAdherence !== null && $budgetAdherence < 0.40) {
            $addScore('debtor', 1, 'Kepatuhan anggaran sangat rendah.');
        }

        if ($savingsRatio >= 0.10 && $savingsRatio <= 0.20) {
            $addScore('balanced', 3, 'Rasio tabungan berada pada rentang sehat.');
        }
        if ($discretionaryRatio >= 0.25 && $discretionaryRatio <= 0.45) {
            $addScore('balanced', 2, 'Komposisi kebutuhan dan keinginan cukup seimbang.');
        }
        if ($budgetAdherence !== null && $budgetAdherence >= 0.50 && $budgetAdherence <= 0.80) {
            $addScore('balanced', 2, 'Kepatuhan anggaran berada di level moderat.');
        }
        if ($expenseVariance <= 0.35) {
            $addScore('balanced', 1, 'Pola pengeluaran relatif stabil.');
        }

        arsort($scores);
        $topKey = (string) array_key_first($scores);
        $scoreValues = array_values($scores);
        $topScore = (float) ($scoreValues[0] ?? 0.0);
        $secondScore = (float) ($scoreValues[1] ?? 0.0);
        $confidence = $this->calculateConfidence($topScore, $secondScore);

        $labels = [
            'saver' => 'The Saver',
            'spender' => 'The Spender',
            'investor' => 'The Investor',
            'debtor' => 'The Debtor',
            'balanced' => 'The Balanced',
        ];

        return [
            'profile_key' => $topKey,
            'profile_label' => $labels[$topKey] ?? 'The Balanced',
            'confidence' => $confidence,
            'scores' => $scores,
            'reasons' => array_slice($reasons[$topKey] ?: ['Data belum cukup kuat, gunakan profil sementara.'], 0, 4),
        ];
    }

    private function calculateConfidence(float $topScore, float $secondScore): float
    {
        if ($topScore <= 0) {
            return 0.2000;
        }

        $gap = max(0.0, $topScore - $secondScore);
        $confidence = 0.50 + (($gap / max(1.0, $topScore)) * 0.50);

        return (float) number_format(min($confidence, 0.95), 4, '.', '');
    }

    private function buildMonthlyKeys(Carbon $periodStart, Carbon $periodEnd): array
    {
        $keys = [];
        $cursor = $periodStart->copy()->startOfMonth();
        $end = $periodEnd->copy()->startOfMonth();

        while ($cursor->lte($end)) {
            $keys[] = $cursor->format('Y-m');
            $cursor->addMonth();
        }

        return $keys;
    }

    private function calculateCoefficientOfVariation(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        $mean = array_sum($values) / count($values);

        if ($mean <= 0) {
            return 0.0;
        }

        $variance = 0.0;

        foreach ($values as $value) {
            $variance += ($value - $mean) ** 2;
        }

        $variance /= count($values);
        $stdDev = sqrt($variance);

        return $stdDev / $mean;
    }
}
