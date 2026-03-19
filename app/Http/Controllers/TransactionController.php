<?php

namespace App\Http\Controllers;

use App\Http\Requests\ScanReceiptRequest;
use App\Http\Requests\StoreTransactionRequest;
use App\Jobs\ProcessReceiptScan;
use App\Models\ReceiptScan;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class TransactionController extends Controller
{
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
}
