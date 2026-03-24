<?php

use App\Models\Transaction;
use App\Models\User;
use App\Models\UserBudget;
use App\Services\FirebasePushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('sends a budget alert immediately after a transaction crosses the budget limit', function (): void {
    config()->set('cache.default', 'array');

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    UserBudget::query()->create([
        'user_id' => $user->id,
        'month' => 3,
        'year' => 2026,
        'limit' => 100,
    ]);

    $existingTransaction = Transaction::query()->create([
        'user_id' => $user->id,
        'merchant_name' => 'Warung',
        'description' => 'Belanja awal',
        'price_total' => 90,
        'tax' => 0,
        'service_charge' => 0,
        'transaction_date' => '2026-03-10 10:00:00',
        'input_method' => 'manual',
    ]);

    $existingTransaction->items()->create([
        'item_name' => 'Belanja mingguan',
        'price' => 90,
        'category' => 'Makanan',
    ]);

    $pushService = \Mockery::mock(FirebasePushService::class);
    $pushService
        ->shouldReceive('sendToUser')
        ->once()
        ->withArgs(function (User $notifiedUser, string $title, string $body, array $data, ?string $type) use ($user): bool {
            return $notifiedUser->is($user)
                && $title === 'Peringatan Anggaran'
                && $body === 'Pemakaian anggaran kamu sudah 110% bulan ini.'
                && $type === 'budget_alert'
                && $data['month'] === 3
                && $data['year'] === 2026
                && $data['threshold'] === 100
                && (float) $data['used_percent'] === 110.0
                && (float) $data['limit'] === 100.0
                && (float) $data['used'] === 110.0;
        })
        ->andReturn([
            'sent' => 1,
            'failed' => 0,
            'skipped' => false,
        ]);

    app()->instance(FirebasePushService::class, $pushService);

    $response = $this->postJson('/api/transactions', [
        'merchant_name' => 'Kafe',
        'description' => 'Ngopi',
        'price_total' => 20,
        'tax' => 0,
        'service_charge' => 0,
        'transaction_date' => '2026-03-24 08:00:00',
        'input_method' => 'manual',
        'items' => [
            [
                'item_name' => 'Kopi',
                'price' => 20,
                'category' => 'Makanan',
            ],
        ],
    ]);

    $response->assertCreated();
});

it('does not resend the same budget threshold alert on subsequent transactions', function (): void {
    config()->set('cache.default', 'array');

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    UserBudget::query()->create([
        'user_id' => $user->id,
        'month' => 3,
        'year' => 2026,
        'limit' => 100,
    ]);

    $existingTransaction = Transaction::query()->create([
        'user_id' => $user->id,
        'merchant_name' => 'Supermarket',
        'description' => 'Belanja awal',
        'price_total' => 75,
        'tax' => 0,
        'service_charge' => 0,
        'transaction_date' => '2026-03-09 10:00:00',
        'input_method' => 'manual',
    ]);

    $existingTransaction->items()->create([
        'item_name' => 'Groceries',
        'price' => 75,
        'category' => 'Makanan',
    ]);

    $pushService = \Mockery::mock(FirebasePushService::class);
    $pushService
        ->shouldReceive('sendToUser')
        ->once()
        ->andReturn([
            'sent' => 1,
            'failed' => 0,
            'skipped' => false,
        ]);

    app()->instance(FirebasePushService::class, $pushService);

    $firstResponse = $this->postJson('/api/transactions', [
        'merchant_name' => 'Kafe',
        'description' => 'Ngopi pertama',
        'price_total' => 10,
        'tax' => 0,
        'service_charge' => 0,
        'transaction_date' => '2026-03-24 08:00:00',
        'input_method' => 'manual',
        'items' => [
            [
                'item_name' => 'Kopi',
                'price' => 10,
                'category' => 'Makanan',
            ],
        ],
    ]);

    $secondResponse = $this->postJson('/api/transactions', [
        'merchant_name' => 'Minimarket',
        'description' => 'Snack',
        'price_total' => 2,
        'tax' => 0,
        'service_charge' => 0,
        'transaction_date' => '2026-03-24 09:00:00',
        'input_method' => 'manual',
        'items' => [
            [
                'item_name' => 'Snack',
                'price' => 2,
                'category' => 'Makanan',
            ],
        ],
    ]);

    $firstResponse->assertCreated();
    $secondResponse->assertCreated();
});
