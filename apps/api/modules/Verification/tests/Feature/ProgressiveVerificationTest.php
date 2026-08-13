<?php

declare(strict_types=1);

use EruoFood\Identity\Infrastructure\Persistence\Eloquent\Model\UserModel;
use EruoFood\Payments\Application\Service\WalletService;
use EruoFood\Payments\Domain\Enum\TransactionType;
use EruoFood\Payments\Domain\Enum\WalletOwnerType;
use EruoFood\Verification\Application\Port\PhoneVerificationSender;
use EruoFood\Verification\Application\Service\PhoneVerificationService;
use EruoFood\Verification\Application\Service\StepUpService;
use EruoFood\Verification\Contracts\StepUpGuard;
use EruoFood\Verification\Contracts\VerificationStatusQuery;
use EruoFood\Verification\Domain\Exception\StepUpRequired;
use EruoFood\Verification\Domain\Phone\PhoneChallengeRepository;
use EruoFood\Verification\Infrastructure\Persistence\Eloquent\Model\VerificationPhoneChallengeModel;
use EruoFood\Verification\Infrastructure\StepUp\ConfiguredStepUpGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/**
 * M24 — progressive verification.
 *
 * The requirement is that a customer is never forced through KYC to sign up or
 * to order. Assurance is asked for at the moment an operation actually needs it,
 * and only as much as that operation needs. These tests hold both ends: that
 * registering and ordering demand nothing, and that a sensitive operation
 * genuinely refuses until the account has climbed far enough.
 */
