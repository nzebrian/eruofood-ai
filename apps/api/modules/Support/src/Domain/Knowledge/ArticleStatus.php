<?php

declare(strict_types=1);

namespace EruoFood\Support\Domain\Knowledge;

/** The lifecycle state of a knowledge-base article. */
enum ArticleStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
