<?php

declare(strict_types=1);

use EruoFood\Notifications\Application\DTO\EmailMessage;
use EruoFood\Notifications\Application\DTO\Recipient;
use EruoFood\Notifications\Application\Port\EmailProvider;
use EruoFood\Notifications\Application\Port\RecipientResolver;
use EruoFood\Notifications\Application\Service\EmailBodyRenderer;
use EruoFood\Notifications\Domain\Enum\NotificationClass;
use EruoFood\Notifications\Infrastructure\Email\LogEmailProvider;
use EruoFood\Notifications\Infrastructure\Persistence\Eloquent\Model\NotificationModel;
use EruoFood\Notifications\Infrastructure\Seeder\NotificationsSeeder;
use EruoFood\Verification\Application\Service\ReviewService;
use EruoFood\Verification\Application\Service\VerificationService;
use EruoFood\Verification\Domain\Document\DocumentMetadata;
use EruoFood\Verification\Domain\Document\DocumentMetadataRepository;
use EruoFood\Verification\Domain\Enum\CaseType;
use EruoFood\Verification\Domain\Enum\DocumentType;
use EruoFood\Verification\Domain\Enum\RejectionReason;
use EruoFood\Verification\Domain\Enum\SubjectType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * M24 — the KYC/KYB email hooks, and the privacy line they must not cross.
 *
 * Verification is the most sensitive thing the platform emails about, and an
 * inbox is the least controlled place its data could end up: shared, forwarded,
 * indexed by a mail provider, and reachable by whoever compromises the account
 * next. So these tests check two things in equal measure — that the right
 * person is told the right thing at the right moment, and that what reaches
 * them contains none of the underlying identity data.
 */

/** A provider that keeps every message it was asked to transmit. */
final class RecordingEmailProvider implements EmailProvider
{
    /** @var list<EmailMessage> */
    public array $sent = [];

    public function name(): string
    {
        return 'recording';
    }

    public function send(EmailMessage $message): EruoFood\Notifications\Application\DTO\EmailDispatchResult
    {
        $this->sent[] = $message;

        return EruoFood\Notifications\Application\DTO\EmailDispatchResult::sent('rec-'.count($this->sent));
    }

    /** Everything transmitted, flattened, for "must not appear anywhere" assertions. */
    public function allText(): string
    {
        return implode("\n", array_map(
            static fn (EmailMessage $m): string => $m->subject."\n".$m->htmlBody."\n".$m->textBody."\n".json_encode($m->headers),
            $this->sent,
        ));
    }
}

function recordEmails(): RecordingEmailProvider
{
    $provider = new RecordingEmailProvider();
    app()->instance(EmailProvider::class, $provider);
    // The dispatcher holds its senders, so it has to be rebuilt around the new
    // provider.
    app()->forgetInstance(EruoFood\Notifications\Application\Service\ChannelDispatcher::class);
    app()->forgetInstance(EruoFood\Notifications\Application\Service\NotificationService::class);

    return $provider;
}

