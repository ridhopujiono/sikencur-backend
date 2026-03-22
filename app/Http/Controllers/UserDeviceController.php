<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterFcmTokenRequest;
use App\Http\Requests\UnregisterFcmTokenRequest;
use App\Models\UserDevice;
use Illuminate\Http\JsonResponse;

class UserDeviceController extends Controller
{
    public function registerToken(RegisterFcmTokenRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $device = UserDevice::query()->firstOrNew([
            'fcm_token' => $validated['fcm_token'],
        ]);

        $wasRecentlyCreated = ! $device->exists;

        $device->user_id = auth()->id();
        $device->is_active = true;
        $device->last_seen_at = now();

        if ($request->exists('platform')) {
            $device->platform = $validated['platform'] ?? null;
        }

        if ($request->exists('device_name')) {
            $device->device_name = $validated['device_name'] ?? null;
        }

        $device->save();

        return response()->json($device, $wasRecentlyCreated ? 201 : 200);
    }

    public function unregisterToken(UnregisterFcmTokenRequest $request): JsonResponse
    {
        $updated = UserDevice::query()
            ->where('user_id', auth()->id())
            ->where('fcm_token', $request->validated('fcm_token'))
            ->update([
                'is_active' => false,
            ]);

        return response()->json([
            'success' => true,
            'updated' => $updated,
        ]);
    }
}
