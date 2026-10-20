<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\lifebalance\Entity\Criterion;
use Drupal\lifebalance\Repository\SectorRepository;

/**
 * Filters the criteria list. Submitted as GET so the query can be shared.
 */
final class CriterionFilterForm extends FormBase {

  public function __construct(
    protected SectorRepository $sectorRepository,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lifebalance_criterion_filter';
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
   *   The form structure.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $sector = $this->queryValue('sector');
    $tier = $this->queryValue('tier');
    $status = $this->queryValue('status');

    $form['#method'] = 'get';
    $form['#action'] = Url::fromRoute('entity.lifebalance_criterion.collection')->toString();
    $form['#token'] = FALSE;
    $form['#after_build'][] = '::afterBuild';
    $form['#attributes']['class'][] = 'lifebalance-criterion-filter';

    $sector_options = [];
    foreach ($this->sectorRepository->loadAll() as $id => $sector_entity) {
      $sector_options[$id] = $sector_entity->label();
    }
    $form['sector'] = [
      '#type' => 'select',
      '#title' => $this->t('Сектор'),
      '#options' => $sector_options,
      '#empty_option' => $this->t('Усі'),
      '#default_value' => $sector,
    ];
    $form['tier'] = [
      '#type' => 'select',
      '#title' => $this->t('Рівень'),
      '#options' => Criterion::tierLabels(),
      '#empty_option' => $this->t('Усі'),
      '#default_value' => $tier,
    ];
    $form['status'] = [
      '#type' => 'select',
      '#title' => $this->t('Статус'),
      '#options' => Criterion::statusLabels(),
      '#empty_option' => $this->t('Усі'),
      '#default_value' => $status,
    ];
    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Фільтрувати'),
      '#name' => 'filter',
    ];
    if ($sector !== '' || $tier !== '' || $status !== '') {
      $form['actions']['reset'] = [
        '#type' => 'link',
        '#title' => $this->t('Скинути'),
        '#url' => Url::fromRoute('entity.lifebalance_criterion.collection'),
      ];
    }
    return $form;
  }

  /**
   * Drops form bookkeeping so the query string stays the filters.
   *
   * @param array<string, mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The form structure.
   */
  public function afterBuild(array $form, FormStateInterface $form_state): array {
    unset($form['form_token'], $form['form_build_id'], $form['form_id']);
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
    // GET submit reloads the list with the query string.
  }

  /**
   * Reads one filter from the query string.
   */
  private function queryValue(string $name): string {
    $value = $this->getRequest()->query->all()[$name] ?? '';
    return is_string($value) ? $value : '';
  }

}
