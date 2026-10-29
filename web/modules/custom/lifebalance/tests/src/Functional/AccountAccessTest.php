<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance\Functional;

use Drupal\block\Entity\Block;
use Drupal\file\FileInterface;
use Drupal\lifebalance\Entity\Entitlement;
use Drupal\lifebalance\Form\RegisterAccountForm;
use Drupal\lifebalance\Service\Clock;
use Drupal\lifebalance\Service\EntitlementManager;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The header prompts follow anonymous, registered, and Premium access.
 */
#[Group('lifebalance')]
#[RunTestsInSeparateProcesses]
final class AccountAccessTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'lifebalance',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    Block::create([
      'id' => 'stark_account_access',
      'plugin' => 'lifebalance_account_access',
      'theme' => 'stark',
      'region' => 'header',
      'settings' => [
        'label' => 'Account access',
        'label_display' => '0',
      ],
    ])->save();
  }

  /**
   * Anonymous visitors register. Premium hides both prompts.
   */
  public function testPromptsFollowAccess(): void {
    $this->drupalGet('/faq');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->buttonExists('Зареєструватися');
    $this->assertSession()->buttonExists('Отримати преміум');
    $this->assertSession()->fieldExists('Аватар');
    $this->assertSession()->fieldExists("Ім'я");
    $this->assertSession()->fieldExists('Прізвище');
    $this->assertSession()->fieldExists('Мобільний телефон');
    $this->assertSession()->fieldExists('Електронна пошта');
    $this->assertSession()->pageTextContains('Зараз розширений доступ відкривається за запрошенням.');
    $this->assertSession()->pageTextNotContains('149 грн');

    $this->submitRegistration([
      "Ім'я" => 'Тест',
      'Прізвище' => 'Учасник',
      'Електронна пошта' => 'member@example.com',
    ], FALSE);
    $this->assertSession()->pageTextContains('Додайте аватар.');
    $this->assertSession()->buttonExists('Зареєструватися');

    $this->submitRegistration([
      "Ім'я" => '123',
      'Прізвище' => 'Учасник',
      'Електронна пошта' => 'member@example.com',
    ]);
    $this->assertSession()->pageTextContains("Вкажіть ім'я.");
    $this->assertSession()->buttonExists('Зареєструватися');

    $this->submitRegistration([
      "Ім'я" => 'Тест',
      'Прізвище' => 'Учасник',
      'Електронна пошта' => 'member@example.com',
    ]);
    $this->assertSession()->pageTextContains('Обліковий запис створено.');
    $this->assertSession()->buttonNotExists('Зареєструватися');
    $this->assertSession()->buttonExists('Отримати преміум');

    $accounts = $this->container->get('entity_type.manager')->getStorage('user')->loadByProperties([
      'mail' => 'member@example.com',
    ]);
    $account = reset($accounts);
    $this->assertNotFalse($account);
    $profile = $this->container->get('user.data')->get(
      'lifebalance',
      (int) $account->id(),
      RegisterAccountForm::PROFILE,
    );
    $this->assertSame('Тест', $profile['given_name']);
    $this->assertSame('Учасник', $profile['family_name']);
    $this->assertSame('', $profile['phone']);
    $this->assertIsInt($profile['avatar']);
    $file = $this->container->get('entity_type.manager')->getStorage('file')->load($profile['avatar']);
    $this->assertInstanceOf(FileInterface::class, $file);
    $this->assertTrue($file->isPermanent());
    $this->assertSame((int) $account->id(), (int) $file->getOwnerId());

    $this->container->get(EntitlementManager::class)->grantManual(
      $account,
      Entitlement::SOURCE_ADMIN,
      $this->container->get(Clock::class)->now(),
      NULL,
      'Functional check of the premium prompt.',
      $account,
      FALSE,
    );
    $this->drupalGet('/faq');
    $this->assertSession()->buttonNotExists('Зареєструватися');
    $this->assertSession()->buttonNotExists('Отримати преміум');

    $this->drupalLogout();
    $this->drupalGet('/faq');
    $this->submitRegistration([
      "Ім'я" => 'Тест',
      'Прізвище' => 'Інший',
      'Електронна пошта' => 'member@example.com',
    ]);
    $this->assertSession()->pageTextContains('Ця пошта вже зареєстрована.');
    $this->assertSession()->buttonExists('Зареєструватися');
  }

  /**
   * Submits the email registration form, with an avatar unless omitted.
   *
   * @param array<string, string> $edit
   *   Field labels and values, without the password pair.
   * @param bool $avatar
   *   TRUE to attach a small PNG.
   */
  private function submitRegistration(array $edit, bool $avatar = TRUE): void {
    $edit['pass[pass1]'] = 'Balance10';
    $edit['pass[pass2]'] = 'Balance10';
    if ($avatar) {
      $edit['Аватар'] = $this->avatarPath();
    }
    $this->submitForm($edit, 'Створити обліковий запис');
  }

  /**
   * A one-pixel PNG the registration form can store.
   */
  private function avatarPath(): string {
    $path = sys_get_temp_dir() . '/lifebalance-register-avatar.png';
    if (!is_file($path)) {
      $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', TRUE);
      $this->assertIsString($bytes);
      file_put_contents($path, $bytes);
    }
    return $path;
  }

}
