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

    public function forget(): void
    {
        $this->session->forget(self::KEY);
    }
}
