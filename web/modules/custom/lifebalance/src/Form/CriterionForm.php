<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\lifebalance\Entity\Criterion;
use Drupal\lifebalance\Repository\CriterionRepository;
use Drupal\lifebalance\Repository\SectorRepository;
use Drupal\lifebalance\Service\Clock;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Adds and edits a criterion. The catalog key is locked after creation.
 */
final class CriterionForm extends ContentEntityForm {

  public function __construct(
    EntityRepositoryInterface $entity_repository,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    Clock $time,
    protected SectorRepository $sectorRepository,
    protected CriterionRepository $criterionRepository,
  ) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get(Clock::class),
      $container->get(SectorRepository::class),
      $container->get(CriterionRepository::class),
    );
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
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    unset($form['revision_log_message']);

    $form['help_text'] = [
      '#type' => 'markup',
      '#plain_text' => (string) $this->t('Ключ не змінюється. Критерій можна архівувати, видалити його неможливо.'),
      '#weight' => -20,
    ];

    if (isset($form['key']['widget'][0]['value']) && is_array($form['key']['widget'][0]['value'])) {
      if ($this->entity->isNew()) {
        $form['key']['widget'][0]['value']['#description'] = $this->t('Наприклад, health.f01.');
      }
      else {
        $form['key']['widget'][0]['value']['#disabled'] = TRUE;
        $form['key']['widget'][0]['value']['#description'] = $this->t('Ключ не змінюється.');
      }
    }

    if (isset($form['sector']['widget'][0]['value']) && is_array($form['sector']['widget'][0]['value'])) {
      $options = [];
      foreach ($this->sectorRepository->loadAll() as $id => $sector) {
        $options[$id] = $sector->label();
      }
      $form['sector']['widget'][0]['value']['#type'] = 'select';
      $form['sector']['widget'][0]['value']['#options'] = $options;
      unset(
        $form['sector']['widget'][0]['value']['#size'],
        $form['sector']['widget'][0]['value']['#maxlength'],
      );
    }

    if (isset($form['weight']['widget'][0]['value']) && is_array($form['weight']['widget'][0]['value'])) {
      $form['weight']['widget'][0]['value']['#required'] = FALSE;
    }

    return $form;
  }

  /**
   * Checks a new catalog key for shape and uniqueness.
   *
   * @param array<mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): ContentEntityInterface {
    $entity = parent::validateForm($form, $form_state);
    if (!$entity instanceof Criterion || !$entity->isNew()) {
      return $entity;
    }
    $key = $entity->getCatalogKey();
    if (!preg_match(Criterion::KEY_PATTERN, $key)) {
      $form_state->setErrorByName('key', $this->t('Ключ може містити лише малі латинські літери, цифри, крапку, дефіс і підкреслення.'));
    }
    elseif ($this->criterionRepository->loadByKey($key) instanceof Criterion) {
      $form_state->setErrorByName('key', $this->t('Критерій з таким ключем уже існує.'));
    }
    return $entity;
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
   *   The form actions.
   */
  protected function actions(array $form, FormStateInterface $form_state): array {
    $actions = parent::actions($form, $form_state);
    $actions['submit']['#value'] = $this->t('Зберегти');
    if (!$this->entity->isNew()) {
      $actions['archive'] = [
        '#type' => 'link',
        '#title' => $this->t('Архівувати'),
        '#url' => $this->entity->toUrl('archive'),
        '#attributes' => ['class' => ['button']],
        '#weight' => 10,
      ];
    }
    return $actions;
  }

  /**
   * Saves the criterion and returns to the list.
   *
   * @param array<string, mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $status = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Критерій збережено.'));
    $form_state->setRedirectUrl(Url::fromRoute('entity.lifebalance_criterion.collection'));
    return (int) $status;
  }

}
