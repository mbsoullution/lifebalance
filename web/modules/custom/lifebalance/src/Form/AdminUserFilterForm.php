<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\lifebalance\Service\AdminUserStats;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * GET filter for the admin user list.
 */
final class AdminUserFilterForm extends FormBase {

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
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lifebalance_admin_user_filter';
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
   *   The filter form.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $query = $this->getRequest()->query;
    $tab_query = $query->get('tab');
    $sort_query = $query->get('sort');
    $dir_query = $query->get('dir');
    $search_query = $query->get('search');
    $tab = $this->stats->tab(is_string($tab_query) ? $tab_query : '');
    $sort = $this->stats->sort(is_string($sort_query) ? $sort_query : '');
    $direction = strtolower($this->stats->direction(is_string($dir_query) ? $dir_query : ''));
    $search = is_string($search_query) ? $search_query : '';
    $from_query = $query->get('registered_from');
    $to_query = $query->get('registered_to');

    $form['#method'] = 'get';
    $form['#token'] = FALSE;
    $form['#after_build'][] = [static::class, 'stripBuildId'];
    $form['search'] = [
      '#type' => 'search',
      '#title' => $this->t("Ім'я, UID або адреса"),
      '#default_value' => $search,
      '#size' => 32,
    ];
    $form['registered_from'] = [
      '#type' => 'date',
      '#title' => $this->t('Реєстрація від'),
      '#default_value' => is_string($from_query) ? $from_query : '',
    ];
    $form['registered_to'] = [
      '#type' => 'date',
      '#title' => $this->t('Реєстрація до'),
      '#default_value' => is_string($to_query) ? $to_query : '',
    ];
    $form['tab'] = [
      '#type' => 'hidden',
      '#value' => $tab,
    ];
    $form['sort'] = [
      '#type' => 'hidden',
      '#value' => $sort,
    ];
    $form['dir'] = [
      '#type' => 'hidden',
      '#value' => $direction === 'asc' ? 'asc' : 'desc',
    ];
    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Знайти'),
      ],
    ];
    return $form;
  }

  /**
   * Drops Drupal form ids from the query string.
   *
   * @param array<string, mixed> $form
   *   The built form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state. Unused.
   *
   * @return array<string, mixed>
   *   The form without build ids.
   */
  public static function stripBuildId(array $form, FormStateInterface $form_state): array {
    unset($form['form_id'], $form['form_build_id'], $form['form_token']);
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
  }

}
