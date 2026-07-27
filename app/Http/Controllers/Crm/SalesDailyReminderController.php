<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Services\SalesDailyReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SalesDailyReminderController extends Controller
{
    public function dismiss(Request $request): JsonResponse
    {
        abort_unless($request->user()->isSales(), 403);
        $validator = Validator::make($request->all(), [
            'reminder_key' => ['required', 'string', Rule::in([SalesDailyReminderService::KEY])],
            'user_id' => ['prohibited'],
            'dismissed_for_date' => ['prohibited'],
        ]);
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Permintaan pengingat tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();
        $now = now();
        $identity = [
            'user_id' => $request->user()->id,
            'reminder_key' => SalesDailyReminderService::KEY,
            'dismissed_for_date' => $today,
        ];
        DB::table('user_daily_reminder_dismissals')->insertOrIgnore($identity + [
            'dismissed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('user_daily_reminder_dismissals')->where($identity)->update([
            'dismissed_at' => $now,
            'updated_at' => $now,
        ]);

        return response()->json(['ok' => true, 'dismissed_for_date' => $today]);
    }
}
