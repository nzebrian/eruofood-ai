<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Vehicle;

use DateTimeImmutable;
use EruoFood\Dispatch\Domain\Enum\VehicleStatus;
use EruoFood\Dispatch\Domain\Enum\VehicleType;
use EruoFood\Dispatch\Domain\Enum\VehicleVerificationState;
use EruoFood\Dispatch\Domain\Exception\VehicleNotDispatchable;

/**
 * A vehicle a rider works on.
 *
 * Before M26 this did not exist: `marketplace_riders.vehicle_type` was a
 * free-form string with no capacity, no documents and no verification. A
 * dispatch engine cannot match a load to that, and nobody could answer "is this
 * rider insured?" without asking them.
 *
 * ## Three separate questions, three separate fields
 *
 * `status` — may it be used right now? (operational)
 * `verificationState` — has a human checked the paperwork? (compliance)
 * document expiry dates — is that paperwork still current? (time)
 *
 * They are not collapsed into one flag because they fail independently. A
 * verified vehicle whose insurance lapsed last night is still verified — its
 * documents simply are not current, and it should stop receiving work without
 * anybody having to remember to change a status. That is why
 * {@see isDispatchable()} evaluates expiry against the clock rather than
 * trusting a nightly job that might not have run.
 *
 * Identity verification is **not** here. That is M24's, read through its
 * published contract. A vehicle's papers and a person's identity are different
 * things and conflating them would let one vouch for the other.
 */
final class Vehicle
{
    private function __construct(
        private readonly string $id,
        private readonly string $riderId,
        private VehicleType $type,
        private ?string $registrationNumber,
        private ?string $make,
        private ?string $model,
        private ?string $colour,
        private ?int $capacityKg,
        private ?int $capacityLitres,
        private VehicleStatus $status,
        private VehicleVerificationState $verificationState,
        private ?DateTimeImmutable $verifiedAt,
        private ?string $verifiedBy,
        private ?string $verificationNote,
        private ?DateTimeImmutable $insuranceExpiresAt,
        private ?DateTimeImmutable $roadworthinessExpiresAt,
        private ?DateTimeImmutable $licenceExpiresAt,
        private bool $isPrimary,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
        private int $version,
    ) {
    }

    public static function register(
        string $id,
        string $riderId,
        VehicleType $type,
        DateTimeImmutable $now,
        ?string $registrationNumber = null,
        ?string $make = null,
        ?string $model = null,
        ?string $colour = null,
        ?int $capacityKg = null,
        ?int $capacityLitres = null,
        bool $isPrimary = false,
    ): self {
        if ($type->requiresRegistration() && ($registrationNumber === null || trim($registrationNumber) === '')) {
            throw VehicleNotDispatchable::because(sprintf(
                'A %s needs a registration number.',
                $type->value,
            ));
        }

        return new self(
            $id,
            $riderId,
            $type,
            $registrationNumber === null ? null : mb_strtoupper(trim($registrationNumber)),
            $make,
            $model,
            $colour,
            $capacityKg,
            $capacityLitres,
            // A newly registered vehicle is not usable until somebody checks it.
            // Defaulting to Active would let a rider self-certify their own
            // insurance, which is the whole thing verification exists to stop.
            VehicleStatus::PendingVerification,
            VehicleVerificationState::Unverified,
            null,
            null,
            null,
            null,
            null,
            null,
            $isPrimary,
            $now,
            $now,
            1,
        );
    }

    public static function reconstitute(
        string $id,
        string $riderId,
        VehicleType $type,
        ?string $registrationNumber,
        ?string $make,
        ?string $model,
        ?string $colour,
        ?int $capacityKg,
        ?int $capacityLitres,
        VehicleStatus $status,
        VehicleVerificationState $verificationState,
        ?DateTimeImmutable $verifiedAt,
        ?string $verifiedBy,
        ?string $verificationNote,
        ?DateTimeImmutable $insuranceExpiresAt,
        ?DateTimeImmutable $roadworthinessExpiresAt,
        ?DateTimeImmutable $licenceExpiresAt,
        bool $isPrimary,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
        int $version,
    ): self {
        return new self(
            $id,
            $riderId,
            $type,
            $registrationNumber,
            $make,
            $model,
            $colour,
            $capacityKg,
            $capacityLitres,
            $status,
            $verificationState,
            $verifiedAt,
            $verifiedBy,
            $verificationNote,
            $insuranceExpiresAt,
            $roadworthinessExpiresAt,
            $licenceExpiresAt,
            $isPrimary,
            $createdAt,
            $updatedAt,
            $version,
        );
    }

