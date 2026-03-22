<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpsertUserNotificationPreferenceRequest;
use App\Models\UserNotificationPreference;
use Illuminate\Http\JsonResponse;

class UserNotificationPreferenceController extends Controller
{
    public function show(): JsonResponse
    {
        $preference = UserNotificationPreference::query()->firstOrCreate(
            ['user_id' => auth()->id()],
            [
                'push_enabled' => true,
                'weekly_summary_enabled' => true,
                'budget_alert_enabled' => true,
                'dss_tips_enabled' => false,
                'timezone' => 'Asia/Jakarta',
            ]
        );

        return response()->json($preference);
    }

    public function upsert(UpsertUserNotificationPreferenceRequest $request): JsonResponse
    {
        $preference = UserNotificationPreference::query()->firstOrNew([
            'user_id' => auth()->id(),
        ]);

        $wasRecentlyCreated = ! $preference->exists;

        $updatableFields = [
            'push_enabled',
            'weekly_summary_enabled',
            'budget_alert_enabled',
            'dss_tips_enabled',
            'quiet_hours_start',
            'quiet_hours_end',
            'timezone',
        ];

        foreach ($updatableFields as $field) {
            if ($request->exists($field)) {
                $preference->{$field} = $request->input($field);
            }
        }

        $preference->save();

        return response()->json($preference, $wasRecentlyCreated ? 201 : 200);
    }
}
