<?php

declare(strict_types=1);

namespace EruoFood\Support\Interface\Http\Controller;

use EruoFood\Support\Application\Service\KnowledgeBaseService;
use EruoFood\Support\Application\Service\SupportPresenter;
use EruoFood\Support\Domain\Knowledge\ArticleStatus;
use EruoFood\Support\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Support\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The knowledge base: public browse/search/vote, and content-manager authoring. */
final class KnowledgeBaseController
{
    use ResolvesAuthUser;
    use RespondsWithData;

    public function __construct(
        private readonly KnowledgeBaseService $kb,
        private readonly SupportPresenter $presenter,
    ) {
    }

    // ---- Public ----------------------------------------------------------

    public function index(Request $request): JsonResponse
    {
        $term = $request->query('q');
        $category = $request->query('category');

        return $this->paginated(
            $this->kb->search(
                is_string($term) ? $term : null,
                is_string($category) ? $category : null,
                ArticleStatus::Published,
                (int) $request->query('page', '1'),
                (int) $request->query('per_page', '20'),
            ),
            fn ($a): array => $this->presenter->article($a),
        );
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        return $this->data($this->presenter->article($this->kb->getPublishedBySlug($slug)));
    }

    public function categories(): JsonResponse
    {
        return $this->data(['categories' => $this->kb->categories()]);
    }

    public function vote(Request $request, string $slug): JsonResponse
    {
        $data = $request->validate(['helpful' => ['required', 'boolean']]);

        return $this->data($this->presenter->article($this->kb->vote($slug, (bool) $data['helpful'])));
    }

    // ---- Content management (agent) --------------------------------------

    public function adminIndex(Request $request): JsonResponse
    {
        $this->requireAgent($request);
        $term = $request->query('q');
        $category = $request->query('category');
        $status = $request->query('status');

        return $this->paginated(
            $this->kb->search(
                is_string($term) ? $term : null,
                is_string($category) ? $category : null,
                is_string($status) ? ArticleStatus::tryFrom($status) : null,
                (int) $request->query('page', '1'),
                (int) $request->query('per_page', '20'),
            ),
            fn ($a): array => $this->presenter->article($a),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $authorId = $this->requireAgent($request);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'excerpt' => ['nullable', 'string'],
            'category' => ['required', 'string', 'max:100'],
            'slug' => ['nullable', 'string'],
            'tags' => ['nullable', 'array'],
        ]);

        $article = $this->kb->create(
            $authorId,
            $data['title'],
            $data['body'],
            $data['excerpt'] ?? null,
            $data['category'],
            $this->tags($request),
            $data['slug'] ?? null,
        );

        return $this->data($this->presenter->article($article), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->requireAgent($request);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'excerpt' => ['nullable', 'string'],
            'category' => ['required', 'string', 'max:100'],
            'tags' => ['nullable', 'array'],
        ]);

        return $this->data($this->presenter->article(
            $this->kb->update($id, $data['title'], $data['body'], $data['excerpt'] ?? null, $data['category'], $this->tags($request)),
        ));
    }

    public function publish(Request $request, string $id): JsonResponse
    {
        $this->requireAgent($request);

        return $this->data($this->presenter->article($this->kb->publish($id)));
    }

    public function archive(Request $request, string $id): JsonResponse
    {
        $this->requireAgent($request);

        return $this->data($this->presenter->article($this->kb->archive($id)));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->requireAgent($request);
        $this->kb->delete($id);

        return new JsonResponse(null, 204);
    }

    /**
     * @return list<string>
     */
    private function tags(Request $request): array
    {
        /** @var list<mixed> $raw */
        $raw = (array) $request->input('tags', []);

        return array_map('strval', $raw);
    }
}
