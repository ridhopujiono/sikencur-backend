<?php

namespace Database\Seeders;

use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class March2026TransactionSeeder extends Seeder
{
    private const SEED_TAG = 'SEED-MARCH-2026';

    public function run(): void
    {
        $profiles = [
            ['email' => 'test1@example.com', 'name' => 'Test 1', 'profile' => 'saver'],
            ['email' => 'test2@example.com', 'name' => 'Test 2', 'profile' => 'spender'],
            ['email' => 'test3@example.com', 'name' => 'Test 3', 'profile' => 'investor'],
            ['email' => 'test4@example.com', 'name' => 'Test 4', 'profile' => 'debtor'],
            ['email' => 'test5@example.com', 'name' => 'Test 5', 'profile' => 'balanced'],
        ];

        foreach ($profiles as $data) {
            $user = User::query()->updateOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => Hash::make('password'),
                ]
            );

            $this->clearSeededTransactions($user);
            $this->seedByProfile($user, $data['profile']);
        }
    }

    private function clearSeededTransactions(User $user): void
    {
        Transaction::query()
            ->where('user_id', $user->id)
            ->where('description', 'like', self::SEED_TAG.'%')
            ->delete();
    }

    private function seedByProfile(User $user, string $profile): void
    {
        $period = CarbonPeriod::create('2026-03-01', '2026-03-30');

        $this->seedBaseIncome($user, $profile);

        foreach ($period as $date) {
            $day = (int) $date->format('d');

            match ($profile) {
                'saver' => $this->seedSaverDay($user, $date, $day),
                'spender' => $this->seedSpenderDay($user, $date, $day),
                'investor' => $this->seedInvestorDay($user, $date, $day),
                'debtor' => $this->seedDebtorDay($user, $date, $day),
                default => $this->seedBalancedDay($user, $date, $day),
            };
        }
    }

    private function seedBaseIncome(User $user, string $profile): void
    {
        $salaryAmount = match ($profile) {
            'saver' => 12000000,
            'spender' => 8500000,
            'investor' => 11000000,
            'debtor' => 5500000,
            default => 9000000,
        };

        $this->createTransaction(
            user: $user,
            date: Carbon::parse('2026-03-01'),
            hour: 9,
            minute: 0,
            merchant: 'Payroll',
            items: [
                ['item_name' => 'Gaji Bulanan', 'price' => $salaryAmount, 'category' => 'Gaji'],
            ],
            inputMethod: 'manual',
            note: 'salary'
        );

        if ($profile === 'saver') {
            $this->createTransaction(
                user: $user,
                date: Carbon::parse('2026-03-20'),
                hour: 9,
                minute: 15,
                merchant: 'Payroll',
                items: [
                    ['item_name' => 'Bonus Bulanan', 'price' => 1000000, 'category' => 'Bonus & THR'],
                ],
                inputMethod: 'manual',
                note: 'bonus'
            );
        }

        if ($profile === 'investor') {
            $this->createTransaction(
                user: $user,
                date: Carbon::parse('2026-03-15'),
                hour: 10,
                minute: 10,
                merchant: 'UMKM Online',
                items: [
                    ['item_name' => 'Penghasilan Usaha', 'price' => 2500000, 'category' => 'Penghasilan Usaha'],
                ],
                inputMethod: 'manual',
                note: 'usaha'
            );
        }

        if ($profile === 'balanced') {
            $this->createTransaction(
                user: $user,
                date: Carbon::parse('2026-03-20'),
                hour: 10,
                minute: 30,
                merchant: 'Freelance Client',
                items: [
                    ['item_name' => 'Proyek Freelance', 'price' => 1200000, 'category' => 'Penghasilan Freelance'],
                ],
                inputMethod: 'manual',
                note: 'freelance'
            );
        }
    }

    private function seedSaverDay(User $user, Carbon $date, int $day): void
    {
        $food = 52000 + (($day % 5) * 3500);
        $this->createTransaction($user, $date, 8, 20, 'Warung Sehat', [
            ['item_name' => 'Makanan Harian', 'price' => $food, 'category' => 'Makanan & Minuman'],
        ], $day % 2 === 0 ? 'scan' : 'manual', 'saver-food');

        if (! $date->isWeekend()) {
            $transport = 22000 + (($day % 4) * 2000);
            $this->createTransaction($user, $date, 18, 5, 'Transport Umum', [
                ['item_name' => 'Ongkos Harian', 'price' => $transport, 'category' => 'Transportasi'],
            ], 'manual', 'saver-transport');
        }

        if ($date->dayOfWeek === Carbon::MONDAY) {
            $this->createTransaction($user, $date, 20, 0, 'Aplikasi Investasi', [
                ['item_name' => 'Investasi Mingguan', 'price' => 300000, 'category' => 'Investasi'],
            ], 'manual', 'saver-invest');
        }

        if ($day === 5) {
            $this->createTransaction($user, $date, 10, 0, 'PLN & PDAM', [
                ['item_name' => 'Tagihan Rumah', 'price' => 900000, 'category' => 'Tagihan & Utilitas'],
            ], 'manual', 'saver-bills');
        }

        if ($day === 10) {
            $this->createTransaction($user, $date, 11, 10, 'Provider Internet', [
                ['item_name' => 'Internet Rumah', 'price' => 350000, 'category' => 'Komunikasi'],
            ], 'manual', 'saver-internet');
        }
    }

    private function seedSpenderDay(User $user, Carbon $date, int $day): void
    {
        $food = 110000 + (($day % 6) * 7000);
        $this->createTransaction($user, $date, 8, 40, 'Coffee & Bistro', [
            ['item_name' => 'Makan & Kopi', 'price' => $food, 'category' => 'Makanan & Minuman'],
        ], $day % 2 === 0 ? 'scan' : 'manual', 'spender-food');

        if (! $date->isWeekend()) {
            $this->createTransaction($user, $date, 18, 30, 'Ride Hailing', [
                ['item_name' => 'Transport Harian', 'price' => 45000, 'category' => 'Transportasi'],
            ], 'manual', 'spender-transport');
        }

        if ($date->isWeekend()) {
            $shopping = 320000 + (($day % 3) * 80000);
            $this->createTransaction($user, $date, 15, 10, 'Lifestyle Mall', [
                ['item_name' => 'Belanja Akhir Pekan', 'price' => $shopping, 'category' => 'Belanja'],
            ], 'scan', 'spender-shopping');
        }

        if ($day % 3 === 0) {
            $this->createTransaction($user, $date, 21, 0, 'Cinema & Games', [
                ['item_name' => 'Hiburan Malam', 'price' => 180000, 'category' => 'Hiburan'],
            ], 'manual', 'spender-fun');
        }

        if ($day === 12) {
            $this->createTransaction($user, $date, 10, 0, 'Streaming Services', [
                ['item_name' => 'Langganan Bulanan', 'price' => 299000, 'category' => 'Langganan'],
            ], 'manual', 'spender-subscription');
        }
    }

    private function seedInvestorDay(User $user, Carbon $date, int $day): void
    {
        $food = 85000 + (($day % 5) * 4500);
        $this->createTransaction($user, $date, 8, 15, 'Healthy Meal Prep', [
            ['item_name' => 'Makan Harian', 'price' => $food, 'category' => 'Makanan & Minuman'],
        ], 'manual', 'investor-food');

        if (! $date->isWeekend()) {
            $this->createTransaction($user, $date, 18, 15, 'Commuter Line', [
                ['item_name' => 'Transport Harian', 'price' => 30000, 'category' => 'Transportasi'],
            ], 'manual', 'investor-transport');
        }

        if ($day % 3 === 0) {
            $this->createTransaction($user, $date, 19, 30, 'Platform Investasi', [
                ['item_name' => 'DCA Investasi', 'price' => 400000, 'category' => 'Investasi'],
            ], 'manual', 'investor-dca');
        }

        if ($day === 20) {
            $this->createTransaction($user, $date, 9, 40, 'Portofolio', [
                ['item_name' => 'Pendapatan Investasi', 'price' => 600000, 'category' => 'Pendapatan Investasi'],
            ], 'manual', 'investor-return');
        }

        if ($day === 7) {
            $this->createTransaction($user, $date, 10, 45, 'Asuransi Jiwa', [
                ['item_name' => 'Premi Bulanan', 'price' => 450000, 'category' => 'Asuransi'],
            ], 'manual', 'investor-insurance');
        }
    }

    private function seedDebtorDay(User $user, Carbon $date, int $day): void
    {
        $food = 95000 + (($day % 4) * 5500);
        $this->createTransaction($user, $date, 8, 50, 'Warung Harian', [
            ['item_name' => 'Makan Harian', 'price' => $food, 'category' => 'Makanan & Minuman'],
        ], 'manual', 'debtor-food');

        if (! $date->isWeekend()) {
            $this->createTransaction($user, $date, 18, 40, 'Transport Online', [
                ['item_name' => 'Transport Harian', 'price' => 40000, 'category' => 'Transportasi'],
            ], 'manual', 'debtor-transport');
        }

        if (in_array($day, [2, 16], true)) {
            $this->createTransaction($user, $date, 10, 0, 'Kredit Plus', [
                ['item_name' => 'Cicilan Bulanan', 'price' => 1800000, 'category' => 'Cicilan & Utang'],
            ], 'manual', 'debtor-installment');
        }

        if ($day === 23) {
            $this->createTransaction($user, $date, 10, 20, 'Bank Kredit', [
                ['item_name' => 'Bunga Kredit', 'price' => 350000, 'category' => 'Cicilan & Utang'],
            ], 'manual', 'debtor-interest');
        }

        if ($date->isWeekend()) {
            $this->createTransaction($user, $date, 15, 30, 'Supermarket', [
                ['item_name' => 'Belanja Mingguan', 'price' => 180000, 'category' => 'Belanja'],
            ], 'scan', 'debtor-shopping');
        }
    }

    private function seedBalancedDay(User $user, Carbon $date, int $day): void
    {
        $food = 78000 + (($day % 5) * 4000);
        $this->createTransaction($user, $date, 8, 25, 'Kantin Kantor', [
            ['item_name' => 'Makanan Harian', 'price' => $food, 'category' => 'Makanan & Minuman'],
        ], $day % 2 === 0 ? 'scan' : 'manual', 'balanced-food');

        if (! $date->isWeekend()) {
            $this->createTransaction($user, $date, 18, 20, 'Transport Publik', [
                ['item_name' => 'Transport Harian', 'price' => 28000, 'category' => 'Transportasi'],
            ], 'manual', 'balanced-transport');
        }

        if ($date->dayOfWeek === Carbon::MONDAY) {
            $this->createTransaction($user, $date, 20, 15, 'Bank Digital', [
                ['item_name' => 'Setor Tabungan', 'price' => 250000, 'category' => 'Tabungan'],
            ], 'manual', 'balanced-saving');
        }

        if ($date->dayOfWeek === Carbon::SATURDAY) {
            $this->createTransaction($user, $date, 19, 45, 'Cafe Family', [
                ['item_name' => 'Hiburan Akhir Pekan', 'price' => 120000, 'category' => 'Hiburan'],
            ], 'scan', 'balanced-fun');
        }

        if ($day === 5) {
            $this->createTransaction($user, $date, 10, 5, 'PLN & Internet', [
                ['item_name' => 'Tagihan Bulanan', 'price' => 1100000, 'category' => 'Tagihan & Utilitas'],
            ], 'manual', 'balanced-bills');
        }
    }

    private function createTransaction(
        User $user,
        Carbon $date,
        int $hour,
        int $minute,
        string $merchant,
        array $items,
        string $inputMethod,
        string $note,
        float $tax = 0.0,
        float $serviceCharge = 0.0
    ): void {
        $transactionDate = $date->copy()->setTime($hour, $minute, 0);
        $itemTotal = (float) collect($items)->sum(fn (array $item): float => (float) $item['price']);
        $priceTotal = round($itemTotal + $tax + $serviceCharge, 2);

        $description = sprintf(
            '%s | %s | %s | %s',
            self::SEED_TAG,
            $user->email,
            $transactionDate->format('Y-m-d H:i'),
            $note
        );

        DB::transaction(function () use (
            $user,
            $merchant,
            $description,
            $inputMethod,
            $priceTotal,
            $tax,
            $serviceCharge,
            $transactionDate,
            $items
        ): void {
            $transaction = Transaction::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'merchant_name' => $merchant,
                    'description' => $description,
                    'transaction_date' => $transactionDate->format('Y-m-d H:i:s'),
                    'input_method' => $inputMethod,
                ],
                [
                    'price_total' => $priceTotal,
                    'tax' => $tax > 0 ? $tax : null,
                    'service_charge' => $serviceCharge > 0 ? $serviceCharge : null,
                ]
            );

            $transaction->items()->delete();

            foreach ($items as $item) {
                $transaction->items()->create([
                    'item_name' => $item['item_name'],
                    'price' => (float) $item['price'],
                    'category' => $item['category'] ?? null,
                ]);
            }
        });
    }
}
