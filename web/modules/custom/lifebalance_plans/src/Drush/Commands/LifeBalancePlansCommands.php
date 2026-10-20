<?php

declare(strict_types=1);

namespace Drupal\lifebalance_plans\Drush\Commands;

use Drupal\lifebalance_plans\Service\RecommendationImporter;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Imports the recommendation catalog.
 */
final class LifeBalancePlansCommands extends DrushCommands {

  public function __construct(
    protected RecommendationImporter $importer,
  ) {
    parent::__construct();
  }

  /**
   * Creates the command from the Drupal container.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The Drupal service container.
   * @param mixed $drush_container
   *   Drush passes its own container as the second argument.
   */
  public static function create(ContainerInterface $container, mixed $drush_container = NULL): self {
    unset($drush_container);
    return new self($container->get(RecommendationImporter::class));
  }

  /**
   * Imports recommendations. Unchanged rows are skipped.
   */
  #[CLI\Command(name: 'lifebalance:import-recommendations')]
  #[CLI\Argument(name: 'file', description: 'Path to a recommendation YAML file. Defaults to the shipped catalog.')]
  #[CLI\Usage(name: 'drush lifebalance:import-recommendations', description: 'Import the shipped recommendation catalog.')]
  public function importRecommendations(?string $file = NULL): void {
    $result = $this->importer->import($file);
    $this->io()->success(sprintf(
      'Recommendation import: %d created, %d updated, %d unchanged, %d warnings.',
      $result->created,
      $result->updated,
      $result->unchanged,
      $result->warned,
    ));
  }

}
