<?php

namespace App\Http\Controllers;

use App\Http\Requests\ShowUserBudgetRequest;
use App\Http\Requests\UpsertUserBudgetRequest;
use App\Models\UserBudget;
use Illuminate\Http\JsonResponse;

class UserBudgetController extends Controller
{
    public function show(ShowUserBudgetRequest $request): JsonResponse
    {
        $month = (int) $request->input('month', now()->month);
        $year = (int) $request->input('year', now()->year);

        $budget = UserBudget::query()
            ->where('user_id', auth()->id())
            ->where('month', $month)
            ->where('year', $year)
            ->first();

        return response()->json([
            'month' => $month,
            'year' => $year,
            'data' => $budget,
        ]);
    }

    public function upsert(UpsertUserBudgetRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $budget = UserBudget::query()->firstOrNew([
            'user_id' => auth()->id(),
            'month' => $validated['month'],
            'year' => $validated['year'],
        ]);

        $wasRecentlyCreated = ! $budget->exists;

        $budget->limit = $validated['limit'];

        if ($request->exists('target_remaining')) {
            $budget->target_remaining = $validated['target_remaining'] ?? null;
        }

        $budget->save();

        return response()->json($budget, $wasRecentlyCreated ? 201 : 200);
    }
}
