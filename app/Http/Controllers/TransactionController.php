<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListTransactionsRequest;
use App\Http\Requests\ScanReceiptRequest;
use App\Http\Requests\StoreTransactionRequest;
use App\Http\Requests\TotalTransactionsRequest;
use App\Http\Requests\TransactionSummaryRequest;
use App\Http\Requests\UpdateTransactionRequest;
use App\Jobs\ProcessReceiptScan;
use App\Models\ReceiptScan;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\UserBudget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TransactionController extends Controller
{
    private const INCOME_CATEGORIES = [
        'Gaji',
        'Bonus & THR',
        'Penghasilan Freelance',
        'Penghasilan Usaha',
        'Pendapatan Investasi',
        'Penghasilan Lain',
        'Refund/Pengembalian Dana',
    ];

    public function index(ListTransactionsRequest $request): JsonResponse
    {
        $query = Transaction::query()
            ->with('items')
            ->where('user_id', auth()->id());

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));

            if ($search !== '') {
                $query->where(function (Builder $builder) use ($search): void {
                    $builder
                        ->where('merchant_name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhereHas('items', function (Builder $itemQuery) use ($search): void {
                            $itemQuery->where('item_name', 'like', "%{$search}%");
                        });
                });
            }
        }

        if ($request->filled('merchant_name')) {
            $query->where('merchant_name', 'like', '%'.$request->string('merchant_name')->toString().'%');
        }

        if ($request->filled('input_method')) {
            $query->where('input_method', $request->string('input_method')->toString());
        }

        if ($request->filled('category')) {
            $query->whereHas('items', function (Builder $itemQuery) use ($request): void {
                $itemQuery->where('category', $request->string('category')->toString());
            });
        }

        $query->when($request->transaction_type, function (Builder $builder, string $transactionType): void {
            $normalizedType = strtolower(trim($transactionType));

            if (! in_array($normalizedType, ['income', 'expense'], true)) {
                return;
            }

            $incomeCategories = $this->incomeCategoriesLower();

            $builder->whereHas('items', function (Builder $itemQuery) use ($normalizedType, $incomeCategories): void {
                if ($normalizedType === 'income') {
                    $itemQuery->whereIn(DB::raw('TRIM(LOWER(transaction_items.category))'), $incomeCategories);

                    return;
                }

                $itemQuery->where(function (Builder $expenseQuery) use ($incomeCategories): void {
                    $expenseQuery
                        ->whereNull('transaction_items.category')
                        ->orWhereNotIn(DB::raw('TRIM(LOWER(transaction_items.category))'), $incomeCategories);
                });
            });
        });

        if ($request->filled('min_total')) {
            $query->where('price_total', '>=', $request->input('min_total'));
        }

        if ($request->filled('max_total')) {
            $query->where('price_total', '<=', $request->input('max_total'));
        }

        $this->applyDateFilters($query, $request);

        $transactions = $query
            ->orderBy(
                $request->string('sort_by', 'transaction_date')->toString(),
                $request->string('sort_direction', 'desc')->toString()
            )
            ->paginate((int) $request->input('per_page', 100))
            ->appends($request->query());

        return response()->json($transactions);
    }

    public function summary(TransactionSummaryRequest $request): JsonResponse
    {
        $userId = (string) auth()->id();
        $month = (int) $request->input('month', now()->month);
        $year = (int) $request->input('year', now()->year);

        $periodStart = Carbon::create($year, $month, 1)->startOfMonth();
        $periodEnd = $periodStart->copy()->endOfMonth();
        $previousPeriodStart = $periodStart->copy()->subMonth()->startOfMonth();
        $previousPeriodEnd = $periodStart->copy()->subMonth()->endOfMonth();
        $periodEndForDaily = now()->lt($periodEnd) ? now() : $periodEnd->copy()->endOfDay();

        $currentMonthTotal = $this->calculateExpenseTotal($userId, $periodStart, $periodEnd);
        $previousMonthTotal = $this->calculateExpenseTotal($userId, $previousPeriodStart, $previousPeriodEnd);

        $monthlyTransactionCount = (int) Transaction::query()
            ->where('user_id', $userId)
            ->whereBetween('transaction_date', [$periodStart, $periodEnd])
            ->count();

        $scanStatusFilter = $request->string('scan_status', 'completed')->toString();
        $receiptScanQuery = ReceiptScan::query()
            ->where('user_id', $userId)
            ->whereBetween('created_at', [$periodStart, $periodEnd]);

        if ($scanStatusFilter !== 'all') {
            $receiptScanQuery->where('status', $scanStatusFilter);
        }

        $receiptScanCount = (int) $receiptScanQuery->count();
        $budgetSetting = UserBudget::query()
            ->where('user_id', $userId)
            ->where('month', $month)
            ->where('year', $year)
            ->first(['limit', 'target_remaining']);

        $comparison = $this->buildMonthComparison($currentMonthTotal, $previousMonthTotal);
        $budget = $this->buildBudgetSummary(
            $budgetSetting !== null ? (float) $budgetSetting->limit : null,
            $budgetSetting !== null && $budgetSetting->target_remaining !== null
                ? (float) $budgetSetting->target_remaining
                : null,
            $currentMonthTotal
        );
        $dailySpending = $this->buildSevenDaySpending($userId, $periodEndForDaily);
        $topCategories = $this->buildTopCategories(
            $userId,
            $periodStart,
            $periodEnd,
            (int) $request->input('top_categories', 4)
        );

        return response()->json([
            'period' => [
                'month' => $month,
                'year' => $year,
                'label' => $this->formatMonthYear($month, $year),
                'start_date' => $periodStart->toDateString(),
                'end_date' => $periodEnd->toDateString(),
            ],
            'summary' => [
                'total_expense' => round($currentMonthTotal, 2),
                'previous_month_total' => round($previousMonthTotal, 2),
                'comparison' => $comparison,
                'transaction_count' => $monthlyTransactionCount,
            ],
            'budget' => $budget,
            'receipt_scans' => [
                'count' => $receiptScanCount,
                'status_filter' => $scanStatusFilter,
            ],
            'seven_day_expense' => $dailySpending,
            'top_categories' => $topCategories,
            'largest_category' => $topCategories[0] ?? null,
        ]);
    }

    public function total(TotalTransactionsRequest $request): JsonResponse
    {
        $query = TransactionItem::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->where('transactions.user_id', auth()->id());

        if ($request->filled('date_from')) {
            $query->where('transactions.transaction_date', '>=', $request->string('date_from')->toString().' 00:00:00');
        }

        if ($request->filled('date_to')) {
            $query->where('transactions.transaction_date', '<=', $request->string('date_to')->toString().' 23:59:59');
        }

        if ($request->filled('category')) {
            $query->where('transaction_items.category', $request->string('category')->toString());
        }

        if ($request->filled('input_method')) {
            $query->where('transactions.input_method', $request->string('input_method')->toString());
        }

        $query->when($request->transaction_type, function (Builder $builder, string $transactionType): void {
            $normalizedType = strtolower(trim($transactionType));
            $incomeCategories = $this->incomeCategoriesLower();

            if ($normalizedType === 'income') {
                $builder->whereIn(DB::raw('TRIM(LOWER(transaction_items.category))'), $incomeCategories);

                return;
            }

            $builder->where(function (Builder $expenseQuery) use ($incomeCategories): void {
                $expenseQuery
                    ->whereNull('transaction_items.category')
                    ->orWhereNotIn(DB::raw('TRIM(LOWER(transaction_items.category))'), $incomeCategories);
            });
        });

        return response()->json([
            'total' => round((float) $query->sum('transaction_items.price'), 2),
        ]);
    }

    public function scanReceipt(ScanReceiptRequest $request): JsonResponse
    {
        $filePath = $request->file('image')->store('', 'receipts');

        $receiptScan = ReceiptScan::query()->create([
            'user_id' => auth()->id(),
            'file_path' => $filePath,
            'status' => 'pending',
        ]);

        ProcessReceiptScan::dispatch($receiptScan);

        return response()->json([
            'scan_id' => $receiptScan->id,
            'status' => $receiptScan->status,
        ], 202);
    }

    public function checkStatus(string $scan_id): JsonResponse
    {
        $receiptScan = ReceiptScan::query()
            ->where('id', $scan_id)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        return response()->json([
            'status' => $receiptScan->status,
            'data' => $receiptScan->result_data,
            'error_message' => $receiptScan->error_message,
        ]);
    }

    public function store(StoreTransactionRequest $request): JsonResponse
    {
        $transaction = DB::transaction(function () use ($request): Transaction {
            $transaction = Transaction::query()->create([
                'user_id' => auth()->id(),
                'merchant_name' => $request->validated('merchant_name'),
                'description' => $request->validated('description'),
                'price_total' => $request->validated('price_total'),
                'tax' => $request->validated('tax'),
                'service_charge' => $request->validated('service_charge'),
                'transaction_date' => $request->validated('transaction_date'),
                'input_method' => $request->validated('input_method'),
            ]);

            foreach ($request->validated('items') as $item) {
                $transaction->items()->create($item);
            }

            return $transaction->load('items');
        });

        return response()->json($transaction, 201);
    }

    public function update(string $transaction_id, UpdateTransactionRequest $request): JsonResponse
    {
        $transaction = Transaction::query()
            ->where('id', $transaction_id)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $transaction = DB::transaction(function () use ($transaction, $request): Transaction {
            $updatableFields = [
                'merchant_name',
                'description',
                'price_total',
                'tax',
                'service_charge',
                'transaction_date',
                'input_method',
            ];

            $attributes = [];

            foreach ($updatableFields as $field) {
                if ($request->exists($field)) {
                    $attributes[$field] = $request->input($field);
                }
            }

            if ($attributes !== []) {
                $transaction->fill($attributes);
                $transaction->save();
            }

            if ($request->has('items')) {
                $transaction->items()->delete();

                foreach ($request->validated('items') as $item) {
                    $transaction->items()->create($item);
                }
            }

            return $transaction->load('items');
        });

        return response()->json($transaction);
    }

    public function show(string $transaction_id): JsonResponse
    {
        $transaction = Transaction::query()
            ->with('items')
            ->where('id', $transaction_id)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        return response()->json($transaction);
    }

    private function applyDateFilters(Builder $query, ListTransactionsRequest $request): void
    {
        if ($request->filled('date_range')) {
            $now = now();
            $range = $request->string('date_range')->toString();

            $dateWindow = match ($range) {
                'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
                'this_week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
                'this_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
                'this_year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
                'last_7_days' => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()],
                'last_30_days' => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()],
                default => null,
            };

            if ($dateWindow !== null) {
                $query->whereBetween('transaction_date', $dateWindow);
            }
        }

        if ($request->filled('date_from')) {
            $query->where('transaction_date', '>=', $request->string('date_from')->toString().' 00:00:00');
        }

        if ($request->filled('date_to')) {
            $query->where('transaction_date', '<=', $request->string('date_to')->toString().' 23:59:59');
        }
    }

    private function buildMonthComparison(float $currentMonthTotal, float $previousMonthTotal): array
    {
        if ($previousMonthTotal <= 0.0) {
            if ($currentMonthTotal <= 0.0) {
                return [
                    'change_percent' => 0.0,
                    'direction' => 'stable',
                    'label' => '0%',
                ];
            }

            return [
                'change_percent' => null,
                'direction' => 'up',
                'label' => 'baru',
            ];
        }

        $changePercent = round((($currentMonthTotal - $previousMonthTotal) / $previousMonthTotal) * 100, 2);
        $prefix = $changePercent > 0 ? '+' : '';

        return [
            'change_percent' => $changePercent,
            'direction' => $changePercent > 0 ? 'up' : ($changePercent < 0 ? 'down' : 'stable'),
            'label' => $prefix.$changePercent.'%',
        ];
    }

    private function buildBudgetSummary(
        ?float $budgetLimit,
        ?float $targetRemaining,
        float $currentMonthTotal
    ): ?array
    {
        if ($budgetLimit === null) {
            return null;
        }

        $remaining = round($budgetLimit - $currentMonthTotal, 2);
        $usedPercent = $budgetLimit > 0
            ? round(($currentMonthTotal / $budgetLimit) * 100, 2)
            : null;

        return [
            'limit' => round($budgetLimit, 2),
            'used' => round($currentMonthTotal, 2),
            'remaining' => $remaining,
            'used_percent' => $usedPercent,
            'target_remaining' => $targetRemaining !== null ? round($targetRemaining, 2) : null,
            'planned_spend' => $targetRemaining !== null ? round($budgetLimit - $targetRemaining, 2) : null,
            'target_on_track' => $targetRemaining !== null ? $remaining >= $targetRemaining : null,
        ];
    }

    private function buildSevenDaySpending(string $userId, Carbon $periodEndForDaily): array
    {
        $dailyEnd = $periodEndForDaily->copy()->endOfDay();
        $dailyStart = $dailyEnd->copy()->subDays(6)->startOfDay();
        $dayLabels = [0 => 'Min', 1 => 'Sen', 2 => 'Sel', 3 => 'Rab', 4 => 'Kam', 5 => 'Jum', 6 => 'Sab'];
        $excludedCategories = $this->incomeCategoriesLower();

        $totalsByDate = TransactionItem::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->selectRaw('DATE(transactions.transaction_date) as tx_date, SUM(transaction_items.price) as total')
            ->where('transactions.user_id', $userId)
            ->whereBetween('transactions.transaction_date', [$dailyStart, $dailyEnd])
            ->where(function ($builder) use ($excludedCategories): void {
                $builder
                    ->whereNull('transaction_items.category')
                    ->orWhereNotIn(DB::raw('TRIM(LOWER(transaction_items.category))'), $excludedCategories);
            })
            ->groupBy(DB::raw('DATE(transactions.transaction_date)'))
            ->pluck('total', 'tx_date');

        $result = [];
        $cursor = $dailyStart->copy();

        for ($i = 0; $i < 7; $i++) {
            $date = $cursor->toDateString();

            $result[] = [
                'date' => $date,
                'day_label' => $dayLabels[$cursor->dayOfWeek],
                'total' => round((float) ($totalsByDate[$date] ?? 0), 2),
            ];

            $cursor->addDay();
        }

        return $result;
    }

    private function buildTopCategories(string $userId, Carbon $periodStart, Carbon $periodEnd, int $limit): array
    {
        $groupExpression = "COALESCE(NULLIF(transaction_items.category, ''), 'Tanpa Kategori')";

        $rows = TransactionItem::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->selectRaw($groupExpression.' as category_name')
            ->selectRaw('SUM(transaction_items.price) as total')
            ->where('transactions.user_id', $userId)
            ->whereBetween('transactions.transaction_date', [$periodStart, $periodEnd])
            ->groupBy(DB::raw($groupExpression))
            ->orderByDesc('total')
            ->limit($limit)
            ->get();

        $baseTotal = (float) $rows->sum(fn ($row): float => (float) $row->total);

        return $rows->map(function ($row) use ($baseTotal): array {
            $total = (float) $row->total;

            return [
                'category' => (string) $row->category_name,
                'total' => round($total, 2),
                'percentage' => $baseTotal > 0 ? round(($total / $baseTotal) * 100, 2) : 0.0,
            ];
        })->values()->all();
    }

    private function calculateExpenseTotal(string $userId, Carbon $periodStart, Carbon $periodEnd): float
    {
        $excludedCategories = $this->incomeCategoriesLower();

        return (float) TransactionItem::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->where('transactions.user_id', $userId)
            ->whereBetween('transactions.transaction_date', [$periodStart, $periodEnd])
            ->where(function (Builder $builder) use ($excludedCategories): void {
                $builder
                    ->whereNull('transaction_items.category')
                    ->orWhereNotIn(DB::raw('TRIM(LOWER(transaction_items.category))'), $excludedCategories);
            })
            ->sum('transaction_items.price');
    }

    private function incomeCategoriesLower(): array
    {
        return array_map(
            static fn (string $category): string => strtolower($category),
            self::INCOME_CATEGORIES
        );
    }

    private function formatMonthYear(int $month, int $year): string
    {
        $monthNames = [
            1 => 'Januari',
            2 => 'Februari',
            3 => 'Maret',
            4 => 'April',
            5 => 'Mei',
            6 => 'Juni',
            7 => 'Juli',
            8 => 'Agustus',
            9 => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Desember',
        ];

        return ($monthNames[$month] ?? 'Tidak Diketahui').' '.$year;
    }
}
