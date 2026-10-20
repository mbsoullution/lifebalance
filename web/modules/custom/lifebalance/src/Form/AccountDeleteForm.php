<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Url;
use Drupal\user\UserInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Deletes the current person's account and the data kept for it.
 *
 * The route has no user id. User 1 cannot be removed here.
 */
final class AccountDeleteForm extends ConfirmFormBase {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountProxyInterface $currentAccount,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lifebalance_account_delete';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Видалити акаунт і всі збережені зрізи?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t("Зрізи, відповіді, нотатки, чернетка і прив'язані способи входу буде видалено. Це незворотно.");
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Видалити акаунт');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return Url::fromRoute('lifebalance.account_data');
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
    if ((int) $this->currentAccount->id() < 2) {
      throw new AccessDeniedHttpException();
    }
    $form = parent::buildForm($form, $form_state);
    $form['#cache']['contexts'][] = 'user';
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
    $uid = (int) $this->currentAccount->id();
    if ($uid < 2) {
      $this->messenger()->addError($this->t('Цей акаунт не можна видалити.'));
      $form_state->setRedirect('lifebalance.account_data');
      return;
    }
    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$user instanceof UserInterface || (int) $user->id() !== $uid) {
      throw new AccessDeniedHttpException();
    }
    $this->messenger()->addStatus($this->t('Акаунт видалено.'));
    $user->delete();
    $this->currentAccount->setAccount(new AnonymousUserSession());
    if ($this->getRequest()->hasSession()) {
      $this->getRequest()->getSession()->migrate();
    }
    $form_state->setRedirect('<front>');
  }

}
