<?php

/**
 * @file
 * Deploy hooks for the recommendation library.
 */

declare(strict_types=1);

use Drupal\lifebalance_plans\Service\RecommendationImporter;

/**
 * Imports the shipped recommendation catalog as drafts.
 *
 * @param array<string, mixed> $sandbox
 *   Batch sandbox. Unused; the catalog import is a single step.
 */
function lifebalance_plans_deploy_import_recommendations(array &$sandbox): string {
  $result = \Drupal::service(RecommendationImporter::class)->import();
  return (string) t('Recommendation import: @created created, @updated updated, @unchanged unchanged, @warned warnings.', [
    '@created' => $result->created,
    '@updated' => $result->updated,
    '@unchanged' => $result->unchanged,
    '@warned' => $result->warned,
  ]);
}
