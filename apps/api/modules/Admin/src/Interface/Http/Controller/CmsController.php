<?php

declare(strict_types=1);

namespace EruoFood\Admin\Interface\Http\Controller;

use DateTimeImmutable;
use EruoFood\Admin\Application\Service\AdminPresenter;
use EruoFood\Admin\Application\Service\CmsService;
use EruoFood\Admin\Application\Service\PermissionService;
use EruoFood\Admin\Domain\Cms\ContentType;
use EruoFood\Admin\Domain\Cms\PublishStatus;
use EruoFood\Admin\Domain\Rbac\Permission;
use EruoFood\Admin\Interface\Http\Concerns\AuthorizesAdmin;
use EruoFood\Admin\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Content Management: pages/blog/news/legal/help content, banners and FAQs. */
final class CmsController
{
    use AuthorizesAdmin;
    use RespondsWithData;

    public function __construct(
        private readonly PermissionService $permissions,
        private readonly CmsService $cms,
        private readonly AdminPresenter $presenter,
    ) {
    }

    protected function permissions(): PermissionService
    {
        return $this->permissions;
    }

    // ---- Pages -----------------------------------------------------------

    public function listPages(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request, Permission::CONTENT_MANAGE);
        $type = $request->query('type');
        $status = $request->query('status');

        return $this->paginated(
            $this->cms->listPages(
                is_string($type) ? ContentType::tryFrom($type) : null,
                is_string($status) ? PublishStatus::tryFrom($status) : null,
                (int) $request->query('page', '1'),
                (int) $request->query('per_page', '20'),
            ),
            fn ($p): array => $this->presenter->page($p),
        );
    }

    public function showPage(Request $request, string $id): JsonResponse
    {
        $this->authorizeAdmin($request, Permission::CONTENT_MANAGE);

        return $this->data($this->presenter->page($this->cms->getPage($id)));
    }

    public function createPage(Request $request): JsonResponse
    {
        $actor = $this->authorizeAdmin($request, Permission::CONTENT_MANAGE);
        $data = $request->validate([
            'type' => ['required', 'in:'.implode(',', array_map(static fn (ContentType $t): string => $t->value, ContentType::cases()))],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'excerpt' => ['nullable', 'string'],
            'slug' => ['nullable', 'string'],
            'seo' => ['nullable', 'array'],
        ]);
        $page = $this->cms->createPage(
            $actor,
            ContentType::from($data['type']),
            $data['title'],
            $data['body'],
            $data['excerpt'] ?? null,
            $data['slug'] ?? null,
            $data['seo'] ?? [],
        );

        return $this->data($this->presenter->page($page), 201);
    }

    public function updatePage(Request $request, string $id): JsonResponse
    {
        $actor = $this->authorizeAdmin($request, Permission::CONTENT_MANAGE);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'excerpt' => ['nullable', 'string'],
            'seo' => ['nullable', 'array'],
        ]);

        return $this->data($this->presenter->page(
            $this->cms->updatePage($actor, $id, $data['title'], $data['body'], $data['excerpt'] ?? null, $data['seo'] ?? []),
        ));
    }

    public function publishPage(Request $request, string $id): JsonResponse
    {
        $actor = $this->authorizeAdmin($request, Permission::CONTENT_MANAGE);

        return $this->data($this->presenter->page($this->cms->publishPage($actor, $id)));
    }

    public function unpublishPage(Request $request, string $id): JsonResponse
    {
        $actor = $this->authorizeAdmin($request, Permission::CONTENT_MANAGE);

        return $this->data($this->presenter->page($this->cms->unpublishPage($actor, $id)));
    }

    public function archivePage(Request $request, string $id): JsonResponse
    {
        $actor = $this->authorizeAdmin($request, Permission::CONTENT_MANAGE);

        return $this->data($this->presenter->page($this->cms->archivePage($actor, $id)));
    }

    // ---- Banners ---------------------------------------------------------

    public function listBanners(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request, Permission::CONTENT_MANAGE);
        $placement = $request->query('placement');

        return $this->data(['banners' => array_map(
            fn ($b): array => $this->presenter->banner($b),
            $this->cms->listBanners(is_string($placement) ? $placement : null),
        )]);
    }

    public function createBanner(Request $request): JsonResponse
    {
        $actor = $this->authorizeAdmin($request, Permission::CONTENT_MANAGE);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'image_url' => ['required', 'string', 'max:1000'],
            'link_url' => ['nullable', 'string', 'max:1000'],
            'placement' => ['required', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
        ]);
        $banner = $this->cms->createBanner(
            $actor,
            $data['title'],
            $data['image_url'],
            $data['link_url'] ?? null,
            $data['placement'],
            (int) ($data['sort_order'] ?? 0),
            isset($data['starts_at']) ? new DateTimeImmutable((string) $data['starts_at']) : null,
            isset($data['ends_at']) ? new DateTimeImmutable((string) $data['ends_at']) : null,
        );

        return $this->data($this->presenter->banner($banner), 201);
    }

    public function setBannerActive(Request $request, string $id): JsonResponse
    {
        $actor = $this->authorizeAdmin($request, Permission::CONTENT_MANAGE);
        $data = $request->validate(['active' => ['required', 'boolean']]);

        return $this->data($this->presenter->banner($this->cms->setBannerActive($actor, $id, (bool) $data['active'])));
    }

    public function deleteBanner(Request $request, string $id): JsonResponse
    {
        $actor = $this->authorizeAdmin($request, Permission::CONTENT_MANAGE);
        $this->cms->deleteBanner($actor, $id);

        return new JsonResponse(null, 204);
    }

    // ---- FAQ -------------------------------------------------------------

    public function listFaqs(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request, Permission::CONTENT_MANAGE);
        $category = $request->query('category');

        return $this->data(['faqs' => array_map(
            fn ($f): array => $this->presenter->faq($f),
            $this->cms->listFaqs(is_string($category) ? $category : null),
        )]);
    }

    public function createFaq(Request $request): JsonResponse
    {
        $actor = $this->authorizeAdmin($request, Permission::CONTENT_MANAGE);
        $data = $request->validate([
            'question' => ['required', 'string', 'max:500'],
            'answer' => ['required', 'string'],
            'category' => ['required', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer'],
        ]);
        $item = $this->cms->createFaq($actor, $data['question'], $data['answer'], $data['category'], (int) ($data['sort_order'] ?? 0));

        return $this->data($this->presenter->faq($item), 201);
    }

    public function updateFaq(Request $request, string $id): JsonResponse
    {
        $actor = $this->authorizeAdmin($request, Permission::CONTENT_MANAGE);
        $data = $request->validate([
            'question' => ['required', 'string', 'max:500'],
            'answer' => ['required', 'string'],
            'category' => ['required', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        return $this->data($this->presenter->faq(
            $this->cms->updateFaq($actor, $id, $data['question'], $data['answer'], $data['category'], (int) ($data['sort_order'] ?? 0)),
        ));
    }

    public function deleteFaq(Request $request, string $id): JsonResponse
    {
        $actor = $this->authorizeAdmin($request, Permission::CONTENT_MANAGE);
        $this->cms->deleteFaq($actor, $id);

        return new JsonResponse(null, 204);
    }
}
