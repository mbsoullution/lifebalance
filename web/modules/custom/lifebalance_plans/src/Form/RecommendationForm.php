<?php

declare(strict_types=1);

namespace Drupal\lifebalance_plans\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\lifebalance\Entity\Criterion;
use Drupal\lifebalance\Repository\SectorRepository;
use Drupal\lifebalance\Service\Clock;
use Drupal\lifebalance_plans\Entity\Recommendation;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Adds and edits a recommendation. The catalog key is locked after creation.
 */
final class RecommendationForm extends ContentEntityForm {

  public function __construct(
    EntityRepositoryInterface $entity_repository,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    Clock $time,
    protected SectorRepository $sectorRepository,
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
    $form['#cache']['contexts'][] = 'user.permissions';

    $form['help_text'] = [
      '#type' => 'markup',
      '#plain_text' => (string) $this->t('Ключ не змінюється. Рекомендацію можна архівувати, видалити її неможливо.'),
      '#weight' => -20,
    ];

    if (isset($form['key']['widget'][0]['value']) && is_array($form['key']['widget'][0]['value'])) {
      if ($this->entity->isNew()) {
        $form['key']['widget'][0]['value']['#description'] = $this->t('Наприклад, health.sleep.evening_wind_down.');
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

    $this->limitStatusOptions($form);
    return $form;
  }

  /**
   * Checks a new catalog key and leaves publishing to the entity constraint.
   *
   * @param array<mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): ContentEntityInterface {
    $entity = parent::validateForm($form, $form_state);
    if (!$entity instanceof Recommendation || !$entity->isNew()) {
      return $entity;
    }
    $key = $entity->getCatalogKey();
    if (!preg_match(Criterion::KEY_PATTERN, $key)) {
      $form_state->setErrorByName('key', $this->t('Ключ може містити лише малі латинські літери, цифри, крапку, дефіс і підкреслення.'));
    }
    elseif ($this->loadExisting($key) instanceof Recommendation) {
      $form_state->setErrorByName('key', $this->t('Рекомендація з таким ключем уже існує.'));
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
    return $actions;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Рекомендацію збережено.'));
    $form_state->setRedirect('view.lifebalance_recommendations.page_1');
    return $result;
  }

  /**
   * Hides publish and archive from an account that cannot publish.
   *
   * @param array<string, mixed> $form
   *   The form structure.
   */
  private function limitStatusOptions(array &$form): void {
    if ($this->currentUser()->hasPermission('publish lifebalance recommendations')) {
      return;
    }
    if (!isset($form['status']['widget']['#options']) || !is_array($form['status']['widget']['#options'])) {
      return;
    }
    unset(
      $form['status']['widget']['#options'][Recommendation::STATUS_PUBLISHED],
      $form['status']['widget']['#options'][Recommendation::STATUS_ARCHIVED],
    );
  }

  /**
   * An existing recommendation with this catalog key.
   */
  private function loadExisting(string $key): ?Recommendation {
    $storage = $this->entityTypeManager->getStorage('lifebalance_recommendation');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('key', $key)
      ->range(0, 1)
      ->execute();
    $id = reset($ids);
    if ($id === FALSE) {
      return NULL;
    }
    $entity = $storage->load($id);
    return $entity instanceof Recommendation ? $entity : NULL;
  }

}
