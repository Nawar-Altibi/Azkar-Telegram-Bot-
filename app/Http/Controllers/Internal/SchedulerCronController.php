<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;

class SchedulerCronController extends Controller
{
    public function __invoke(): JsonResponse
    {
        Artisan::call('schedule:run');
        $schedule = trim(Artisan::output());

        if (config('queue.default') !== 'sync') {
            Artisan::call('queue:work', [
                '--stop-when-empty' => true,
                '--sleep' => 1,
                '--max-time' => 20,
                '--tries' => 3,
                '--no-interaction' => true,
            ]);
        }

        return response()->json([
            'ok' => true,
            'schedule' => $schedule,
        ]);
    }
}
