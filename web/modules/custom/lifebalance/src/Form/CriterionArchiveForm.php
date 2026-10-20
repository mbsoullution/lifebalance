<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\lifebalance\Entity\Criterion;

/**
 * Archives a criterion. The criterion and its revisions stay in storage.
 */
final class CriterionArchiveForm extends ConfirmFormBase {

  /**
   * Criterion being archived.
   */
  protected ?Criterion $criterion = NULL;

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lifebalance_criterion_archive';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    $key = $this->criterion instanceof Criterion ? $this->criterion->getCatalogKey() : '';
    return $this->t('Архівувати критерій @key?', ['@key' => $key]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('Критерій зникне з опитувальника. Формулювання і ревізії залишаються. Статус можна повернути у формі редагування.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Архівувати');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelText() {
    return $this->t('Скасувати');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return Url::fromRoute('entity.lifebalance_criterion.collection');
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\lifebalance\Entity\Criterion|null $lifebalance_criterion
   *   The criterion from the route.
   *
   * @return array<string, mixed>
   *   The form structure.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?Criterion $lifebalance_criterion = NULL): array {
    if (!$lifebalance_criterion instanceof Criterion) {
      throw new \InvalidArgumentException('A criterion is required.');
    }
    $this->criterion = $lifebalance_criterion;
    return parent::buildForm($form, $form_state);
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
    $criterion = $this->criterion;
    if (!$criterion instanceof Criterion) {
      throw new \InvalidArgumentException('A criterion is required.');
    }
    if ($criterion->getStatus() === Criterion::STATUS_ARCHIVED) {
      $this->messenger()->addStatus($this->t('Критерій уже в архіві.'));
    }
    else {
      $criterion->set('status', Criterion::STATUS_ARCHIVED);
      $criterion->save();
      $this->messenger()->addStatus($this->t('Критерій архівовано.'));
    }
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
