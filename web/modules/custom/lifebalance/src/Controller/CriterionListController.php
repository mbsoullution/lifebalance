<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Url;
use Drupal\lifebalance\Entity\Criterion;
use Drupal\lifebalance\Form\CriterionFilterForm;
use Drupal\lifebalance\Repository\CriterionRepository;
use Drupal\lifebalance\Repository\SectorRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Lists criteria with sector, tier, and status filters.
 */
final class CriterionListController extends ControllerBase {

  private const PER_PAGE = 50;

  public function __construct(
    protected CriterionRepository $criterionRepository,
    protected SectorRepository $sectorRepository,
    protected PagerManagerInterface $pagerManager,
    protected RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(CriterionRepository::class),
      $container->get(SectorRepository::class),
      $container->get('pager.manager'),
      $container->get('request_stack'),
    );
  }

  /**
   * Builds the filtered criteria table.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function list(): array {
    $sector = $this->queryValue('sector');
    $tier = $this->queryValue('tier');
    $status = $this->queryValue('status');
    $all = $this->sortForAdmin($this->criterionRepository->loadByFilters($sector, $tier, $status));
    $this->pagerManager->createPager(count($all), self::PER_PAGE);
    $page = $this->pagerManager->findPage();
    $items = array_slice($all, $page * self::PER_PAGE, self::PER_PAGE);

    $sector_labels = [];
    foreach ($this->sectorRepository->loadAll() as $id => $sector_entity) {
      $sector_labels[$id] = (string) $sector_entity->label();
    }

    $rows = [];
    foreach ($items as $criterion) {
      $links = [
        'edit' => [
          'title' => $this->t('Редагувати'),
          'url' => $criterion->toUrl('edit-form'),
        ],
        'revisions' => [
          'title' => $this->t('Ревізії'),
          'url' => $criterion->toUrl('revisions'),
        ],
      ];
      if ($criterion->getStatus() !== Criterion::STATUS_ARCHIVED) {
        $links['archive'] = [
          'title' => $this->t('Архівувати'),
          'url' => $criterion->toUrl('archive'),
        ];
      }
      $sector_id = $criterion->getSectorId();
      $rows[] = [
        ['data' => ['#plain_text' => $criterion->getCatalogKey()]],
        ['data' => ['#plain_text' => $sector_labels[$sector_id] ?? $sector_id]],
        ['data' => ['#plain_text' => (string) (Criterion::tierLabels()[$criterion->getTier()] ?? $criterion->getTier())]],
        ['data' => ['#plain_text' => $criterion->getText()]],
        ['data' => ['#plain_text' => (string) (Criterion::statusLabels()[$criterion->getStatus()] ?? $criterion->getStatus())]],
        ['data' => ['#plain_text' => (string) $criterion->getWeight()]],
        [
          'data' => [
            '#type' => 'operations',
            '#links' => $links,
          ],
        ],
      ];
    }

    $build = [];
    $build['help'] = [
      '#type' => 'markup',
      '#plain_text' => (string) $this->t('Критерій не видаляється. Його можна архівувати.'),
    ];
    $build['add'] = [
      '#type' => 'link',
      '#title' => $this->t('Додати критерій'),
      '#url' => Url::fromRoute('entity.lifebalance_criterion.add_form'),
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];
    $build['filters'] = $this->formBuilder()->getForm(CriterionFilterForm::class);
    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Ключ'),
        $this->t('Сектор'),
        $this->t('Рівень'),
        $this->t('Формулювання'),
        $this->t('Статус'),
        $this->t('Порядок'),
        $this->t('Дії'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('Немає критеріїв за цими фільтрами.'),
    ];
    $build['pager'] = [
      '#type' => 'pager',
    ];
    $build['#cache'] = [
      'contexts' => [
        'url.query_args:sector',
        'url.query_args:tier',
        'url.query_args:status',
        'url.query_args:page',
        'user.permissions',
      ],
      'tags' => $this->entityTypeManager()->getDefinition('lifebalance_criterion')->getListCacheTags(),
    ];
    return $build;
  }

  /**
   * Orders criteria by the sector weight, then by their own weight and key.
   *
   * @param list<\Drupal\lifebalance\Entity\Criterion> $criteria
   *   Criteria to sort.
   *
   * @return list<\Drupal\lifebalance\Entity\Criterion>
   *   Sorted criteria.
   */
  private function sortForAdmin(array $criteria): array {
    $weights = [];
    foreach ($this->sectorRepository->loadAll() as $id => $sector) {
      $weights[$id] = $sector->getWeight();
    }
    usort($criteria, static function (Criterion $a, Criterion $b) use ($weights): int {
      $sector_a = $weights[$a->getSectorId()] ?? 0;
      $sector_b = $weights[$b->getSectorId()] ?? 0;
      return [$sector_a, $a->getWeight(), $a->getCatalogKey()]
        <=> [$sector_b, $b->getWeight(), $b->getCatalogKey()];
    });
    return $criteria;
  }

  /**
   * Reads one filter from the current request.
   */
  private function queryValue(string $name): string {
    $request = $this->requestStack->getCurrentRequest();
    if ($request === NULL) {
      return '';
    }
    $value = $request->query->all()[$name] ?? '';
    return is_string($value) ? $value : '';
  }

}
