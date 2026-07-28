<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Interface\Http\Controller;

use EruoFood\Reviews\Application\Service\ReviewPresenter;
use EruoFood\Reviews\Application\Service\ReviewService;
use EruoFood\Reviews\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Reviews\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The public + customer review surface. Browsing a subject's published reviews
 * and its rating summary is open; submitting, editing, voting and (for subject
 * owners) responding require authentication. Every path goes through the Reviews
 * context — no business module stores or aggregates reviews itself.
 */
final class ReviewController
{
    use ResolvesAuthUser;
    use RespondsWithData;

    public function __construct(
        private readonly ReviewService $reviews,
        private readonly ReviewPresenter $presenter,
    ) {
    }

    /** Public — published reviews for a subject. */
    public function index(Request $request, string $subjectType, string $subjectId): JsonResponse
    {
        $subject = $this->subject($subjectType, $subjectId);
        $sort = (string) $request->query('sort', 'newest');
        $verifiedOnly = filter_var($request->query('verified', 'false'), FILTER_VALIDATE_BOOL);

        $page = $this->reviews->listForSubject(
            $subject,
            in_array($sort, ['newest', 'oldest', 'helpful', 'rating_desc', 'rating_asc'], true) ? $sort : 'newest',
            $verifiedOnly,
            (int) $request->query('page', '1'),
            (int) $request->query('per_page', '20'),
        );

        return $this->paginated($page, fn ($r): array => $this->presenter->review($r));
    }

    /** Public — the authoritative rating summary for a subject. */
    public function summary(string $subjectType, string $subjectId): JsonResponse
    {
        return $this->data($this->presenter->summary($this->reviews->summary($this->subject($subjectType, $subjectId))));
    }

    /** Public — a single review. */
    public function show(string $id): JsonResponse
    {
        return $this->data($this->presenter->review($this->reviews->get($id)));
    }

    public function store(Request $request): JsonResponse
    {
        $userId = $this->currentUserId($request);
        $data = $request->validate([
            'subject_type' => ['required', 'string', 'max:50'],
            'subject_id' => ['required', 'string', 'max:255'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:5000'],
            'photos' => ['nullable', 'array'],
            'photos.*' => ['string', 'max:2048'],
        ]);

        $review = $this->reviews->submit(
            $userId,
            $this->subject($data['subject_type'], $data['subject_id']),
            (int) $data['rating'],
            $data['title'] ?? null,
            $data['body'] ?? null,
            $this->photos($request),
        );

        return $this->data($this->presenter->review($review), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $userId = $this->currentUserId($request);
        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:5000'],
            'photos' => ['nullable', 'array'],
            'photos.*' => ['string', 'max:2048'],
        ]);

        $review = $this->reviews->edit(
            $id,
            $userId,
            (int) $data['rating'],
            $data['title'] ?? null,
            $data['body'] ?? null,
            $this->photos($request),
        );

        return $this->data($this->presenter->review($review));
    }

    public function vote(Request $request, string $id): JsonResponse
    {
        $this->currentUserId($request);
        $data = $request->validate(['helpful' => ['required', 'boolean']]);
        $review = $this->reviews->vote($id, (bool) $data['helpful']);

        return $this->data($this->presenter->review($review));
    }

    public function mine(Request $request): JsonResponse
    {
        $page = $this->reviews->myReviews(
            $this->currentUserId($request),
            (int) $request->query('page', '1'),
            (int) $request->query('per_page', '20'),
        );

        return $this->paginated($page, fn ($r): array => $this->presenter->review($r));
    }

    /** Subject-owner (vendor/restaurant) public response to a review. */
    public function respond(Request $request, string $id): JsonResponse
    {
        $responderId = $this->requireResponder($request);
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $review = $this->reviews->respond($id, $responderId, $data['body']);

        return $this->data($this->presenter->review($review));
    }

    /**
     * @return list<string>
     */
    private function photos(Request $request): array
    {
        /** @var list<mixed> $raw */
        $raw = (array) $request->input('photos', []);

        return array_values(array_map('strval', $raw));
    }
}
