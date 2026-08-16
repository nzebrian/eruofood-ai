<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Lifecycle;

/**
 * Implemented by a context's own status enum to declare its phase projection.
 *
 * ## Why an interface rather than a lookup table somewhere central
 *
 * A central `match` mapping thirty enums onto eight phases would live far from
 * every state machine it describes, and would go stale the first time a context
 * adds a case — silently, because a missing entry would just fall through to a
 * default. Putting the method on the enum means adding a case without
 * classifying it is a *compile-time* failure: PHP requires the `match` in
 * `serverPhase()` to be exhaustive, so the language enforces what a convention
 * could not.
 *
 * ## What implementing this does not change
 *
 * Nothing about the context's own behaviour. Transitions, terminality and
 * vocabulary stay exactly as they are — M23's payment states, M24's case
 * states, M26's assignment states are untouched. This adds one query: "in the
 * platform's coarse language, where is this?"
 *
 * The projection is deliberately one-way. There is no `fromPhase()`, because
 * eight phases cannot reconstruct thirty vocabularies, and anything that tried
 * would be inventing state. Same reasoning as M26's `AssignmentState::forDeliveryStatus()`
 * mirror: the coarse view follows the precise one, never the reverse.
 */
interface ServerAuthoritative
{
    /**
     * Where this state sits in the platform-wide lifecycle.
     *
     * Computed from server state only. Never accepted from a client.
     */
    public function serverPhase(): ServerPhase;
}
