<?php

declare(strict_types=1);

namespace EruoFood\Payments\Application\Port;

/**
 * Who to tell, and who is allowed to look.
 *
 * A merchant id is not a user id. Settlement notifications go to a person, and
 * the merchant-facing endpoints have to answer "is this the merchant asking, or
 * somebody who typed their id into the URL" — both need the mapping, and
 * neither is Payments' to own.
 *
 * A port rather than a Marketplace import, and the adapter reads a table rather
 * than a class, following {@see \EruoFood\Dispatch\Application\Port\RiderDirectory}.
 * Marketplace can restructure its vendor aggregate freely; one file follows.
 */
interface MerchantDirectory
{
    /**
     * The user account that owns a merchant, or null when there is none.
     *
     * Null is a real answer, not an error: a driver payee has no vendor record,
     * and a merchant may have been removed. Callers must treat it as "nobody to
     * notify" and "nobody authorised", never as "everybody".
     */
    public function ownerOf(string $merchantType, string $merchantId): ?string;

    /**
     * The merchants a user owns, for scoping their own queries.
     *
     * @return list<string>
     */
    public function merchantsFor(string $userId): array;

    /** A display name for a merchant, for an operator view. Never for a token. */
    public function nameOf(string $merchantType, string $merchantId): ?string;
}
