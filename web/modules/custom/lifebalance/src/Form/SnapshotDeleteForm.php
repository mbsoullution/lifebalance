<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\lifebalance\Entity\Snapshot;
use Drupal\lifebalance\Repository\SnapshotRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Deletes the current person's snapshot.
 */
final class SnapshotDeleteForm extends ConfirmFormBase {

  /**
   * UUID from the route, kept for the cancel link.
   */
  protected string $uuid = '';

  public function __construct(
    protected SnapshotRepository $snapshotRepository,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lifebalance_snapshot_delete';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Видалити цей зріз?');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return Url::fromRoute('lifebalance.snapshot', ['uuid' => $this->uuid]);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Видалити');
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string|null $uuid
   *   Snapshot UUID from the route.
   *
   * @return array<string, mixed>
   *   The form structure.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $uuid = NULL): array {
    $snapshot = $this->owned($uuid);
    $this->uuid = (string) $snapshot->uuid();
    $form = parent::buildForm($form, $form_state);
    $form['#cache'] = [
      'contexts' => ['user'],
      'tags' => ['lifebalance_snapshot_list:' . $snapshot->getOwnerId()],
    ];
    $form_state->set('uuid', $this->uuid);
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
    $uuid = $form_state->get('uuid');
    $snapshot = $this->owned(is_string($uuid) ? $uuid : NULL);
    $snapshot->delete();
    $this->messenger()->addStatus($this->t('Зріз видалено.'));
    $form_state->setRedirect('lifebalance.history');
  }

  /**
   * The current person's snapshot, or a 404.
   */
  private function owned(?string $uuid): Snapshot {
    $snapshot = $this->snapshotRepository->loadByUuidForOwner((string) $uuid, (int) $this->currentUser()->id());
    if (!$snapshot instanceof Snapshot) {
      throw new NotFoundHttpException();
    }
    return $snapshot;
  }

}
