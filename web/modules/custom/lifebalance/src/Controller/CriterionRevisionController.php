<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\lifebalance\Entity\Criterion;
use Drupal\lifebalance\Repository\CriterionRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shows the wording history of one criterion. Revisions cannot be deleted.
 */
final class CriterionRevisionController extends ControllerBase {

  public function __construct(
    protected CriterionRepository $criterionRepository,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(CriterionRepository::class),
      $container->get('date.formatter'),
    );
  }

  /**
   * Builds the revision table.
   *
   * @param \Drupal\lifebalance\Entity\Criterion $lifebalance_criterion
   *   The criterion from the route.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function list(Criterion $lifebalance_criterion): array {
    $current_id = (int) $lifebalance_criterion->getRevisionId();
    $ids = $this->criterionRepository->revisionIds($lifebalance_criterion);
    $rows = [];
    foreach (array_reverse($ids) as $revision_id) {
      $revision = $this->criterionRepository->loadRevision($revision_id);
      if (!$revision instanceof Criterion) {
        continue;
      }
      $created = (int) $revision->getRevisionCreationTime();
      $help = $revision->getHelp();
      $rows[] = [
        ['data' => ['#plain_text' => (string) $revision_id]],
        ['data' => ['#plain_text' => $created > 0 ? $this->dateFormatter->format($created, 'custom', 'd.m.Y H:i') : '']],
        ['data' => ['#plain_text' => $revision->getText()]],
        ['data' => ['#plain_text' => $help !== '' ? $help : '—']],
        ['data' => ['#plain_text' => $revision_id === $current_id ? (string) $this->t('Поточна') : '']],
      ];
    }

    $build = [];
    $build['summary'] = [
      '#type' => 'markup',
      '#plain_text' => (string) $this->t('Ключ @key не змінюється. Ревізії не видаляються.', [
        '@key' => $lifebalance_criterion->getCatalogKey(),
      ]),
    ];
    $build['links'] = [
      '#theme' => 'links',
      '#links' => [
        [
          'title' => $this->t('Редагувати'),
          'url' => $lifebalance_criterion->toUrl('edit-form'),
        ],
        [
          'title' => $this->t('До списку'),
          'url' => Url::fromRoute('entity.lifebalance_criterion.collection'),
        ],
      ],
    ];
    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Ревізія'),
        $this->t('Час'),
        $this->t('Формулювання'),
        $this->t('Пояснення'),
        $this->t('Стан'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('Ревізій немає.'),
    ];
    $build['#cache'] = [
      'contexts' => ['user.permissions'],
      'tags' => $lifebalance_criterion->getCacheTags(),
    ];
    return $build;
  }

}
