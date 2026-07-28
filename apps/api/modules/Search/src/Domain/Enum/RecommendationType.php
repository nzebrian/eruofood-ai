<?php

declare(strict_types=1);

namespace EruoFood\Search\Domain\Enum;

/**
 * The kind of recommendation requested. Each maps to a strategy in the
 * RecommendationService: content-based (vector similarity), behavioural
 * (co-view), or popularity/seasonal fallbacks.
 */
enum RecommendationType: string
{
    case Related = 'related';                             // same type, vector-similar
    case Similar = 'similar';                             // alias of related, cross-type allowed
    case Restaurant = 'restaurant';                       // recommended restaurants/vendors
    case Personalised = 'personalised';                   // from the user's recent activity
    case FrequentlyViewedTogether = 'frequently_viewed_together'; // co-view behavioural
    case Seasonal = 'seasonal';                           // seasonal boost over popularity
    case Trending = 'trending';                           // most-clicked recently
}
