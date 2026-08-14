<?php

declare(strict_types=1);

namespace EruoFood\Geo\Interface\Http\Controller;

use DateTimeInterface;
use EruoFood\Geo\Application\Service\DeliveryDistanceService;
use EruoFood\Geo\Application\Service\MerchantLocationService;
use EruoFood\Geo\Application\Service\RiderLocationService;
use EruoFood\Geo\Domain\ValueObject\Coordinates;
use EruoFood\Geo\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Geo\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Read surfaces for the Global Command Centre.
 *
 * Two things it reports and one it deliberately does not.
 *
 * **Provider health and cost** — call volume, cache hit rate, failure rate and
 * latency, so that a degrading provider and a runaway bill are both visible
 * before customers or the finance team discover them. Built entirely from the
 * telemetry table, which by construction holds no coordinates and no address
 * text: this is a surface operators and analysts will export and graph, and
 * none of them need to know where a particular customer lives.
 *
 * **Pricing mode** — whether routed pricing is on, because "why did this
 * delivery cost that?" is a question the command centre will be asked and the
 * answer depends on a switch.
 *
 * **Not a rider map.** There is no endpoint here that lists where riders are.
 * That is dispatch and live tracking, it belongs to a later milestone, and a
 * real-time map of a workforce's positions is not something to build before
 * anything needs it.
 */
