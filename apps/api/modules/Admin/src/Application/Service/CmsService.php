<?php

declare(strict_types=1);

namespace EruoFood\Admin\Application\Service;

use DateTimeImmutable;
use EruoFood\Admin\Domain\Cms\Banner;
use EruoFood\Admin\Domain\Cms\BannerRepository;
use EruoFood\Admin\Domain\Cms\CmsPage;
use EruoFood\Admin\Domain\Cms\CmsPageRepository;
use EruoFood\Admin\Domain\Cms\ContentType;
use EruoFood\Admin\Domain\Cms\FaqItem;
use EruoFood\Admin\Domain\Cms\FaqRepository;
use EruoFood\Admin\Domain\Cms\PublishStatus;
use EruoFood\Admin\Domain\Cms\SeoMetadata;
use EruoFood\Admin\Domain\Enum\AuditCategory;
use EruoFood\Admin\Domain\Exception\AdminConflict;
use EruoFood\Admin\Domain\Exception\AdminNotFound;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Shared\Domain\ValueObject\Slug;

/**
 * The CMS use cases: managed pages/blog/news/legal/help content, dynamic
 * banners, and FAQ entries. Content edits are audit-logged under the Content
 * category. Slugs are unique per content type.
 */
final readonly class CmsService
{
    public function __construct(
        private CmsPageRepository $pages,
        private BannerRepository $banners,
        private FaqRepository $faqs,
        private AuditService $audit,
    ) {
    }

    // ---- Pages -----------------------------------------------------------

    /**
     * @param array<string, mixed> $seo
     */
    public function createPage(
        string $actorId,
        ContentType $type,
        string $title,
        string $body,
        ?string $excerpt,
        ?string $slug,
        array $seo,
    ): CmsPage {
        $slugVo = $slug !== null ? new Slug($slug) : Slug::fromTitle($title);
        if ($this->pages->findByTypeAndSlug($type, $slugVo) !== null) {
            throw new AdminConflict(sprintf('A %s page with slug "%s" already exists.', $type->value, $slugVo->value));
        }

        $page = CmsPage::draft(
            $this->pages->nextIdentity(),
            $type,
            $slugVo,
            $title,
            $body,
            $excerpt,
            $this->seo($seo),
            $actorId,
            new DateTimeImmutable(),
        );
        $this->pages->save($page);
        $this->audit->record($actorId, AuditCategory::Content, 'cms.page_created', 'cms_page', $page->id(), [
            'type' => $type->value,
            'slug' => $slugVo->value,
        ]);

        return $page;
    }

    /**
     * @param array<string, mixed> $seo
     */
    public function updatePage(string $actorId, string $id, string $title, string $body, ?string $excerpt, array $seo): CmsPage
    {
        $page = $this->requirePage($id);
        $page->edit($title, $body, $excerpt, $this->seo($seo), new DateTimeImmutable());
        $this->pages->save($page);
        $this->audit->record($actorId, AuditCategory::Content, 'cms.page_updated', 'cms_page', $id);

        return $page;
    }

    public function publishPage(string $actorId, string $id): CmsPage
    {
        $page = $this->requirePage($id);
        $page->publish(new DateTimeImmutable());
        $this->pages->save($page);
        $this->audit->record($actorId, AuditCategory::Content, 'cms.page_published', 'cms_page', $id);

        return $page;
    }

    public function unpublishPage(string $actorId, string $id): CmsPage
    {
        $page = $this->requirePage($id);
        $page->unpublish(new DateTimeImmutable());
        $this->pages->save($page);
        $this->audit->record($actorId, AuditCategory::Content, 'cms.page_unpublished', 'cms_page', $id);

        return $page;
    }

    public function archivePage(string $actorId, string $id): CmsPage
    {
        $page = $this->requirePage($id);
        $page->archive(new DateTimeImmutable());
        $this->pages->save($page);
        $this->audit->record($actorId, AuditCategory::Content, 'cms.page_archived', 'cms_page', $id);

        return $page;
    }

    /**
     * @return Paginated<CmsPage>
     */
    public function listPages(?ContentType $type, ?PublishStatus $status, int $page, int $perPage): Paginated
    {
        return $this->pages->search($type, $status, $page, $perPage);
    }

    public function getPage(string $id): CmsPage
    {
        return $this->requirePage($id);
    }

    // ---- Banners ---------------------------------------------------------

    public function createBanner(
        string $actorId,
        string $title,
        string $imageUrl,
        ?string $linkUrl,
        string $placement,
        int $sortOrder,
        ?DateTimeImmutable $startsAt,
        ?DateTimeImmutable $endsAt,
    ): Banner {
        $banner = Banner::create(
            $this->banners->nextIdentity(),
            $title,
            $imageUrl,
            $linkUrl,
            $placement,
            $sortOrder,
            $startsAt,
            $endsAt,
            new DateTimeImmutable(),
        );
        $this->banners->save($banner);
        $this->audit->record($actorId, AuditCategory::Content, 'cms.banner_created', 'banner', $banner->id());

        return $banner;
    }

    public function setBannerActive(string $actorId, string $id, bool $active): Banner
    {
        $banner = $this->banners->findById($id) ?? throw AdminNotFound::of('banner', $id);
        $active ? $banner->activate() : $banner->deactivate();
        $this->banners->save($banner);
        $this->audit->record($actorId, AuditCategory::Content, $active ? 'cms.banner_activated' : 'cms.banner_deactivated', 'banner', $id);

        return $banner;
    }

    public function deleteBanner(string $actorId, string $id): void
    {
        $banner = $this->banners->findById($id) ?? throw AdminNotFound::of('banner', $id);
        $this->banners->delete($banner->id());
        $this->audit->record($actorId, AuditCategory::Content, 'cms.banner_deleted', 'banner', $id);
    }

    /** @return list<Banner> */
    public function listBanners(?string $placement = null): array
    {
        return $this->banners->all($placement);
    }

    // ---- FAQ -------------------------------------------------------------

    public function createFaq(string $actorId, string $question, string $answer, string $category, int $sortOrder): FaqItem
    {
        $item = FaqItem::create($this->faqs->nextIdentity(), $question, $answer, $category, $sortOrder, new DateTimeImmutable());
        $this->faqs->save($item);
        $this->audit->record($actorId, AuditCategory::Content, 'cms.faq_created', 'faq', $item->id());

        return $item;
    }

    public function updateFaq(string $actorId, string $id, string $question, string $answer, string $category, int $sortOrder): FaqItem
    {
        $item = $this->faqs->findById($id) ?? throw AdminNotFound::of('faq', $id);
        $item->update($question, $answer, $category, $sortOrder, new DateTimeImmutable());
        $this->faqs->save($item);
        $this->audit->record($actorId, AuditCategory::Content, 'cms.faq_updated', 'faq', $id);

        return $item;
    }

    public function deleteFaq(string $actorId, string $id): void
    {
        $item = $this->faqs->findById($id) ?? throw AdminNotFound::of('faq', $id);
        $this->faqs->delete($item->id());
        $this->audit->record($actorId, AuditCategory::Content, 'cms.faq_deleted', 'faq', $id);
    }

    /** @return list<FaqItem> */
    public function listFaqs(?string $category = null): array
    {
        return $this->faqs->all($category);
    }

    private function requirePage(string $id): CmsPage
    {
        return $this->pages->findById($id) ?? throw AdminNotFound::of('cms page', $id);
    }

    /**
     * @param array<string, mixed> $seo
     */
    private function seo(array $seo): SeoMetadata
    {
        $keywords = $seo['keywords'] ?? [];

        return new SeoMetadata(
            isset($seo['meta_title']) ? (string) $seo['meta_title'] : null,
            isset($seo['meta_description']) ? (string) $seo['meta_description'] : null,
            is_array($keywords) ? array_values(array_map('strval', $keywords)) : [],
            isset($seo['og_image']) ? (string) $seo['og_image'] : null,
        );
    }
}
