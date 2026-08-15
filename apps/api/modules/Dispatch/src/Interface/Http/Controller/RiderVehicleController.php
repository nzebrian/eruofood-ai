<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Interface\Http\Controller;

use DateTimeImmutable;
use EruoFood\Dispatch\Application\Service\VehicleService;
use EruoFood\Dispatch\Domain\Enum\VehicleType;
use EruoFood\Dispatch\Domain\Vehicle\Vehicle;
use EruoFood\Dispatch\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Dispatch\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A rider managing their own vehicles.
 *
 * Every write here leaves the vehicle unusable or pushes it back to pending.
 * There is no endpoint on this controller that can produce a dispatchable
 * vehicle — approval requires an operator, and it lives on the admin
 * controller. Without that split, vehicle verification would be a form riders
 * fill in about themselves.
 *
 * The rider is resolved from the authenticated account throughout; no rider id
 * is accepted from the request.
 */
final readonly class RiderVehicleController
{
    use ResolvesAuthUser;
    use RespondsWithData;

    public function __construct(
        private VehicleService $vehicles,
        private DispatchPresenter $presenter,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $now = new DateTimeImmutable();

        return $this->collection(array_map(
            fn (Vehicle $vehicle): array => $this->presenter->vehicle($vehicle, $now),
            $this->vehicles->own($this->currentUserId($request)),
        ));
    }

    public function show(Request $request, string $vehicleId): JsonResponse
    {
        return $this->data($this->presenter->vehicle(
            $this->vehicles->getOwned($this->currentUserId($request), $vehicleId),
            new DateTimeImmutable(),
        ));
    }

    /** Register a vehicle. Lands pending verification, always. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:bike,tricycle,car,bus'],
            'registration_number' => ['nullable', 'string', 'max:32'],
            'make' => ['nullable', 'string', 'max:64'],
            'model' => ['nullable', 'string', 'max:64'],
            'colour' => ['nullable', 'string', 'max:32'],
            // Bounded so a typo cannot claim a bicycle carries a tonne. The
            // domain caps a stated capacity at the type default anyway; this
            // just rejects nonsense at the edge.
            'capacity_kg' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'capacity_litres' => ['nullable', 'integer', 'min:1', 'max:20000'],
        ]);

        $vehicle = $this->vehicles->register(
            userId: $this->currentUserId($request),
            type: VehicleType::from($data['type']),
            registrationNumber: $data['registration_number'] ?? null,
            make: $data['make'] ?? null,
            model: $data['model'] ?? null,
            colour: $data['colour'] ?? null,
            capacityKg: isset($data['capacity_kg']) ? (int) $data['capacity_kg'] : null,
            capacityLitres: isset($data['capacity_litres']) ? (int) $data['capacity_litres'] : null,
        );

        return $this->data($this->presenter->vehicle($vehicle, new DateTimeImmutable()), 201);
    }

    /**
     * Record the paperwork dates.
     *
     * Sends a previously verified vehicle back to pending, by the aggregate's
     * own rule — a rider who could extend their own insurance date on a
     * verified vehicle would make the check ceremonial.
     */
    public function updateDocuments(Request $request, string $vehicleId): JsonResponse
    {
        $data = $request->validate([
            'insurance_expires_at' => ['nullable', 'date'],
            'roadworthiness_expires_at' => ['nullable', 'date'],
            'licence_expires_at' => ['nullable', 'date'],
        ]);

        $vehicle = $this->vehicles->updateDocuments(
            $this->currentUserId($request),
            $vehicleId,
            isset($data['insurance_expires_at']) ? new DateTimeImmutable($data['insurance_expires_at']) : null,
            isset($data['roadworthiness_expires_at']) ? new DateTimeImmutable($data['roadworthiness_expires_at']) : null,
            isset($data['licence_expires_at']) ? new DateTimeImmutable($data['licence_expires_at']) : null,
        );

        return $this->data($this->presenter->vehicle($vehicle, new DateTimeImmutable()));
    }

    /** Put the vehicle in front of an operator. */
    public function submit(Request $request, string $vehicleId): JsonResponse
    {
        return $this->data($this->presenter->vehicle(
            $this->vehicles->submitForVerification($this->currentUserId($request), $vehicleId),
            new DateTimeImmutable(),
        ));
    }

    public function makePrimary(Request $request, string $vehicleId): JsonResponse
    {
        return $this->data($this->presenter->vehicle(
            $this->vehicles->makePrimary($this->currentUserId($request), $vehicleId),
            new DateTimeImmutable(),
        ));
    }

    /** The rider no longer has it. Kept for the record, never dispatched on again. */
    public function retire(Request $request, string $vehicleId): JsonResponse
    {
        return $this->data($this->presenter->vehicle(
            $this->vehicles->retire($this->currentUserId($request), $vehicleId),
            new DateTimeImmutable(),
        ));
    }
}
