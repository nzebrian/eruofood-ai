<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Cms;

/** The kind of a CMS content item. Pages, blog posts, news/announcements, and
 *  the legal documents all share one aggregate and are told apart by this. */
enum ContentType: string
{
    case Page = 'page';                 // generic static page / homepage block
    case Blog = 'blog';                 // blog article
    case News = 'news';                 // news item / announcement
    case Legal = 'legal';               // terms, privacy, policy documents
    case HelpArticle = 'help_article';  // help-centre article

    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }
}
