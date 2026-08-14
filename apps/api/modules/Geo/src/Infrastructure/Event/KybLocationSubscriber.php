<?php

declare(strict_types=1);

namespace EruoFood\Geo\Infrastructure\Event;

use EruoFood\Geo\Application\Service\MerchantLocationService;
use EruoFood\Verification\Domain\Business\BusinessProfileRepository;
use EruoFood\Verification\Domain\Event\SubjectVerified;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Geocodes a business's registered address once KYB approves it.
 *
 * This closes a gap M24 left open deliberately: it created `latitude` and
 * `longitude` columns on `verification_business_profiles` and never populated
 * them, because there was no geocoder. Now there is.
 *
 * Three properties, each load-bearing:
 *
 * **The result is private.** A registered address is the one on the CAC filing,
 * which is frequently an accountant's office or the owner's home. It is
 * attached to the verification profile for operations, and it never becomes the
 * merchant's public map pin — that is the trading address, which the merchant
 * sets themselves.
 *
 * **It never fails the approval.** Every failure is caught. A business that
 * passed KYB is verified whether or not a mapping provider was reachable at
 * that moment; letting a geocode failure propagate would turn somebody else's
 * outage into a blocked merchant onboarding.
 *
 * **It is one-way and by event name.** Geo never queries Verification's tables
 * on the hot path, and Verification knows nothing about Geo — the same
 * arrangement Commerce and Marketplace already use for this event.
 *
 * ## Why this is registered by class name, not as an instance
 *
 * The dependencies below are resolved only when a KYB approval actually fires.
 * Constructing this at boot instead would pull M24's business-profile
 * repository — and with it the encrypter, since M24 encrypts document numbers
 * at the repository boundary — into *every* process start, including
 * `package:discover`, which runs before an application key exists. That is not
 * a hypothetical: it broke CI on this milestone's first push.
 *
 * A listener that is needed on a rare event should cost nothing on the paths
 * where it is not.
 */
final readonly class KybLocationSubscriber
{
    public function __construct(
        private MerchantLocationService $locations,
        private BusinessProfileRepository $profiles,
        private LoggerInterface $logger,
    ) {
    }

    /** Invoked by the dispatcher; the container builds this class at that moment. */
    public function handle(SubjectVerified $event): void
    {
        if ($event->subjectType !== 'business' || $event->caseType !== 'business') {
            return;
        }

        $this->geocode($event->subjectId);
    }

    private function geocode(string $businessId): void
    {
        try {
            // The event carries the business id, not the profile id, so the
            // profile is looked up by the business it belongs to.
            $profile = $this->profiles->findForBusiness('vendor', $businessId)
                ?? $this->profiles->findForBusiness('store', $businessId);

            if ($profile === null) {
                return;
            }

            $this->locations->geocodeRegisteredAddress(
                $profile->id(),
                $profile->address(),
                $profile->countryCode(),
            );
        } catch (Throwable $e) {
            // Logged with the business id and the exception class only. The
            // address itself is not logged: it is the very PII this pathway
            // exists to handle carefully, and a log line outlives the request
            // that produced it.
            $this->logger->warning('Could not geocode a verified business address.', [
                'business_id' => $businessId,
                'exception' => $e::class,
            ]);
        }
    }
}
