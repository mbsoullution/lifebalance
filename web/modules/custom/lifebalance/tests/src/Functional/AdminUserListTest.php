<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance\Functional;

use Drupal\lifebalance\Entity\Snapshot;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\RoleInterface;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The admin list shows wheel state and hides scores, notes, and emails.
 */
#[Group('lifebalance')]
#[RunTestsInSeparateProcesses]
final class AdminUserListTest extends BrowserTestBase {

  /**
   * Active sector IDs.
   *
   * @var list<string>
   */
  private const SECTORS = [
    'health',
    'relationships',
    'environment',
    'purpose',
    'finances',
    'self_development',
    'life_brightness',
    'spirituality',
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['lifebalance'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Metadata is visible. Notes, scores, and emails stay hidden.
   */
  public function testListCardAndExport(): void {
    $scored = $this->drupalCreateUser([], 'scoredwheel');
    $scored->setEmail('scored-list@example.com');
    $scored->save();
    $started = $this->drupalCreateUser([], 'startedwheel');
    $quiet = $this->drupalCreateUser([], 'quietwheel');
    $quiet->setEmail('quiet-list@example.com');
    $quiet->save();
    $draft_only = $this->drupalCreateUser([], 'draftonly');
    $this->container->get('current_user')->setAccount($scored);
    $this->saveSnapshot($scored);
    $user_data = $this->container->get('user.data');
    $user_data->set('lifebalance', (int) $scored->id(), 'wheel_created', 1700000001);
    $user_data->set('lifebalance', (int) $started->id(), 'wheel_created', 1700000002);
    $user_data->set('lifebalance', (int) $started->id(), 'draft', [
      'note' => 'Секретна чернетка 84721',
    ]);
    $user_data->set('lifebalance', (int) $draft_only->id(), 'draft', [
      'note' => 'Секретна чернетка 84721',
    ]);

    $this->drupalGet('/admin/lifebalance/users');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('/admin/lifebalance/stats');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('/admin/lifebalance/users/' . $quiet->id());
    $this->assertSession()->statusCodeEquals(403);

    $stranger = $this->drupalCreateUser();
    $this->drupalLogin($stranger);
    $this->drupalGet('/admin/lifebalance/users');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('/history/' . $this->snapshotUuid($scored));
    $this->assertSession()->statusCodeEquals(404);

    $manager = $this->drupalCreateUser();
    $manager->addRole('site_manager');
    $manager->save();
    $this->drupalLogin($manager);

    $this->drupalGet('/admin/lifebalance/users');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('quietwheel');
    $this->assertSession()->pageTextContains('startedwheel');
    $this->assertSession()->pageTextContains('scoredwheel');
    $this->assertSession()->pageTextContains('draftonly');
    $this->assertSession()->pageTextContains('Зареєстрований');
    $this->assertSession()->pageTextContains('Колесо створено');
    $this->assertSession()->pageTextContains('Є зріз');
    $this->assertSession()->pageTextNotContains('Секретна нотатка 84721');
    $this->assertSession()->pageTextNotContains('Секретна чернетка 84721');
    $this->assertSession()->pageTextNotContains('quiet-list@example.com');
    $this->assertSession()->pageTextNotContains('scored-list@example.com');
    $this->assertSession()->responseHeaderContains('Cache-Control', 'private');
    $this->assertSession()->responseHeaderContains('Cache-Control', 'no-store');

    $this->drupalGet('/admin/lifebalance/users', [
      'query' => ['tab' => 'nowheel'],
    ]);
    $this->assertSession()->pageTextContains('quietwheel');
    $this->assertSession()->pageTextContains('draftonly');
    $this->assertSession()->pageTextNotContains('scoredwheel');
    $this->assertSession()->pageTextNotContains('startedwheel');

    $this->drupalGet('/admin/lifebalance/users', [
      'query' => ['tab' => 'wheel'],
    ]);
    $this->assertSession()->pageTextContains('startedwheel');
    $this->assertSession()->pageTextContains('scoredwheel');
    $this->assertSession()->pageTextNotContains('quietwheel');
    $this->assertSession()->pageTextNotContains('draftonly');
    $this->assertSession()->pageTextNotContains('Секретна чернетка 84721');

    $this->drupalGet('/admin/lifebalance/users', [
      'query' => ['search' => 'quiet-list@example.com'],
    ]);
    $this->assertSession()->pageTextNotContains('quietwheel');

    $this->drupalGet('/admin/lifebalance/users/' . $scored->id());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('scoredwheel');
    $this->assertSession()->pageTextContains('Є зріз');
    $this->assertSession()->pageTextNotContains('Секретна нотатка 84721');
    $this->assertSession()->pageTextNotContains('scored-list@example.com');
    $this->assertSession()->responseNotContains('/users/' . $scored->id() . '/wheel');

    $this->drupalGet('/admin/lifebalance/users/' . $scored->id() . '/wheel');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalGet('/history/' . $this->snapshotUuid($scored));
    $this->assertSession()->statusCodeEquals(404);

    $this->drupalGet('/admin/lifebalance/users', [
      'query' => ['search' => 'quietwheel'],
    ]);
    $this->submitForm([], 'Завантажити CSV');
    $this->assertSession()->responseContains('quietwheel');
    $this->assertSession()->responseNotContains('quiet-list@example.com');
    $this->assertSession()->responseNotContains('Секретна нотатка 84721');
    $this->assertSession()->responseHeaderContains('Cache-Control', 'private');

    $role = $this->container->get('entity_type.manager')->getStorage('user_role')->load('site_manager');
    $this->assertInstanceOf(RoleInterface::class, $role);
    $role->grantPermission('view lifebalance user emails');
    $role->save();
    $this->drupalLogin($manager);
    $this->drupalGet('/admin/lifebalance/users', [
      'query' => ['search' => 'quiet-list@example.com'],
    ]);
    $this->assertSession()->pageTextContains('quietwheel');
    $this->assertSession()->pageTextContains('quiet-list@example.com');
    $this->submitForm([], 'Завантажити CSV');
    $this->assertSession()->responseContains('quiet-list@example.com');
    $this->assertSession()->responseNotContains('Секретна нотатка 84721');

    $this->drupalGet('/admin/lifebalance/stats');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Акаунти:');
    $this->assertSession()->pageTextContains('Колесо створено:');
    $this->assertSession()->pageTextContains('Зрізів за період:');
    $this->assertSession()->pageTextNotContains('Секретна нотатка 84721');
    $this->assertSession()->responseHeaderContains('Cache-Control', 'private');
    $this->assertSession()->responseHeaderContains('Cache-Control', 'no-store');
  }

  /**
   * The site owner sees another person's wheel. Other roles do not.
   */
  public function testSiteOwnerSeesAnotherWheel(): void {
    $scored = $this->drupalCreateUser([], 'scoredwheel');
    $other = $this->drupalCreateUser([], 'otherwheel');
    $this->container->get('current_user')->setAccount($scored);
    $this->saveSnapshot($scored);
    $this->container->get('current_user')->setAccount($other);
    $this->saveSnapshot($other);
    $uuid = $this->snapshotUuid($scored);
    $foreign = $this->snapshotUuid($other);
    $wheel = '/admin/lifebalance/users/' . $scored->id() . '/wheel';

    $this->drupalGet($wheel);
    $this->assertSession()->statusCodeEquals(403);
    $stranger = $this->drupalCreateUser();
    $this->drupalLogin($stranger);
    $this->drupalGet($wheel);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet($wheel . '/' . $uuid);
    $this->assertSession()->statusCodeEquals(403);

    $owner = $this->drupalCreateUser([
      'administer lifebalance snapshots',
      'view lifebalance user metadata',
    ]);
    $this->drupalLogin($owner);
    $this->drupalGet('/admin/lifebalance/users');
    $this->assertSession()->responseContains($wheel);
    $this->drupalGet('/admin/lifebalance/users/' . $scored->id());
    $this->assertSession()->linkExists('Відкрити');
    $this->drupalGet($wheel);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Секретна нотатка 84721');
    $this->assertSession()->pageTextContains("Здоров'я");
    $this->assertSession()->pageTextContains('6');
    $this->assertSession()->pageTextNotContains('Видалити зріз');
    $this->assertSession()->responseHeaderContains('Cache-Control', 'private');
    $this->assertSession()->responseHeaderContains('Cache-Control', 'no-store');

    $this->drupalGet($wheel . '/' . $uuid);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Секретна нотатка 84721');
    $this->drupalGet($wheel . '/' . $foreign);
    $this->assertSession()->statusCodeEquals(404);
    $this->drupalGet('/history/' . $uuid);
    $this->assertSession()->statusCodeEquals(404);

    $this->drupalGet('/admin/lifebalance/users/' . $stranger->id() . '/wheel');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Зрізів ще немає.');
  }

  /**
   * Stores eight sector scores and a private note.
   */
  private function saveSnapshot(UserInterface $owner): void {
    $scores = [];
    foreach (self::SECTORS as $sector) {
      $scores[] = [
        'sector_id' => $sector,
        'value' => 6,
      ];
    }
    Snapshot::create([
      'uid' => $owner->id(),
      'note' => 'Секретна нотатка 84721',
      'scores' => $scores,
    ])->save();
  }

  /**
   * UUID of the person's only snapshot.
   */
  private function snapshotUuid(UserInterface $owner): string {
    $storage = $this->container->get('entity_type.manager')->getStorage('lifebalance_snapshot');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $owner->id())
      ->execute();
    $id = reset($ids);
    $this->assertNotFalse($id);
    $snapshot = $storage->load($id);
    $this->assertInstanceOf(Snapshot::class, $snapshot);
    return (string) $snapshot->uuid();
  }

}