final readonly class GeoAdminController
{
    use ResolvesAuthUser;
    use RespondsWithData;

    public function __construct(
        private RiderLocationService $riders,
        private MerchantLocationService $merchants,
        private DeliveryDistanceService $distances,
        private GeoPresenter $presenter,
    ) {
    }

    /**
     * Provider cost and health for the last 24 hours.
     *
     * Cache hit rate is the number to watch: mapping APIs bill per request, so
     * a rate that falls is a bill that rises, and it usually falls for a reason
     * — a deploy that changed a cache key, a client that stopped reusing one.
     */
    public function providerHealth(Request $request): JsonResponse
    {
        $since = now()->subDay();

        // Query builder rather than the Eloquent model: these are aggregate
        // reporting reads, and hydrating each row into a model whose columns do
        // not exist on the table invites exactly the kind of undefined-property
        // bug that only shows up on a dashboard nobody tests.
        $rows = DB::table('geo_provider_requests')
            ->where('requested_at', '>=', $since)
            ->selectRaw('provider, capability')
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when served_from_cache then 1 else 0 end) as cached')
            ->selectRaw('sum(case when succeeded then 0 else 1 end) as failed')
            ->selectRaw('avg(duration_ms) as avg_duration_ms')
            ->groupBy('provider', 'capability')
            ->orderBy('provider')
            ->orderBy('capability')
            ->get();

        $capabilities = [];

        foreach ($rows as $row) {
            $total = (int) $row->total;
            $cached = (int) $row->cached;
            $failed = (int) $row->failed;

            $capabilities[] = [
                'provider' => (string) $row->provider,
                'capability' => (string) $row->capability,
                'total' => $total,
                'served_from_cache' => $cached,
                // The billable half — the only part that costs anything.
                'billable' => $total - $cached,
                'failed' => $failed,
                'cache_hit_rate' => $total === 0 ? 0.0 : round($cached / $total, 4),
                'failure_rate' => $total === 0 ? 0.0 : round($failed / $total, 4),
                'avg_duration_ms' => $row->avg_duration_ms === null ? null : (int) round((float) $row->avg_duration_ms),
            ];
        }

        $billableToday = DB::table('geo_provider_requests')
            ->where('served_from_cache', false)
            ->where('requested_at', '>=', now()->startOfDay())
            ->count();

        $quota = (int) config('geo.limits.provider_daily_quota', 50_000);

        return $this->data([
            'window' => '24h',
            'capabilities' => $capabilities,
            'daily_quota' => [
                'billable_calls_today' => $billableToday,
                'limit' => $quota,
                'remaining' => max(0, $quota - $billableToday),
            ],
            'failure_codes' => $this->failureCodes($since),
        ]);
    }

    /**
     * Whether customers are currently billed on routed distance.
     *
     * The switch is read live, so this reflects what the next quote will
     * actually do rather than what was configured at boot.
     */
    public function pricingMode(Request $request): JsonResponse
    {
        return $this->data([
            'routed_pricing_enabled' => $this->distances->routedPricingEnabled(),
            'shadow_mode' => (bool) config('delivery.routing_pricing.shadow_mode', false),
            'refuse_when_unavailable' => (bool) config('delivery.routing_pricing.refuse_when_unavailable', true),
            'stale_route_grace_seconds' => (int) config('geo.cache.stale_route_grace', 21_600),
        ]);
    }

    /**
     * Geocoding coverage — how much of the platform's geography is actually
     * resolved.
     *
     * An unresolved location is a delivery that will fail later, so this is a
     * backlog to work through rather than a statistic.
     */
    public function coverage(Request $request): JsonResponse
    {
        $locations = DB::table('geo_locations')
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when latitude is null then 1 else 0 end) as ungeocoded')
            ->selectRaw("sum(case when verification_status = 'confirmed' then 1 else 0 end) as confirmed")
            ->selectRaw("sum(case when verification_status = 'disputed' then 1 else 0 end) as disputed")
            ->selectRaw("sum(case when precision in ('rooftop','range_interpolated') then 1 else 0 end) as deliverable")
            ->first();

        return $this->data([
            'locations' => [
                'total' => (int) ($locations->total ?? 0),
                'awaiting_geocode' => (int) ($locations->ungeocoded ?? 0),
                'confirmed' => (int) ($locations->confirmed ?? 0),
                'disputed' => (int) ($locations->disputed ?? 0),
                'precise_enough_to_deliver' => (int) ($locations->deliverable ?? 0),
            ],
            'riders' => [
                // A count, not positions. Operations needs to know the fleet is
                // reporting; it does not need a map to know that.
                'reporting_recently' => $this->riders->activeRiderCount(),
                'stale_after_seconds' => $this->riders->staleAfterSeconds(),
            ],
        ]);
    }

    /** A single location, for investigating a delivery that went wrong. */
    public function showLocation(Request $request, string $id): JsonResponse
    {
        $location = $this->merchants->get($id);

        return $this->data($this->presenter->merchantLocation($location));
    }

    /** Mark an operator-checked location as correct. */
    public function confirmLocation(Request $request, string $id): JsonResponse
    {
        $location = $this->merchants->confirm($id, $this->currentUserId($request));

        return $this->data($this->presenter->merchantLocation($location));
    }

    /** Flag a location as wrong — a rider could not find it. */
    public function disputeLocation(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:280']]);

        $location = $this->merchants->dispute($id, (string) $data['reason']);

        return $this->data($this->presenter->merchantLocation($location));
    }

    /**
     * A distance between two points, for an operator checking a disputed fee.
     *
     * Returns the provenance alongside the number, because "the fee was wrong"
     * and "the fee was computed from a six-hour-old route" are different
     * findings and only one of them is a bug.
     */
    public function measure(Request $request): JsonResponse
    {
        $data = $request->validate([
            'origin_latitude' => ['required', 'numeric', 'between:-90,90'],
            'origin_longitude' => ['required', 'numeric', 'between:-180,180'],
            'destination_latitude' => ['required', 'numeric', 'between:-90,90'],
            'destination_longitude' => ['required', 'numeric', 'between:-180,180'],
            'travel_mode' => ['nullable', 'string', 'in:driving,two_wheeler,bicycle,walking'],
        ]);

        $route = $this->distances->route(
            Coordinates::fromMixed($data['origin_latitude'], $data['origin_longitude']),
            Coordinates::fromMixed($data['destination_latitude'], $data['destination_longitude']),
            isset($data['travel_mode']) ? (string) $data['travel_mode'] : null,
        );

        if ($route === null) {
            return $this->data([
                'route' => null,
                'reason' => 'No measured route is available for those points.',
            ]);
        }

        return $this->data([
            'route' => $this->presenter->route($route, (int) config('geo.cache.stale_route_grace', 21_600)),
        ]);
    }

    /** @return list<array{code: string, total: int}> */
    private function failureCodes(DateTimeInterface $since): array
    {
        $rows = DB::table('geo_provider_requests')
            ->where('requested_at', '>=', $since)
            ->whereNotNull('failure_code')
            ->selectRaw('failure_code, count(*) as total')
            ->groupBy('failure_code')
            ->orderByDesc('total')
            ->get();

        $codes = [];

        foreach ($rows as $row) {
            $codes[] = ['code' => (string) $row->failure_code, 'total' => (int) $row->total];
        }

        return $codes;
    }
}
