<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Drush\Commands;

use Drupal\lifebalance\Service\CriterionImporter;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Imports the criteria catalog.
 */
final class LifeBalanceCommands extends DrushCommands {

  public function __construct(
    protected CriterionImporter $importer,
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
    return new self($container->get(CriterionImporter::class));
  }

  /**
   * Imports criteria. Repeated imports keep keys and skip unchanged rows.
   */
  #[CLI\Command(name: 'lifebalance:import-criteria')]
  #[CLI\Argument(name: 'file', description: 'Path to a criteria YAML file. Defaults to the shipped catalog.')]
  #[CLI\Usage(name: 'drush lifebalance:import-criteria', description: 'Import the shipped criteria catalog.')]
  public function importCriteria(?string $file = NULL): void {
    $result = $this->importer->import($file);
    $this->io()->success(sprintf(
      'Criteria import: %d created, %d updated, %d unchanged, %d warnings.',
      $result->created,
      $result->updated,
      $result->unchanged,
      $result->warned,
    ));
  }

}