    /**
     * The single question dispatch asks.
     *
     * All three conditions, evaluated against the clock. Expiry is checked here
     * rather than relying on a status flag somebody has to flip, because a
     * lapsed policy should remove a vehicle from service at the moment it
     * lapses — not at the next time an operator happens to look.
     */
    public function isDispatchable(DateTimeImmutable $now): bool
    {
        return $this->status->isDispatchable()
            && $this->verificationState->permitsDispatch()
            && $this->documentsAreCurrent($now);
    }

    public function documentsAreCurrent(DateTimeImmutable $now): bool
    {
        foreach ([$this->insuranceExpiresAt, $this->roadworthinessExpiresAt, $this->licenceExpiresAt] as $expiry) {
            // A null date means "not recorded", not "expired". Requiring every
            // document up front would exclude legitimate riders during rollout;
            // the verification decision is where completeness is judged.
            if ($expiry !== null && $expiry <= $now) {
                return false;
            }
        }

        return true;
    }

    /** Documents lapsing soon, so a rider gets a warning rather than a surprise. */
    public function expiresWithin(DateTimeImmutable $now, int $days): bool
    {
        $threshold = $now->modify(sprintf('+%d days', $days));

        foreach ([$this->insuranceExpiresAt, $this->roadworthinessExpiresAt, $this->licenceExpiresAt] as $expiry) {
            if ($expiry !== null && $expiry > $now && $expiry <= $threshold) {
                return true;
            }
        }

        return false;
    }

    /** Whether this vehicle can serve a request needing `$required`. */
    public function satisfies(VehicleType $required, ?int $kg = null, ?int $litres = null): bool
    {
        return $this->type->satisfies($required)
            && $this->type->canCarry($kg, $litres, $this->capacityKg, $this->capacityLitres);
    }

    /** An operator accepting the paperwork. Never the rider themselves. */
    public function approve(string $actorId, DateTimeImmutable $now, ?string $note = null): void
    {
        $this->verificationState = VehicleVerificationState::Verified;
        $this->status = VehicleStatus::Active;
        $this->verifiedAt = $now;
        $this->verifiedBy = $actorId;
        $this->verificationNote = $note;
        $this->touch($now);
    }

    public function reject(string $actorId, string $reason, DateTimeImmutable $now): void
    {
        $this->verificationState = VehicleVerificationState::Rejected;
        $this->status = VehicleStatus::PendingVerification;
        $this->verifiedBy = $actorId;
        $this->verificationNote = $reason;
        $this->verifiedAt = null;
        $this->touch($now);
    }

    public function submitForVerification(DateTimeImmutable $now): void
    {
        if ($this->status === VehicleStatus::Retired) {
            throw VehicleNotDispatchable::because('A retired vehicle cannot be resubmitted.');
        }

        $this->verificationState = VehicleVerificationState::Pending;
        $this->touch($now);
    }

    /** Operations withdrawing a vehicle — an incident, a failed inspection. */
    public function suspend(string $reason, DateTimeImmutable $now): void
    {
        $this->status = VehicleStatus::Suspended;
        $this->verificationNote = $reason;
        $this->touch($now);
    }

    public function reinstate(DateTimeImmutable $now): void
    {
        if (! $this->verificationState->permitsDispatch()) {
            throw VehicleNotDispatchable::because('An unverified vehicle cannot be reinstated.');
        }

        $this->status = VehicleStatus::Active;
        $this->touch($now);
    }

