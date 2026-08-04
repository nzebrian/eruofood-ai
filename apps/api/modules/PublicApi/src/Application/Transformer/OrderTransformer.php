<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Application\Transformer;

use EruoFood\PublicApi\Domain\Order\OrderResource;

/** Transforms an order read-model into the stable external JSON shape. */
final readonly class OrderTransformer
{
    /**
     * @return array<string, mixed>
     */
    public function order(OrderResource $o): array
    {
        return [
            'id' => $o->id,
            'reference' => $o->reference,
            'status' => $o->status,
            'total_minor' => $o->totalMinor,
            'currency' => $o->currency,
            'pickup' => $o->pickup,
            'note' => $o->note,
            'lines' => $o->lines,
            'placed_at' => $o->placedAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function status(OrderResource $o): array
    {
        return ['id' => $o->id, 'reference' => $o->reference, 'status' => $o->status];
    }
}
