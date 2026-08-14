<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Delivery pricing (M25)
|------------------------------------------------------------------------------
| One switch in this file decides what real customers are charged. It ships OFF
| and must be turned on deliberately, with a rollback ready.
|
| ## Why it exists
|
| Before M25 the per-kilometre delivery fee was computed from straight-line
| distance. Roads are neither straight nor on a sphere: in Lagos the routed
| distance commonly runs 1.3–1.6× the straight line, and worse across a bridge
| or through a one-way system. So the pre-M25 fee was not merely imprecise — it
| understated the real journey, in one direction, on every single order.
|
| Fixing that raises prices. That is a business decision with a customer-facing
| consequence, not a deployment side effect, which is why the corrected
| behaviour sits behind a switch that defaults to the old one.
|
| ## How to turn it on safely
|
|   1. Deploy with `routing_pricing.enabled = false` (this default). Nothing
|      changes for anybody.
|   2. Set `routing_pricing.shadow_mode = true`. Routed distances are computed
|      and the difference against the straight-line fee is recorded, but
|      customers are still charged the old fee. Watch the real spread on real
|      orders.
|   3. When the numbers are understood, set `routing_pricing.enabled = true`.
|   4. Rollback is setting it back to false. No migration, no data change, no
|      deploy — the calculator reads this on every quote.
|
| ## What is never allowed
|
| Straight-line distance is not a billing distance when routed pricing is on. If
| routing cannot be established, the chain falls to a merchant flat fee and then
| to an honest refusal. It never quietly substitutes a haversine estimate, and
| it never accepts an order at an uncertain price to reconcile later.
|------------------------------------------------------------------------------
*/

return [
    'routing_pricing' => [
        /*
        | The switch. False keeps the pre-M25 straight-line behaviour exactly as
        | it was; true bills on measured road distance.
        */
        'enabled' => (bool) env('DELIVERY_ROUTING_PRICING_ENABLED', false),

        /*
        | Measure without charging. Computes the routed distance alongside the
        | straight-line one and records the comparison, so the size of the
        | pricing change is known before anybody feels it. Costs provider calls;
        | changes no price.
        */
        'shadow_mode' => (bool) env('DELIVERY_ROUTING_SHADOW_MODE', false),

        /*
        | What to do when routed pricing is on and no route can be established.
        |
        | True (the default) refuses to quote — the customer is told plainly
        | that delivery pricing is unavailable and offered pickup. False permits
        | the merchant's own flat zone fee instead, which is a price the
        | merchant published and stands behind.
        |
        | Neither setting ever permits a straight-line bill.
        */
        'refuse_when_unavailable' => (bool) env('DELIVERY_REFUSE_WHEN_ROUTING_UNAVAILABLE', true),

        /*
        | Sanity ceiling on the routed:straight-line ratio.
        |
        | A route four times the straight line is not a delivery, it is a
        | provider returning nonsense — a ferry route, a wrong hemisphere, a
        | mis-parsed response. Beyond this the result is rejected and the chain
        | continues, because a bad number that reaches a customer's bill is
        | worse than no number.
        */
        'max_detour_ratio' => (float) env('DELIVERY_MAX_DETOUR_RATIO', 4.0),
    ],
];
