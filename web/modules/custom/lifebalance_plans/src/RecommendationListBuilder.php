<?php

declare(strict_types=1);

namespace Drupal\lifebalance_plans;

use Drupal\Core\Entity\EntityListBuilder;

/**
 * Supplies operation links for the Views list.
 *
 * The list itself is the view at /admin/lifebalance/recommendations.
 * This builder is not a second listing.
 */
class RecommendationListBuilder extends EntityListBuilder {}
