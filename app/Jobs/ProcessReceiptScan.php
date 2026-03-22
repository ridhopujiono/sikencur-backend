<?php

namespace App\Jobs;

use App\Models\ReceiptScan;
use App\Services\FirebasePushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessReceiptScan implements ShouldQueue
{
    use Queueable;

    public function __construct(public ReceiptScan $receiptScan)
    {
    }

    public function handle(FirebasePushService $pushService): void
    {
        $this->receiptScan->update([
            'status' => 'processing',
            'error_message' => null,
        ]);

        try {
            if (! Storage::disk('receipts')->exists($this->receiptScan->file_path)) {
                $this->receiptScan->update([
                    'status' => 'failed',
                    'error_message' => 'Receipt file not found.',
                ]);
                $this->notifyScanResult($pushService, 'failed');

                return;
            }

            $fileContents = Storage::disk('receipts')->get($this->receiptScan->file_path);

            $response = Http::timeout(60)
                ->attach('image', $fileContents, basename($this->receiptScan->file_path))
                ->post((string) config('services.openclaw.url'));

            if ($response->successful()) {
                $result = $response->json();

                $this->receiptScan->update([
                    'status' => 'completed',
                    'result_data' => $result ?? ['raw' => $response->body()],
                    'error_message' => null,
                ]);
                $this->notifyScanResult($pushService, 'completed');

                return;
            }

            $this->receiptScan->update([
                'status' => 'failed',
                'error_message' => $response->body(),
            ]);
            $this->notifyScanResult($pushService, 'failed');
        } catch (Throwable $e) {
            $this->receiptScan->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            $this->notifyScanResult($pushService, 'failed');
        }
    }

    private function notifyScanResult(FirebasePushService $pushService, string $status): void
    {
        $user = $this->receiptScan->user()->with('notificationPreference')->first();

        if ($user === null) {
            return;
        }

        if ($status === 'completed') {
            $pushService->sendToUser(
                $user,
                'OCR selesai',
                'Pemindaian struk kamu sudah selesai.',
                [
                    'scan_id' => $this->receiptScan->id,
                    'status' => 'completed',
                ],
                'ocr_completed'
            );

            return;
        }

        $pushService->sendToUser(
            $user,
            'OCR gagal',
            'Pemindaian struk gagal. Coba scan ulang ya.',
            [
                'scan_id' => $this->receiptScan->id,
                'status' => 'failed',
            ],
            'ocr_failed'
        );
    }
}
