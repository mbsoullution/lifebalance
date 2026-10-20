<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\lifebalance\Service\AdminUserStats;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Downloads the filtered admin list. The log stores filters, not row data.
 */
final class AdminUserExportForm extends FormBase {

  public function __construct(
    protected AdminUserStats $stats,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $form = new static(
      $container->get(AdminUserStats::class),
    );
    $form->setStringTranslation($container->get('string_translation'));
    $form->setRequestStack($container->get('request_stack'));
    $form->setLoggerFactory($container->get('logger.factory'));
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lifebalance_admin_user_export';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The export form.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $filters = $this->filters();
    foreach ($filters as $key => $value) {
      $form[$key] = [
        '#type' => 'hidden',
        '#value' => $value,
      ];
    }
    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Завантажити CSV'),
      ],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $filters = $this->filters();
    $emails = $this->currentUser()->hasPermission('view lifebalance user emails');
    $records = $this->stats->all(
      $filters['tab'],
      $filters['search'],
      $filters['sort'],
      $filters['dir'],
      $emails,
      $filters['registered_from'],
      $filters['registered_to'],
    );
    $csv = $this->stats->csv($records, $emails);
    $this->getLogger('lifebalance')->info('Exported the user list for uid @uid. tab=@tab sort=@sort dir=@dir search=@search', [
      '@uid' => $this->currentUser()->id(),
      '@tab' => $filters['tab'],
      '@sort' => $filters['sort'],
      '@dir' => $filters['dir'],
      '@search' => $filters['search'],
    ]);
    $response = new Response($csv);
    $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="lifebalance-users.csv"');
    $response->headers->set('Cache-Control', 'private, no-store');
    $form_state->setResponse($response);
  }

  /**
   * Allowlisted filters from the current request.
   *
   * @return array{
   *   tab: string,
   *   search: string,
   *   sort: string,
   *   dir: string,
   *   registered_from: string,
   *   registered_to: string
   *   }
   *   Filter values.
   */
  private function filters(): array {
    $query = $this->getRequest()->query;
    $tab_query = $query->get('tab');
    $sort_query = $query->get('sort');
    $dir_query = $query->get('dir');
    $search_query = $query->get('search');
    $from_query = $query->get('registered_from');
    $to_query = $query->get('registered_to');
    $direction = $this->stats->direction(is_string($dir_query) ? $dir_query : '');
    return [
      'tab' => $this->stats->tab(is_string($tab_query) ? $tab_query : ''),
      'search' => is_string($search_query) ? $search_query : '',
      'sort' => $this->stats->sort(is_string($sort_query) ? $sort_query : ''),
      'dir' => strtolower($direction) === 'asc' ? 'asc' : 'desc',
      'registered_from' => is_string($from_query) ? $from_query : '',
      'registered_to' => is_string($to_query) ? $to_query : '',
    ];
  }

}
