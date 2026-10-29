<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Form;

use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\file\Upload\FileUploadHandlerInterface;
use Drupal\file\Upload\FormUploadedFile;
use Drupal\user\UserDataInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Creates an account from an email address.
 *
 * The avatar is required on this form. Existing accounts are not asked for
 * one. The phone number stays optional. The name and the mail are the same
 * address, so the existing sign-in form accepts it. A matching social account
 * is not merged.
 */
final class RegisterAccountForm extends FormBase {

  /**
   * Storage key for the name, the optional phone, and the avatar file id.
   */
  public const PROFILE = 'profile';

  private const FLOOD_EVENT = 'lifebalance.register';

  private const FLOOD_WINDOW = 3600;

  private const FLOOD_LIMIT = 10;

  private const AVATAR_DIRECTORY = 'public://lifebalance/avatars';

  private const AVATAR_EXTENSIONS = 'png jpg jpeg gif webp';

  private const AVATAR_BYTES = 2097152;

  public function __construct(
    protected FloodInterface $flood,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EmailValidatorInterface $emailValidator,
    protected UserDataInterface $userData,
    protected FileUploadHandlerInterface $uploads,
    protected FileSystemInterface $fileSystem,
    protected FileUsageInterface $fileUsage,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('flood'),
      $container->get('entity_type.manager'),
      $container->get('email.validator'),
      $container->get('user.data'),
      $container->get('file.upload_handler'),
      $container->get('file_system'),
      $container->get('file.usage'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lifebalance_register_account';
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
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attributes']['class'][] = 'lifebalance-register-form';
    if ($form_state->get('lifebalance_register_invalid')) {
      $form['#attributes']['data-lifebalance-register-invalid'] = '1';
    }
    $form['avatar'] = [
      '#type' => 'file',
      '#title' => $this->t('Аватар'),
      '#description' => $this->t("Обов'язково. PNG, JPG, GIF або WebP, до 2 МБ."),
      '#required' => TRUE,
      '#required_error' => $this->t('Додайте аватар.'),
      '#attributes' => [
        'accept' => 'image/png,image/jpeg,image/gif,image/webp',
        'data-lifebalance-avatar-required' => (string) $this->t('Додайте аватар.'),
      ],
    ];
    $form['given_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t("Ім'я"),
      '#required' => TRUE,
      '#maxlength' => 64,
    ];
    $form['family_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Прізвище'),
      '#required' => TRUE,
      '#maxlength' => 64,
    ];
    $form['phone'] = [
      '#type' => 'tel',
      '#title' => $this->t('Мобільний телефон'),
      '#required' => FALSE,
      '#maxlength' => 20,
      '#description' => $this->t("Необов'язково"),
    ];
    $form['mail'] = [
      '#type' => 'email',
      '#title' => $this->t('Електронна пошта'),
      '#required' => TRUE,
      '#maxlength' => 254,
    ];
    $form['pass'] = [
      '#type' => 'password_confirm',
      '#title' => $this->t('Пароль'),
      '#required' => TRUE,
      '#size' => 25,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Створити обліковий запис'),
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
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->currentUser()->isAnonymous()) {
      $form_state->setErrorByName('mail', $this->t('Ви вже маєте обліковий запис.'));
    }
    else {
      $this->validateNewAccount($form_state);
    }
    if ($form_state->hasAnyErrors()) {
      $form_state->set('lifebalance_register_invalid', TRUE);
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
    if (!$this->currentUser()->isAnonymous()) {
      return;
    }
    $mail = $this->text($form_state, 'mail');
    $password = $form_state->getValue('pass');
    if (!is_string($password) || $password === '') {
      return;
    }
    $file = $this->storeAvatar();
    if (!$file instanceof FileInterface) {
      $this->failAvatar($form_state);
      return;
    }
    $account = $this->entityTypeManager->getStorage('user')->create([
      'name' => $mail,
      'mail' => $mail,
      'init' => $mail,
      'status' => 1,
    ]);
    $account->setPassword($password);
    try {
      $account->save();
      $uid = (int) $account->id();
      $file->setOwnerId($uid);
      $file->setPermanent();
      $file->save();
      $this->fileUsage->add($file, 'lifebalance', 'user', (string) $uid);
      $this->userData->set('lifebalance', $uid, self::PROFILE, [
        'given_name' => $this->text($form_state, 'given_name'),
        'family_name' => $this->text($form_state, 'family_name'),
        'phone' => $this->text($form_state, 'phone'),
        'avatar' => (int) $file->id(),
      ]);
    }
    catch (\Throwable) {
      $this->rollBack($account, $file);
      $this->failAvatar($form_state);
      return;
    }
    $this->flood->register(self::FLOOD_EVENT, self::FLOOD_WINDOW, $this->floodId());
    user_login_finalize($account);
    $this->messenger()->addStatus($this->t('Обліковий запис створено.'));
    $form_state->setRedirect('<front>');
  }

  /**
   * Checks the flood limit, the avatar, the names, the mail, and the password.
   */
  private function validateNewAccount(FormStateInterface $form_state): void {
    $allowed = $this->flood->isAllowed(
      self::FLOOD_EVENT,
      self::FLOOD_LIMIT,
      self::FLOOD_WINDOW,
      $this->floodId(),
    );
    if (!$allowed) {
      $form_state->setErrorByName('mail', $this->t('Спробуйте пізніше.'));
      return;
    }
    $this->validateAvatar($form_state);
    $this->validateName($form_state, 'given_name', (string) $this->t("Вкажіть ім'я."));
    $this->validateName($form_state, 'family_name', (string) $this->t('Вкажіть прізвище.'));
    $this->validatePhone($form_state);
    $mail = $this->text($form_state, 'mail');
    if (!$this->emailValidator->isValid($mail)) {
      $form_state->setErrorByName('mail', $this->t('Вкажіть електронну пошту.'));
      return;
    }
    if ($this->mailTaken($mail)) {
      $form_state->setErrorByName('mail', $this->t('Ця пошта вже зареєстрована. Увійдіть наявним способом.'));
      return;
    }
    $password = $form_state->getValue('pass');
    if (is_string($password) && $password !== '' && mb_strlen($password) < 8) {
      $form_state->setErrorByName('pass', $this->t('Пароль має містити щонайменше 8 символів.'));
    }
  }

  /**
   * Requires one image within the size and pixel limits.
   */
  private function validateAvatar(FormStateInterface $form_state): void {
    $uploaded = $this->uploadedAvatar();
    if (!$uploaded instanceof UploadedFile || !$uploaded->isValid()) {
      if (!isset($form_state->getErrors()['avatar'])) {
        $form_state->setErrorByName('avatar', $this->t('Додайте аватар.'));
      }
      return;
    }
    $name = $uploaded->getClientOriginalName();
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowed = explode(' ', self::AVATAR_EXTENSIONS);
    if (!in_array($extension, $allowed, TRUE)) {
      $message = $this->t('Аватар має бути зображенням PNG, JPG, GIF або WebP.');
      $form_state->setErrorByName('avatar', $message);
      return;
    }
    if ($uploaded->getSize() > self::AVATAR_BYTES) {
      $form_state->setErrorByName('avatar', $this->t('Аватар має бути не більшим за 2 МБ.'));
      return;
    }
    $path = $uploaded->getRealPath();
    $info = is_string($path) ? $this->imageSize($path) : FALSE;
    if ($info === FALSE) {
      $message = $this->t('Аватар має бути зображенням PNG, JPG, GIF або WebP.');
      $form_state->setErrorByName('avatar', $message);
      return;
    }
    if ((int) $info[0] > 2000 || (int) $info[1] > 2000) {
      $message = $this->t('Аватар має бути не більшим за 2000×2000 пікселів.');
      $form_state->setErrorByName('avatar', $message);
    }
  }

  /**
   * Stores the uploaded image as a temporary file owned by the visitor.
   */
  private function storeAvatar(): ?FileInterface {
    $uploaded = $this->uploadedAvatar();
    if (!$uploaded instanceof UploadedFile) {
      return NULL;
    }
    $directory = self::AVATAR_DIRECTORY;
    $flags = FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS;
    if (!$this->fileSystem->prepareDirectory($directory, $flags)) {
      return NULL;
    }
    try {
      $result = $this->uploads->handleFileUpload(
        new FormUploadedFile($uploaded),
        [
          'FileExtension' => ['extensions' => self::AVATAR_EXTENSIONS],
          'FileIsImage' => [],
          'FileSizeLimit' => ['fileLimit' => self::AVATAR_BYTES],
        ],
        self::AVATAR_DIRECTORY,
        FileExists::Rename,
      );
    }
    catch (\Throwable) {
      return NULL;
    }
    if ($result->hasViolations()) {
      return NULL;
    }
    return $result->getFile();
  }

  /**
   * The file posted with this form, when the browser sent one.
   */
  private function uploadedAvatar(): ?UploadedFile {
    $files = $this->getRequest()->files->get('files', []);
    if (!is_array($files)) {
      return NULL;
    }
    $uploaded = $files['avatar'] ?? NULL;
    return $uploaded instanceof UploadedFile ? $uploaded : NULL;
  }

  /**
   * Image dimensions, or FALSE when the bytes are not an image.
   *
   * @return array<int|string, mixed>|false
   *   The getimagesize result.
   */
  private function imageSize(string $path): array|false {
    set_error_handler(static function (): bool {
      return TRUE;
    });
    try {
      $info = getimagesize($path);
    }
    finally {
      restore_error_handler();
    }
    return is_array($info) ? $info : FALSE;
  }

  /**
   * Removes a half-created account and its avatar.
   */
  private function rollBack(UserInterface $account, FileInterface $file): void {
    if (!$file->isNew()) {
      $file->delete();
    }
    if (!$account->isNew()) {
      $account->delete();
    }
  }

  /**
   * Keeps the dialog open and reports that the avatar was not stored.
   */
  private function failAvatar(FormStateInterface $form_state): void {
    $form_state->setRebuild();
    $form_state->set('lifebalance_register_invalid', TRUE);
    $this->messenger()->addError($this->t('Не вдалося зберегти аватар.'));
  }

  /**
   * Requires a short personal name.
   */
  private function validateName(FormStateInterface $form_state, string $key, string $message): void {
    $value = $this->text($form_state, $key);
    if (preg_match('/^[\p{L}\'-][\p{L}\' \-]{0,63}$/u', $value) !== 1) {
      $form_state->setErrorByName($key, $message);
    }
  }

  /**
   * Allows an empty phone. A filled phone is a short dial string.
   */
  private function validatePhone(FormStateInterface $form_state): void {
    $phone = $this->text($form_state, 'phone');
    if ($phone === '') {
      return;
    }
    if (preg_match('/^\+?[0-9][0-9 \-()]{6,18}$/', $phone) !== 1) {
      $form_state->setErrorByName('phone', $this->t('Вкажіть телефон цифрами або залиште поле порожнім.'));
    }
  }

  /**
   * Whether this mail is already a sign-in name or an address.
   */
  private function mailTaken(string $mail): bool {
    $storage = $this->entityTypeManager->getStorage('user');
    return $storage->loadByProperties(['mail' => $mail]) !== []
      || $storage->loadByProperties(['name' => $mail]) !== [];
  }

  /**
   * Trimmed text from one field.
   */
  private function text(FormStateInterface $form_state, string $key): string {
    $value = $form_state->getValue($key);
    return is_string($value) ? trim($value) : '';
  }

  /**
   * Flood bucket for this visitor address.
   */
  private function floodId(): string {
    return $this->getRequest()->getClientIp() ?? 'unknown';
  }

}