function notifiedUser(object $test, string $email): array
{
    Mail::fake();
    $data = $test->postJson('/api/v1/auth/register', [
        'name' => 'Ada Lovelace',
        'email' => $email,
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data');

    return ['token' => $data['tokens']['access_token'], 'id' => $data['user']['id']];
}

/** @return list<NotificationModel> */
function notificationsFor(string $userId, ?string $template = null): array
{
    $query = NotificationModel::query()->where('user_id', $userId);
    if ($template !== null) {
        $query->where('template_key', $template);
    }

    return $query->orderBy('created_at')->get()->all();
}

beforeEach(function (): void {
    (new NotificationsSeeder())->run();
});

// ------------------------------------------------------------ the hooks ------

it('tells a rider we have their verification the moment they submit', function (): void {
    $emails = recordEmails();
    ['id' => $riderId] = notifiedUser($this, 'rider-sub@example.com');

    $service = app(VerificationService::class);
    $case = $service->openCase(SubjectType::Rider, $riderId, CaseType::Identity, 'NG');
    $service->startVerification($case->id(), ['document']);

    $sent = notificationsFor($riderId, 'verification_submitted');

    expect($sent)->not->toBeEmpty()
        // Silence after handing over your documents reads as failure, which is
        // why this moment gets a message at all.
        ->and($emails->sent)->not->toBeEmpty()
        ->and($emails->sent[0]->subject)->toContain('verification');
});

it('tells a rider when they are approved to start delivering', function (): void {
    $emails = recordEmails();
    ['id' => $riderId] = notifiedUser($this, 'rider-ok@example.com');

    $service = app(VerificationService::class);
    $case = $service->openCase(SubjectType::Rider, $riderId, CaseType::Identity, 'NG');
    $started = $service->startVerification($case->id(), ['document']);
    app(ReviewService::class)->approve($started->id(), (string) Str::uuid());

    expect(notificationsFor($riderId, 'rider_verification_approved'))->not->toBeEmpty()
        // A rider approved is a rider activated — one message that says so,
        // rather than two that overlap.
        ->and($emails->allText())->toContain('accepting deliveries');
});

it('tells a subject when verification is declined, without saying what failed', function (): void {
    $emails = recordEmails();
    ['id' => $riderId] = notifiedUser($this, 'rider-no@example.com');

    $service = app(VerificationService::class);
    $case = $service->openCase(SubjectType::Rider, $riderId, CaseType::Identity, 'NG');
    $started = $service->startVerification($case->id(), ['document']);

    app(ReviewService::class)->reject($started->id(), (string) Str::uuid(), RejectionReason::FaceMismatch);

    $sent = notificationsFor($riderId, 'verification_rejected');

    expect($sent)->not->toBeEmpty()
        // The reason code stays behind a login. Telling somebody's inbox that a
        // face match failed is both a privacy leak and, where the rejection was
        // fraud-related, a tip-off.
        ->and($emails->allText())->not->toContain('face_mismatch')
        ->and($emails->allText())->not->toContain('Face')
        ->and($emails->allText())->toContain('Sign in');
});

it('tells a subject when they must verify again', function (): void {
    $emails = recordEmails();
    ['id' => $riderId] = notifiedUser($this, 'rider-again@example.com');

    $service = app(VerificationService::class);
    $case = $service->openCase(SubjectType::Rider, $riderId, CaseType::Identity, 'NG');
    $started = $service->startVerification($case->id(), ['document']);
    app(ReviewService::class)->approve($started->id(), (string) Str::uuid());
    app(ReviewService::class)->requireReverification($started->id(), (string) Str::uuid());

    // Distinct from expiry: routine ageing and a demanded re-check read very
    // differently to the person who has to act on them.
    expect(notificationsFor($riderId, 'reverification_required'))->not->toBeEmpty()
        ->and($emails->allText())->toContain('verify your identity again');
});

it('records processing in-app only, never as email', function (): void {
    $emails = recordEmails();
    ['id' => $riderId] = notifiedUser($this, 'rider-proc@example.com');

    $service = app(VerificationService::class);
    $case = $service->openCase(SubjectType::Rider, $riderId, CaseType::Identity, 'NG');
    $started = $service->startVerification($case->id(), ['document']);

    $before = count($emails->sent);
    app(EruoFood\Verification\Domain\VerificationCase\CaseRepository::class);
    $locked = app(EruoFood\Verification\Domain\VerificationCase\CaseRepository::class)->findById($started->id());
    $locked->markProcessing(EruoFood\Verification\Domain\Enum\ActorType::Provider, 'mock', new DateTimeImmutable());
    app(EruoFood\Verification\Domain\VerificationCase\CaseRepository::class)->save($locked);
    app(VerificationService::class)->announce($locked);

    // Reassurance, not news. A platform that emails at every internal state
    // change teaches people to ignore its email.
    expect(notificationsFor($riderId, 'verification_processing'))->not->toBeEmpty()
        ->and(count($emails->sent))->toBe($before);
});

// ------------------------------------------------------------ merchant KYB ---

it('writes to the account that owns a business, not to the business id', function (): void {
    $emails = recordEmails();
    ['id' => $ownerId] = notifiedUser($this, 'merchant@example.com');

    $vendorId = (string) Str::uuid();
    DB::table('marketplace_vendors')->insert([
        'id' => $vendorId,
        'owner_user_id' => $ownerId,
        'name' => 'Mama Put Kitchen',
        'slug' => 'mama-put-'.substr($vendorId, 0, 8),
        'type' => 'restaurant',
        'category' => 'african',
        'status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $service = app(VerificationService::class);
    $case = $service->openCase(SubjectType::Business, $vendorId, CaseType::Business, 'NG');
    $service->startVerification($case->id(), ['registry']);

    // Nobody can be emailed at a vendor id; the owning account is the contact.
    expect(notificationsFor($ownerId, 'kyb_submitted'))->not->toBeEmpty()
        ->and(notificationsFor($vendorId))->toBeEmpty()
        ->and($emails->allText())->toContain('business verification');
});

it('tells a merchant when their business is verified', function (): void {
    $emails = recordEmails();
    ['id' => $ownerId] = notifiedUser($this, 'merchant-ok@example.com');

    $vendorId = (string) Str::uuid();
    DB::table('marketplace_vendors')->insert([
        'id' => $vendorId, 'owner_user_id' => $ownerId, 'name' => 'Verified Kitchen',
        'slug' => 'verified-'.substr($vendorId, 0, 8), 'type' => 'restaurant', 'category' => 'african', 'status' => 'pending',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $service = app(VerificationService::class);
    $case = $service->openCase(SubjectType::Business, $vendorId, CaseType::Business, 'NG');
    $started = $service->startVerification($case->id(), ['registry']);
    app(ReviewService::class)->approve($started->id(), (string) Str::uuid());

    expect(notificationsFor($ownerId, 'kyb_approved'))->not->toBeEmpty()
        ->and($emails->allText())->toContain('fully active');
});

it('tells a merchant when business verification is declined', function (): void {
    $emails = recordEmails();
    ['id' => $ownerId] = notifiedUser($this, 'merchant-no@example.com');

    $vendorId = (string) Str::uuid();
    DB::table('marketplace_vendors')->insert([
        'id' => $vendorId, 'owner_user_id' => $ownerId, 'name' => 'Declined Kitchen',
        'slug' => 'declined-'.substr($vendorId, 0, 8), 'type' => 'restaurant', 'category' => 'african', 'status' => 'pending',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $service = app(VerificationService::class);
    $case = $service->openCase(SubjectType::Business, $vendorId, CaseType::Business, 'NG');
    $started = $service->startVerification($case->id(), ['registry']);
    app(ReviewService::class)->reject($started->id(), (string) Str::uuid(), RejectionReason::ManualRejection);

    expect(notificationsFor($ownerId, 'kyb_rejected'))->not->toBeEmpty()
        ->and($emails->allText())->toContain('merchant dashboard');
});

it('sends nothing when a business has no reachable owner', function (): void {
    $emails = recordEmails();

    // A business whose owner column points at an account that no longer
    // exists — a deletion between submission and decision.
    $vendorId = (string) Str::uuid();
    DB::table('marketplace_vendors')->insert([
        'id' => $vendorId, 'owner_user_id' => (string) Str::uuid(), 'name' => 'Orphan Kitchen',
        'slug' => 'orphan-'.substr($vendorId, 0, 8), 'type' => 'restaurant', 'category' => 'african', 'status' => 'pending',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $service = app(VerificationService::class);
    $case = $service->openCase(SubjectType::Business, $vendorId, CaseType::Business, 'NG');

    // An unreachable contact must mean "no email", never a failed
    // verification: the case has to complete regardless.
    $started = $service->startVerification($case->id(), ['registry']);

    expect($started->id())->toBe($case->id())
        ->and($started->status()->value)->toBe('pending')
        ->and($emails->sent)->toBeEmpty();
});

// ------------------------------------------------------------- privacy -------

it('never lets identity data reach an email', function (): void {
    $emails = recordEmails();
    ['id' => $riderId] = notifiedUser($this, 'privacy@example.com');

    $service = app(VerificationService::class);
    $case = $service->openCase(SubjectType::Rider, $riderId, CaseType::Identity, 'NG');
    $started = $service->startVerification($case->id(), ['document']);

    $documents = app(DocumentMetadataRepository::class);
    $documents->save(new DocumentMetadata(
        id: $documents->nextIdentity(),
        caseId: $started->id(),
        documentType: DocumentType::DriversLicence,
        issuingCountry: 'NG',
        numberLast4: DocumentMetadata::lastFourOf('AKW 12345678'),
        expiresOn: new DateTimeImmutable('2030-01-01'),
        providerReference: 'sess_secret_reference',
        createdAt: new DateTimeImmutable(),
    ));

    app(ReviewService::class)->approve($started->id(), (string) Str::uuid());

    $text = $emails->allText();

    // None of this may travel: not the document fragment, not the provider
    // session that could be used to look the case up, not the document type.
    expect($text)->not->toContain('5678')
        ->and($text)->not->toContain('12345678')
        ->and($text)->not->toContain('sess_secret_reference')
        ->and($text)->not->toContain('drivers_licence')
        ->and($text)->not->toContain('AKW');
});

it('strips a regulated field even if a template asks for it', function (): void {
    $translator = new EruoFood\Notifications\Application\Service\EventTranslator(
        app(EruoFood\Notifications\Application\Service\NotificationService::class),
        [],
    );

    $reflection = new ReflectionMethod($translator, 'redact');

    $cleaned = $reflection->invoke($translator, [
        'case_id' => 'case-1',
        'document_number' => 'A01234567',
        'registration_number' => 'RC123456',
        'provider_reference' => 'sess_1',
        'date_of_birth' => '1990-01-01',
        'phone' => '+2348030000000',
        'api_key' => 'sk_live_xyz',
        'status' => 'verified',
    ]);

    // The deny-list is the backstop behind the allow-list: an entry added later
    // without `fields` still cannot carry these.
    expect($cleaned)->toHaveKey('case_id')
        ->and($cleaned)->toHaveKey('status')
        ->and($cleaned)->not->toHaveKey('document_number')
        ->and($cleaned)->not->toHaveKey('registration_number')
        ->and($cleaned)->not->toHaveKey('provider_reference')
        ->and($cleaned)->not->toHaveKey('date_of_birth')
        ->and($cleaned)->not->toHaveKey('phone')
        ->and($cleaned)->not->toHaveKey('api_key');
});

it('classifies verification mail as security, never marketing', function (): void {
    ['id' => $riderId] = notifiedUser($this, 'class@example.com');

    $service = app(VerificationService::class);
    $case = $service->openCase(SubjectType::Rider, $riderId, CaseType::Identity, 'NG');
    $service->startVerification($case->id(), ['document']);

    $stored = notificationsFor($riderId, 'verification_submitted');

    expect($stored[0]->notification_class)->toBe(NotificationClass::Security->value);
});

it('keeps sending verification mail to someone who unsubscribed from marketing', function (): void {
    $emails = recordEmails();
    ['token' => $token, 'id' => $riderId] = notifiedUser($this, 'unsub-verif@example.com');

    $this->withToken($token)->putJson('/api/v1/notifications/preferences/marketing', ['opt_in' => false])
        ->assertOk();

    $service = app(VerificationService::class);
    $case = $service->openCase(SubjectType::Rider, $riderId, CaseType::Identity, 'NG');
    $service->startVerification($case->id(), ['document']);

    // Somebody who silenced campaigns has not asked to stop hearing that their
    // identity verification failed — and an attacker who could arrange that
    // would have removed the platform's only warning.
    expect(notificationsFor($riderId, 'verification_submitted'))->not->toBeEmpty()
        ->and($emails->sent)->not->toBeEmpty();
});

it('puts no unsubscribe header on a security email', function (): void {
    $emails = recordEmails();
    ['id' => $riderId] = notifiedUser($this, 'noheader@example.com');

    $service = app(VerificationService::class);
    $case = $service->openCase(SubjectType::Rider, $riderId, CaseType::Identity, 'NG');
    $service->startVerification($case->id(), ['document']);

    // Offering one-click opt-out of a verification notice would be worse than
    // useless, and trains mail clients to present it.
    foreach ($emails->sent as $message) {
        expect($message->headers)->not->toHaveKey('List-Unsubscribe');
    }
});

it('directs the reader to the application rather than explaining in the email', function (): void {
    config()->set('notifications.email.app_url', 'https://app.example.test');
    $emails = recordEmails();
    ['id' => $riderId] = notifiedUser($this, 'actionlink@example.com');

    $service = app(VerificationService::class);
    $case = $service->openCase(SubjectType::Rider, $riderId, CaseType::Identity, 'NG');
    $started = $service->startVerification($case->id(), ['document']);
    app(ReviewService::class)->reject($started->id(), (string) Str::uuid(), RejectionReason::DocumentUnreadable);

    expect($emails->allText())->toContain('https://app.example.test/account/verification');
});

// -------------------------------------------------- delivery + robustness ----

it('records the provider message id and the correlation id', function (): void {
    recordEmails();
    ['id' => $riderId] = notifiedUser($this, 'delivery@example.com');

    $service = app(VerificationService::class);
    $case = $service->openCase(SubjectType::Rider, $riderId, CaseType::Identity, 'NG');
    $started = $service->startVerification($case->id(), ['document']);

    $email = NotificationModel::query()
        ->where('user_id', $riderId)->where('channel', 'email')->first();

    expect($email->provider_message_id)->not->toBeNull()
        // The correlation id ties the message back to the case that caused it,
        // so a support thread can be followed across contexts.
        ->and($email->correlation_id)->toBe($started->id())
        ->and($email->status)->toBe('delivered');
});

it('does not fail a verification when email delivery throws', function (): void {
    $broken = new class () implements EmailProvider {
        public function name(): string
        {
            return 'broken';
        }

        public function send(EmailMessage $message): EruoFood\Notifications\Application\DTO\EmailDispatchResult
        {
            throw new RuntimeException('the mail host fell over');
        }
    };

    app()->instance(EmailProvider::class, $broken);
    app()->forgetInstance(EruoFood\Notifications\Application\Service\ChannelDispatcher::class);
    app()->forgetInstance(EruoFood\Notifications\Application\Service\NotificationService::class);

    ['id' => $riderId] = notifiedUser($this, 'brokenmail@example.com');

    $service = app(VerificationService::class);
    $case = $service->openCase(SubjectType::Rider, $riderId, CaseType::Identity, 'NG');

    // The verification must complete. A rider does not stay unverified because
    // an SMTP host was briefly unreachable.
    $started = $service->startVerification($case->id(), ['document']);

    expect($started->status()->value)->toBe('pending');

    $email = NotificationModel::query()
        ->where('user_id', $riderId)->where('channel', 'email')->first();

    expect($email->status)->toBe('failed');
});

it('does not retry an address that will never accept mail', function (): void {
    // A recipient the resolver cannot address at all.
    app()->instance(RecipientResolver::class, new class () implements RecipientResolver {
        public function resolve(string $userId): ?Recipient
        {
            return new Recipient($userId, null, 'Nobody');
        }
    });
    app()->forgetInstance(EruoFood\Notifications\Application\Service\ChannelDispatcher::class);
    app()->forgetInstance(EruoFood\Notifications\Application\Service\NotificationService::class);

    ['id' => $riderId] = notifiedUser($this, 'noaddress@example.com');

    $service = app(VerificationService::class);
    $case = $service->openCase(SubjectType::Rider, $riderId, CaseType::Identity, 'NG');
    $service->startVerification($case->id(), ['document']);

    $retried = app(EruoFood\Notifications\Application\Service\NotificationService::class)->retryFailed();

    // Re-attempting a permanent failure every cycle until the cap burns quota
    // and buries real failures in the noise.
    expect($retried)->toBe(0);
});

it('logs a digest of the recipient rather than their address', function (): void {
    $records = [];
    Illuminate\Support\Facades\Log::listen(function ($message) use (&$records): void {
        $records[] = json_encode([$message->message, $message->context]);
    });

    (new LogEmailProvider(app(Psr\Log\LoggerInterface::class)))->send(new EmailMessage(
        toAddress: 'someone@example.com',
        toName: 'Someone',
        subject: 'Your identity is verified',
        htmlBody: '<p>hello</p>',
        textBody: 'hello',
    ));

    $logged = implode("\n", $records);

    // A notification log is read by more people and kept longer than the
    // identity store; it is not the place for a second copy of every address.
    expect($logged)->not->toContain('someone@example.com')
        ->and($logged)->toContain('recipient_sha256');
});

it('escapes anything interpolated into the email body', function (): void {
    $renderer = new EmailBodyRenderer('EruoFood', 'https://app.example.test', 'help@example.test');

    $recipient = new Recipient('u1', 'x@example.test', '<script>alert(1)</script> Smith');

    $notification = EruoFood\Notifications\Domain\Notification\Notification::create(
        'n1',
        'u1',
        EruoFood\Notifications\Domain\Enum\NotificationCategory::Verification,
        EruoFood\Notifications\Domain\Enum\NotificationChannel::Email,
        'verification_submitted',
        [],
        new EruoFood\Notifications\Domain\ValueObject\RenderedContent('Subject', '<img src=x onerror=alert(1)>'),
        EruoFood\Notifications\Domain\Enum\Priority::Normal,
        null,
        new DateTimeImmutable(),
    );

    $body = $renderer->render($notification, $recipient);

    // Notification bodies carry data that originated outside the platform — a
    // trading name, a reviewer's note — into HTML a mail client will render.
    // The test is that nothing can become markup. The literal characters may
    // survive as inert text — what must not survive is a bracket that would
    // let a mail client parse them as a tag or an attribute.
    expect($body->html)->not->toContain('<script>')
        ->and($body->html)->not->toContain('<img')
        ->and($body->html)->toContain('&lt;script&gt;')
        ->and($body->html)->toContain('&lt;img');
});