    /** The rider no longer has it. Kept for the record; never dispatched on again. */
    public function retire(DateTimeImmutable $now): void
    {
        $this->status = VehicleStatus::Retired;
        $this->isPrimary = false;
        $this->touch($now);
    }

    /**
     * Record or update the paperwork dates.
     *
     * Changing a document resets verification to pending: the previous approval
     * was of the previous documents, and letting a rider extend their own
     * insurance date on an already-verified vehicle would make the check
     * ceremonial.
     */
    public function updateDocuments(
        ?DateTimeImmutable $insuranceExpiresAt,
        ?DateTimeImmutable $roadworthinessExpiresAt,
        ?DateTimeImmutable $licenceExpiresAt,
        DateTimeImmutable $now,
    ): void {
        $this->insuranceExpiresAt = $insuranceExpiresAt;
        $this->roadworthinessExpiresAt = $roadworthinessExpiresAt;
        $this->licenceExpiresAt = $licenceExpiresAt;

        if ($this->verificationState->permitsDispatch()) {
            $this->verificationState = VehicleVerificationState::Pending;
            $this->status = VehicleStatus::PendingVerification;
            $this->verifiedAt = null;
        }

        $this->touch($now);
    }

    /**
     * Mark expired documents as such, for the nightly sweep and for reporting.
     *
     * The status moves off `Active` at the same time, and that pairing is not
     * cosmetic: a vehicle left `active` with `expired` paperwork would read as
     * in-service in every operator list while {@see isDispatchable()} quietly
     * refused it — and it is a state the database rejects outright, because
     * `active` is defined as "somebody verified this".
     *
     * Back to pending rather than suspended: the rider has not done anything
     * wrong, their policy simply ran out, and the route back is the ordinary
     * one — supply current documents, get re-approved.
     */
    public function markExpired(DateTimeImmutable $now): void
    {
        if ($this->verificationState === VehicleVerificationState::Verified && ! $this->documentsAreCurrent($now)) {
            $this->verificationState = VehicleVerificationState::Expired;
            $this->status = VehicleStatus::PendingVerification;
            $this->touch($now);
        }
    }

    public function makePrimary(DateTimeImmutable $now): void
    {
        $this->isPrimary = true;
        $this->touch($now);
    }

    public function clearPrimary(DateTimeImmutable $now): void
    {
        $this->isPrimary = false;
        $this->touch($now);
    }

    public function belongsTo(string $riderId): bool
    {
        return $this->riderId === $riderId;
    }

    private function touch(DateTimeImmutable $now): void
    {
        $this->updatedAt = $now;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function riderId(): string
    {
        return $this->riderId;
    }

    public function type(): VehicleType
    {
        return $this->type;
    }

    public function registrationNumber(): ?string
    {
        return $this->registrationNumber;
    }

    public function make(): ?string
    {
        return $this->make;
    }

    public function model(): ?string
    {
        return $this->model;
    }

    public function colour(): ?string
    {
        return $this->colour;
    }

    public function capacityKg(): ?int
    {
        return $this->capacityKg;
    }

    public function capacityLitres(): ?int
    {
        return $this->capacityLitres;
    }

    public function status(): VehicleStatus
    {
        return $this->status;
    }

    public function verificationState(): VehicleVerificationState
    {
        return $this->verificationState;
    }

    public function verifiedAt(): ?DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    public function verifiedBy(): ?string
    {
        return $this->verifiedBy;
    }

    public function verificationNote(): ?string
    {
        return $this->verificationNote;
    }

    public function insuranceExpiresAt(): ?DateTimeImmutable
    {
        return $this->insuranceExpiresAt;
    }

    public function roadworthinessExpiresAt(): ?DateTimeImmutable
    {
        return $this->roadworthinessExpiresAt;
    }

    public function licenceExpiresAt(): ?DateTimeImmutable
    {
        return $this->licenceExpiresAt;
    }

    public function isPrimary(): bool
    {
        return $this->isPrimary;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function version(): int
    {
        return $this->version;
    }
}
