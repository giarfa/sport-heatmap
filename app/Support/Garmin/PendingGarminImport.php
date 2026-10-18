<?php

namespace App\Support\Garmin;

use App\Data\Garmin\ParsedGarminCsv;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;
use Throwable;

/**
 * The one reader and writer of the parsed upload awaiting the visitor's review.
 *
 * The session is the only binding between the upload, the review and the
 * confirm: no route parameter or form field names an import, so no request can
 * reach any import but the one in its own session — which is what keeps two
 * signed-out visitors apart. Nothing is stored in the database until the
 * visitor confirms, and an abandoned review leaves only this entry behind,
 * which the next upload replaces.
 *
 * The plan the review computed is remembered under the same entry, so a new
 * upload — put() replaces the whole entry — and a cancel or confirm — forget()
 * drops it — take the plan with them and it can never outlive its upload.
 */
final readonly class PendingGarminImport
{
    private const KEY = 'garmin_import.pending';

    public function __construct(private Session $session) {}

    /**
     * Park a parsed upload for review, replacing any earlier one, and return the
     * token the review form must echo back on confirm.
     */
    public function put(ParsedGarminCsv $parsed): string
    {
        $token = Str::random(40);

        $this->session->put(self::KEY, [
            'token' => $token,
            'parsed' => $parsed->toSessionArray(),
        ]);

        return $token;
    }

    public function token(): ?string
    {
        $token = $this->session->get(self::KEY.'.token');

        return is_string($token) ? $token : null;
    }

    /**
     * The parsed upload, or null when there is none — or when the entry cannot
     * be read back, which is treated the same way rather than as a 500.
     */
    public function parsed(): ?ParsedGarminCsv
    {
        $parsed = $this->session->get(self::KEY.'.parsed');

        if (! is_array($parsed)) {
            return null;
        }

        try {
            return ParsedGarminCsv::fromSessionArray($parsed);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Keep the plan the review computed, with the stamp of the database state it
     * was computed from. Scalars only, like the rest of the entry.
     *
     * @param  array{athlete: int, stamp: string, new: array<int, int>, conflicts: array<int, int>, already_imported: array<int, int>}  $plan  Row indexes, and for the last two the stored run's id by row index
     */
    public function rememberPlan(array $plan): void
    {
        $this->session->put(self::KEY.'.plan', $plan);
    }

    /**
     * The remembered plan, or null when there is none or it is not the shape
     * rememberPlan() writes — treated as absent rather than as a 500.
     *
     * @return array{athlete: int, stamp: string, new: array<int, int>, conflicts: array<int, int>, already_imported: array<int, int>}|null
     */
    public function rememberedPlan(): ?array
    {
        $plan = $this->session->get(self::KEY.'.plan');

        if (
            ! is_array($plan)
            || ! is_int($plan['athlete'] ?? null)
            || ! is_string($plan['stamp'] ?? null)
            || ! is_array($plan['new'] ?? null)
            || ! is_array($plan['conflicts'] ?? null)
            || ! is_array($plan['already_imported'] ?? null)
        ) {
            return null;
        }

        /** @var array{athlete: int, stamp: string, new: array<int, int>, conflicts: array<int, int>, already_imported: array<int, int>} $plan */
        return $plan;
    }

    public function forget(): void
    {
        $this->session->forget(self::KEY);
    }
}
