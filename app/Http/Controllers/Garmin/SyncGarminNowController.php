<?php

namespace App\Http\Controllers\Garmin;

use App\Actions\Garmin\SyncGarminActivities;
use App\Enums\GarminSyncTrigger;
use App\Exceptions\Garmin\GarminSyncAlreadyRunningException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Garmin\GarminPersonalApiClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SyncGarminNowController extends Controller
{
    /**
     * Run the Garmin sync now, in the request, and say what it did.
     *
     * Access is the route's: the owner's session. There is no input to validate, so there
     * is no Form Request. The sync runs here and not in a queue because the owner is
     * waiting for the answer, and a sync is short once the first full read is done.
     */
    public function __invoke(
        Request $request,
        GarminPersonalApiClient $client,
        SyncGarminActivities $syncGarminActivities,
    ): RedirectResponse {
        /*
         * No key is not an error to retry: nothing is called and nothing is recorded. The
         * button is not rendered then either, so this only answers a hand-made POST.
         */
        if (! $client->isConfigured()) {
            return redirect()->route('dashboard')->with('status', 'Garmin sync is not configured.');
        }

        // Behind the `auth` route middleware, so the user is never null here.
        /** @var User $owner */
        $owner = $request->user();

        /*
         * A first full read can outlive the browser's patience; without this a closed tab
         * ends the script mid-page. The sync is safe to cut short either way, but finishing
         * it records the attempt.
         */
        ignore_user_abort(true);

        try {
            $result = $syncGarminActivities($owner, GarminSyncTrigger::Manual);
        } catch (GarminSyncAlreadyRunningException $exception) {
            return redirect()->route('dashboard')->with('status', $exception->getMessage());
        }

        return redirect()->route('dashboard')->with('status', $result->summary());
    }
}
