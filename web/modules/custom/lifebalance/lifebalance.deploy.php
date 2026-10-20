<?php

/**
 * @file
 * Deploy hooks for LifeBalance.
 */

declare(strict_types=1);

use Drupal\lifebalance\Service\CriterionImporter;

/**
 * Imports the shipped criteria catalog.
 *
 * @param array<string, mixed> $sandbox
 *   Batch sandbox. Unused; the catalog import is a single step.
 */
function lifebalance_deploy_import_criteria(array &$sandbox): string {
  $result = \Drupal::service(CriterionImporter::class)->import();
  return (string) t('Criteria import: @created created, @updated updated, @unchanged unchanged, @warned warnings.', [
    '@created' => $result->created,
    '@updated' => $result->updated,
    '@unchanged' => $result->unchanged,
    '@warned' => $result->warned,
  ]);
}
