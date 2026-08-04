<?php

declare(strict_types=1);

namespace EruoFood\Payments\Application\Service;

use DateTimeImmutable;
use EruoFood\Payments\Domain\Enum\PaymentProvider;
use EruoFood\Payments\Domain\Exception\PaymentsNotAuthorized;
use EruoFood\Payments\Domain\Exception\PaymentsNotFound;
use EruoFood\Payments\Domain\Method\SavedPaymentMethod;
use EruoFood\Payments\Domain\Method\SavedPaymentMethodRepository;
use EruoFood\Payments\Domain\ValueObject\CardFingerprint;

/** Tokenised, PCI-safe saved payment methods. */
final readonly class SavedMethodService
{
    public function __construct(private SavedPaymentMethodRepository $methods)
    {
    }

    /** @return list<SavedPaymentMethod> */
    public function forUser(string $userId): array
    {
        return $this->methods->forUser($userId);
    }

    public function save(string $userId, PaymentProvider $provider, CardFingerprint $card, bool $default): SavedPaymentMethod
    {
        if ($default) {
            $this->methods->clearDefaultFor($userId);
        }
        $method = SavedPaymentMethod::save(
            $this->methods->nextIdentity(),
            $userId,
            $provider,
            $card,
            $default,
            new DateTimeImmutable(),
        );
        $this->methods->save($method);

        return $method;
    }

    public function makeDefault(string $id, string $userId): SavedPaymentMethod
    {
        $method = $this->owned($id, $userId);
        $this->methods->clearDefaultFor($userId);
        $method->makeDefault();
        $this->methods->save($method);

        return $method;
    }

    public function delete(string $id, string $userId): void
    {
        $this->owned($id, $userId);
        $this->methods->delete($id);
    }

    private function owned(string $id, string $userId): SavedPaymentMethod
    {
        $method = $this->methods->findById($id) ?? throw PaymentsNotFound::of('payment method', $id);
        if (! $method->isOwnedBy($userId)) {
            throw new PaymentsNotAuthorized();
        }

        return $method;
    }
}
