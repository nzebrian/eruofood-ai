<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Cms;

/** The lifecycle state of a CMS content item. */
enum PublishStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