function progressiveUser(object $test, string $email): array
{
    Mail::fake();
    $data = $test->postJson('/api/v1/auth/register', [
        'name' => 'Progressive User',
        'email' => $email,
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data');

    return ['token' => $data['tokens']['access_token'], 'id' => $data['user']['id']];
}

/** Turn step-up on with a given trigger table and re-resolve what reads it. */
function setStepUp(array $config): void
{
    config()->set('verification.step_up', $config);
    app()->forgetInstance(StepUpService::class);
    app()->forgetInstance(StepUpGuard::class);
    app()->forgetInstance(ConfiguredStepUpGuard::class);
    app()->forgetInstance(WalletService::class);
}

/**
 * A sender that keeps what it was asked to send.
 *
 * The plaintext code exists in exactly one place — the moment it is handed to
 * the sender — because it is hashed before it is stored. So a test that wants to
 * complete the flow has to stand where the SMS gateway stands, which is also a
 * demonstration that nothing downstream of storage can recover it.
 */
final class CapturingPhoneSender implements PhoneVerificationSender
{
    /** @var array<string, string> */
    public array $sent = [];

    public function send(string $phoneNumber, string $code): void
    {
        $this->sent[$phoneNumber] = $code;
    }
}

function captureCodes(): CapturingPhoneSender
{
    $sender = new CapturingPhoneSender();
    app()->instance(PhoneVerificationSender::class, $sender);
    app()->forgetInstance(PhoneVerificationService::class);

    return $sender;
}

/** Run the real request/confirm round trip for a number. */
function confirmPhone(object $test, string $token, string $phone): void
{
    $sender = captureCodes();

    $test->withToken($token)->postJson('/api/v1/verification/phone/request', ['phone' => $phone])
        ->assertStatus(202);

    $test->withToken($token)
        ->postJson('/api/v1/verification/phone/confirm', ['code' => $sender->sent[$phone]])
        ->assertOk()
        ->assertJsonPath('data.verified', true);
}

// -------------------------------------------------- nothing at registration --

it('asks a new account for no verification at all', function (): void {
    ['token' => $token] = progressiveUser($this, 'fresh@example.com');

    $this->withToken($token)->getJson('/api/v1/verification/level')
        ->assertOk()
        ->assertJsonPath('data.level', 'basic')
        ->assertJsonPath('data.phone_verified', false);

    // No case is opened, nothing is pending, nothing is demanded.
    $this->withToken($token)->getJson('/api/v1/verification/me')
        ->assertOk()->assertJsonPath('data.status', 'not_started');
});

it('ships with step-up off so no existing customer is interrupted', function (): void {
    expect(config('verification.step_up.enabled'))->toBeFalse();

    ['id' => $userId] = progressiveUser($this, 'untouched@example.com');

    // Even a transfer far above the configured threshold: the trigger table is
    // populated, but the master switch is off, so nothing is demanded.
    expect(app(StepUpGuard::class)->requiredLevelFor('wallet.transfer', 100_000_000))->toBeNull()
        ->and(fn () => app(StepUpGuard::class)->assert('wallet.transfer', $userId, 100_000_000))
        ->not->toThrow(StepUpRequired::class);
});

// ------------------------------------------------------- the phone rung --

it('raises the account to the phone level once a number is confirmed', function (): void {
    ['token' => $token, 'id' => $userId] = progressiveUser($this, 'phone@example.com');

    confirmPhone($this, $token, '+2348030000001');

    expect(app(VerificationStatusQuery::class)->levelFor((string) $userId))->toBe('phone')
        // Identity projects the level onto the account, so step-up checks do not
        // reach across a context boundary on every sensitive request.
        ->and(UserModel::query()->whereKey($userId)->value('verification_level'))->toBe('phone');
});

it('never stores the code it sent', function (): void {
    ['token' => $token] = progressiveUser($this, 'nocode@example.com');

    $this->withToken($token)->postJson('/api/v1/verification/phone/request', ['phone' => '+2348030000002'])
        ->assertStatus(202)
        // The response does not echo the number back either.
        ->assertJsonMissing(['phone' => '+2348030000002']);

    $row = VerificationPhoneChallengeModel::query()->where('phone', '+2348030000002')->first();

    // A hash, not the code. A database copy is not enough to complete somebody
    // else's verification.
    expect($row->code_hash)->not->toBeEmpty()
        ->and(strlen((string) $row->code_hash))->toBeGreaterThan(20)
        ->and(password_verify('000000', (string) $row->code_hash) && password_verify('111111', (string) $row->code_hash))
        ->toBeFalse();
});

it('refuses a wrong code and spends an attempt', function (): void {
    ['token' => $token] = progressiveUser($this, 'wrong@example.com');

    $this->withToken($token)->postJson('/api/v1/verification/phone/request', ['phone' => '+2348030000003'])
        ->assertStatus(202);

    $this->withToken($token)->postJson('/api/v1/verification/phone/confirm', ['code' => '000000'])
        ->assertStatus(422)
        ->assertJsonPath('data.verified', false);

    expect((int) VerificationPhoneChallengeModel::query()->where('phone', '+2348030000003')->value('attempts'))
        ->toBe(1);
});

it('stops accepting guesses once the attempt budget is spent', function (): void {
    ['token' => $token, 'id' => $userId] = progressiveUser($this, 'bruteforce@example.com');

    $this->withToken($token)->postJson('/api/v1/verification/phone/request', ['phone' => '+2348030000004'])
        ->assertStatus(202);

    $phones = app(PhoneVerificationService::class);

    // Five wrong codes exhausts the configured budget.
    foreach (range(1, 5) as $attempt) {
        try {
            $phones->confirm((string) $userId, str_pad((string) $attempt, 6, '0', STR_PAD_LEFT));
        } catch (Throwable) {
            // A guess may legitimately be refused; what matters is the sixth.
        }
    }

    // The limit lives on the row, so it survives a cache flush — which is
    // exactly when an attacker would want it to reset.
    expect(fn () => $phones->confirm((string) $userId, '999999'))
        ->toThrow(EruoFood\Verification\Domain\Exception\VerificationInvalidState::class);
});

it('refuses an expired code', function (): void {
    ['token' => $token, 'id' => $userId] = progressiveUser($this, 'expired@example.com');

    $this->withToken($token)->postJson('/api/v1/verification/phone/request', ['phone' => '+2348030000005'])
        ->assertStatus(202);

    VerificationPhoneChallengeModel::query()
        ->where('user_id', $userId)
        ->update(['expires_at' => now()->subHour()]);

    expect(fn () => app(PhoneVerificationService::class)->confirm((string) $userId, '000000'))
        ->toThrow(EruoFood\Verification\Domain\Exception\VerificationInvalidState::class);
});

it('replaces the outstanding code rather than adding a second valid one', function (): void {
    ['token' => $token, 'id' => $userId] = progressiveUser($this, 'reissue@example.com');

    $this->withToken($token)->postJson('/api/v1/verification/phone/request', ['phone' => '+2348030000006'])
        ->assertStatus(202);
    $first = (string) VerificationPhoneChallengeModel::query()->where('user_id', $userId)->value('code_hash');

    $this->withToken($token)->postJson('/api/v1/verification/phone/request', ['phone' => '+2348030000006'])
        ->assertStatus(202);
    $second = (string) VerificationPhoneChallengeModel::query()->where('user_id', $userId)->value('code_hash');

    // One row, one live code: two valid codes would double the guessing surface
    // for no benefit.
    expect(VerificationPhoneChallengeModel::query()->where('user_id', $userId)->count())->toBe(1)
        ->and($second)->not->toBe($first);
});

it('will not let one account confirm a number onto another', function (): void {
    ['token' => $mine] = progressiveUser($this, 'mine@example.com');
    ['token' => $theirs, 'id' => $theirId] = progressiveUser($this, 'theirs@example.com');

    confirmPhone($this, $mine, '+2348030000007');

    // The account always comes from the token, never from the request, so there
    // is no field through which to aim a confirmation at somebody else.
    expect(app(PhoneChallengeRepository::class)->isVerified((string) $theirId))->toBeFalse();

    $this->withToken($theirs)->getJson('/api/v1/verification/level')
        ->assertOk()->assertJsonPath('data.level', 'basic');
});

// ---------------------------------------------------------------- step-up --

it('demands a stronger level only past the configured amount', function (): void {
    setStepUp([
        'enabled' => true,
        'triggers' => ['wallet.transfer' => ['above_minor' => 500000, 'level' => 'identity']],
    ]);

    $guard = app(StepUpGuard::class);

    // A small transfer is not gated at all; the threshold is the whole point.
    expect($guard->requiredLevelFor('wallet.transfer', 1000))->toBeNull()
        ->and($guard->requiredLevelFor('wallet.transfer', 900000))->toBe('identity');
});

it('blocks a large wallet transfer from an unverified customer', function (): void {
    ['id' => $userId] = progressiveUser($this, 'bigspender@example.com');
    ['id' => $peerId] = progressiveUser($this, 'peer@example.com');

    setStepUp([
        'enabled' => true,
        'triggers' => ['wallet.transfer' => ['above_minor' => 500000, 'level' => 'identity']],
    ]);

    $wallets = app(WalletService::class);
    $wallet = $wallets->getOrOpen(WalletOwnerType::Customer, (string) $userId);
    $wallets->credit($wallet, 2_000_000, TransactionType::Topup, null, 'seed');

    try {
        $wallets->transfer(
            WalletOwnerType::Customer,
            (string) $userId,
            WalletOwnerType::Customer,
            (string) $peerId,
            900_000,
            null,
        );
        $thrown = null;
    } catch (StepUpRequired $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull()
        // The refusal names the next step rather than being a dead end.
        ->and($thrown->requiredLevel)->toBe('identity')
        ->and($thrown->trigger)->toBe('wallet.transfer')
        // Nothing moved: the check runs before anything is locked.
        ->and($wallets->getOrOpen(WalletOwnerType::Customer, (string) $userId)->balance()->minorUnits)
        ->toBe(2_000_000);
});

it('lets a small transfer through untouched under the same configuration', function (): void {
    ['id' => $userId] = progressiveUser($this, 'smallspender@example.com');
    ['id' => $peerId] = progressiveUser($this, 'smallpeer@example.com');

    setStepUp([
        'enabled' => true,
        'triggers' => ['wallet.transfer' => ['above_minor' => 500000, 'level' => 'identity']],
    ]);

    $wallets = app(WalletService::class);
    $wallet = $wallets->getOrOpen(WalletOwnerType::Customer, (string) $userId);
    $wallets->credit($wallet, 2_000_000, TransactionType::Topup, null, 'seed');

    $wallets->transfer(
        WalletOwnerType::Customer,
        (string) $userId,
        WalletOwnerType::Customer,
        (string) $peerId,
        1_000,
        null,
    );

    expect($wallets->getOrOpen(WalletOwnerType::Customer, (string) $peerId)->balance()->minorUnits)
        ->toBe(1_000);
});

it('accepts the weaker level when that is all the trigger asks for', function (): void {
    ['token' => $token, 'id' => $userId] = progressiveUser($this, 'phonestep@example.com');
    ['id' => $peerId] = progressiveUser($this, 'phonepeer@example.com');

    confirmPhone($this, $token, '+2348030000008');

    setStepUp([
        'enabled' => true,
        'triggers' => ['wallet.transfer' => ['above_minor' => 500000, 'level' => 'phone']],
    ]);

    $wallets = app(WalletService::class);
    $wallet = $wallets->getOrOpen(WalletOwnerType::Customer, (string) $userId);
    $wallets->credit($wallet, 2_000_000, TransactionType::Topup, null, 'seed');

    // A confirmed number is enough here — the customer is not pushed through a
    // document check for an operation that only wanted the cheaper rung.
    $wallets->transfer(
        WalletOwnerType::Customer,
        (string) $userId,
        WalletOwnerType::Customer,
        (string) $peerId,
        900_000,
        null,
    );

    expect($wallets->getOrOpen(WalletOwnerType::Customer, (string) $peerId)->balance()->minorUnits)
        ->toBe(900_000);
});

it('demands nothing for a trigger nobody configured', function (): void {
    ['id' => $userId] = progressiveUser($this, 'unknowntrigger@example.com');

    setStepUp(['enabled' => true, 'triggers' => []]);

    // A caller naming a trigger the platform never decided to gate must not
    // silently lock the customer out of the operation.
    expect(app(StepUpGuard::class)->requiredLevelFor('something.nobody.configured', 999_999_999))->toBeNull()
        ->and(fn () => app(StepUpGuard::class)->assert('something.nobody.configured', $userId, 999_999_999))
        ->not->toThrow(StepUpRequired::class);
});

it('reports a step-up refusal as a 403 carrying the required level', function (): void {
    ['token' => $token, 'id' => $userId] = progressiveUser($this, 'httpstep@example.com');

    setStepUp([
        'enabled' => true,
        'triggers' => ['wallet.transfer' => ['above_minor' => 100, 'level' => 'identity']],
    ]);

    $wallets = app(WalletService::class);
    $wallet = $wallets->getOrOpen(WalletOwnerType::Customer, (string) $userId);
    $wallets->credit($wallet, 500_000, TransactionType::Topup, null, 'seed');

    ['id' => $peerId] = progressiveUser($this, 'httppeer@example.com');

    $response = $this->withToken($token)->postJson('/api/v1/payments/wallet/transfer', [
        'to_user_id' => (string) $peerId,
        'amount_minor' => 400_000,
    ]);

    // 403 with a machine-readable code, not a 400: the caller is not forbidden,
    // they are not yet verified enough, and the body says which level to obtain.
    $response->assertStatus(403)
        ->assertJsonPath('error.code', 'VERIFICATION_STEP_UP_REQUIRED');
});
