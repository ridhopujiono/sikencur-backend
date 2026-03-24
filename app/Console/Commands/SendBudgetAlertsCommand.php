<?php

namespace App\Console\Commands;

use App\Models\UserBudget;
use App\Services\BudgetAlertService;
use Illuminate\Console\Command;

class SendBudgetAlertsCommand extends Command
{
    protected $signature = 'notifications:budget-alerts {--month=} {--year=} {--force} {--debug}';

    protected $description = 'Send budget threshold alerts (80%, 100%, 120%) to users.';

    public function handle(BudgetAlertService $budgetAlertService): int
    {
        $month = (int) ($this->option('month') ?: now()->month);
        $year = (int) ($this->option('year') ?: now()->year);
        $force = (bool) $this->option('force');
        $debug = (bool) $this->option('debug');

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
            $result = $budgetAlertService->sendForBudget($budget, $force);
            $status = (string) ($result['status'] ?? 'failed');

            if (array_key_exists($status, $reasonCounters)) {
                $reasonCounters[$status]++;
            }

            if ($status === 'sent') {
                $sentCount++;
            }

            if (! $debug) {
                continue;
            }

            match ($status) {
                'no_user_or_limit' => $this->line(sprintf(
                    '[SKIP] user=%s reason=no_user_or_limit limit=%s',
                    $user?->email ?? 'unknown',
                    (string) $budget->limit
                )),
                'no_threshold' => $this->line(sprintf(
                    '[SKIP] user=%s reason=no_threshold used_percent=%.2f',
                    (string) ($user?->email ?? 'unknown'),
                    (float) ($result['used_percent'] ?? 0)
                )),
                'cache_hit' => $this->line(sprintf(
                    '[SKIP] user=%s reason=cache_hit threshold=%d',
                    (string) ($user?->email ?? 'unknown'),
                    (int) ($result['threshold'] ?? 0)
                )),
                'skipped' => $this->line(sprintf(
                    '[SKIP] user=%s reason=push_skipped_by_preferences_or_tokens threshold=%d',
                    (string) ($user?->email ?? 'unknown'),
                    (int) ($result['threshold'] ?? 0)
                )),
                'sent' => $this->line(sprintf(
                    '[SENT] user=%s threshold=%d sent=%d failed=%d skipped=%s',
                    (string) ($user?->email ?? 'unknown'),
                    (int) ($result['threshold'] ?? 0),
                    (int) ($result['sent'] ?? 0),
                    (int) ($result['failed'] ?? 0),
                    ($result['skipped'] ?? false) ? 'true' : 'false'
                )),
                default => $this->line(sprintf(
                    '[FAIL] user=%s threshold=%d sent=%d failed=%d',
                    (string) ($user?->email ?? 'unknown'),
                    (int) ($result['threshold'] ?? 0),
                    (int) ($result['sent'] ?? 0),
                    (int) ($result['failed'] ?? 0)
                )),
            };
        }

        $this->info("Budget alerts sent: {$sentCount}");

        if ($debug) {
            $this->line('Budget alerts debug summary: '.json_encode($reasonCounters));
        }

        return self::SUCCESS;
    }
}
