<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Form;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\lifebalance\Entity\Snapshot;
use Drupal\lifebalance\Repository\SnapshotRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Edits the note on a snapshot. Scores and answers stay as they were saved.
 */
final class SnapshotNoteForm extends FormBase {

  public function __construct(
    protected SnapshotRepository $snapshotRepository,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lifebalance_snapshot_note';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string|null $uuid
   *   Snapshot UUID from the page.
   *
   * @return array<string, mixed>
   *   The form structure.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $uuid = NULL): array {
    $snapshot = $this->owned($uuid);
    $form['#cache'] = [
      'contexts' => ['user'],
      'tags' => [
        'lifebalance_snapshot_list:' . $snapshot->getOwnerId(),
      ],
    ];
    $form['note'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Нотатка'),
      '#default_value' => $snapshot->getNote(),
      '#rows' => 4,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['save'] = [
      '#type' => 'submit',
      '#value' => $this->t('Зберегти нотатку'),
    ];
    $form_state->set('uuid', (string) $snapshot->uuid());
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
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $note = $form_state->getValue('note');
    if (!is_string($note) || mb_strlen($note) > 1000) {
      $form_state->setErrorByName('note', $this->t('Нотатка може містити до 1000 символів.'));
    }
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
    $note = $form_state->getValue('note');
    $snapshot->set('note', is_string($note) ? $note : '');
    try {
      $snapshot->save();
    }
    catch (EntityStorageException) {
      $this->messenger()->addError($this->t('Нотатку не збережено.'));
      return;
    }
    $this->messenger()->addStatus($this->t('Нотатку збережено.'));
    $form_state->setRedirect('lifebalance.snapshot', [
      'uuid' => $snapshot->uuid(),
    ]);
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
