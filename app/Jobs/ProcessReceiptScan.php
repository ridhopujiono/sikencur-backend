<?php

namespace App\Jobs;

use App\Models\ReceiptScan;
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

    public function handle(): void
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

                return;
            }

            $this->receiptScan->update([
                'status' => 'failed',
                'error_message' => $response->body(),
            ]);
        } catch (Throwable $e) {
            $this->receiptScan->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }
    }
}
