<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance_plans\Kernel;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\lifebalance\Entity\Criterion;
use Drupal\lifebalance\Entity\Snapshot;
use Drupal\lifebalance_plans\Entity\Recommendation;
use Drupal\lifebalance_plans\Service\RecommendationImporter;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\Group;

/**
 * Recommendation catalog, workflow, and the content editor role.
 */
#[Group('lifebalance_plans')]
final class RecommendationKernelTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'options',
    'filter',
    'views',
    'lifebalance',
    'lifebalance_plans',
  ];

  /**
   * Account with every permission, used for the import.
   */
  private UserInterface $admin;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('lifebalance_criterion');
    $this->installEntitySchema('lifebalance_recommendation');
    $this->installEntitySchema('lifebalance_snapshot');
    $this->installSchema('user', ['users_data']);
    $this->installConfig([
      'system',
      'filter',
      'views',
      'lifebalance',
      'lifebalance_plans',
    ]);
    $this->admin = $this->createUser();
    $this->setCurrentUser($this->admin);
    $this->addCriterion('health.f03', 'health');
    $this->addCriterion('finances.f02', 'finances');
    $this->addCriterion('life_brightness.f01', 'life_brightness');
    $this->addCriterion('life_brightness.p06', 'life_brightness');
  }

  /**
   * The shipped examples import once and a text change adds one revision.
   */
  public function testImportIsIdempotentAndRevisesText(): void {
    $importer = $this->importer();
    $first = $importer->import();
    $this->assertSame(3, $first->created);
    $this->assertSame(0, $first->updated);
    $this->assertSame(0, $first->warned);
    $this->assertSame(3, $this->countRecommendations());
    $this->assertSame(1, $this->revisionCount('health.sleep.evening_wind_down'));

    $stored = $this->loadRecommendation('health.sleep.evening_wind_down');
    $this->assertSame(Recommendation::STATUS_DRAFT, $stored->getStatus());
    $this->assertSame(Recommendation::BODY_FORMAT, $stored->get('body')->format);
    $this->assertSame('health.f03', $stored->getTriggerCriteria()[0]);

    $second = $importer->import();
    $this->assertSame(0, $second->created);
    $this->assertSame(3, $second->unchanged);
    $this->assertSame(0, $second->updated);
    $this->assertSame(3, $this->countRecommendations());
    $this->assertSame(1, $this->revisionCount('health.sleep.evening_wind_down'));

    $path = sys_get_temp_dir() . '/recommendations-' . uniqid('', TRUE) . '.yml';
    $yaml = (string) file_get_contents($importer->defaultPath());
    $yaml = str_replace(
      'title: "Спокійна година перед сном"',
      'title: "Тиха година перед сном"',
      $yaml,
    );
    $this->assertNotFalse(file_put_contents($path, $yaml));
    $third = $importer->import($path);
    $this->assertSame(0, $third->created);
    $this->assertSame(1, $third->updated);
    $this->assertSame(2, $third->unchanged);
    $this->assertSame(3, $this->countRecommendations());
    $this->assertSame(2, $this->revisionCount('health.sleep.evening_wind_down'));
    $this->assertSame('Тиха година перед сном', $this->loadRecommendation('health.sleep.evening_wind_down')->getTitle());
    $this->assertSame(Recommendation::STATUS_DRAFT, $this->loadRecommendation('health.sleep.evening_wind_down')->getStatus());
  }

  /**
   * A trigger must name an existing criterion in the same sector.
   */
  public function testImportRejectsMismatchedCriterion(): void {
    $importer = $this->importer();
    $yaml = (string) file_get_contents($importer->defaultPath());
    $yaml = str_replace('trigger_criteria: [health.f03]', 'trigger_criteria: [finances.f02]', $yaml);
    $path = sys_get_temp_dir() . '/recommendations-' . uniqid('', TRUE) . '.yml';
    $this->assertNotFalse(file_put_contents($path, $yaml));
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('not in sector');
    $importer->import($path);
  }

  /**
   * Editing does not include publishing. The content editor sees no user data.
   */
  public function testEditorCannotPublishAndCannotSeeSnapshots(): void {
    $this->importer()->import();
    $role = Role::load('content_editor');
    $this->assertInstanceOf(Role::class, $role);
    $permissions = $role->getPermissions();
    sort($permissions);
    $this->assertSame([
      'edit lifebalance recommendations',
      'publish lifebalance recommendations',
    ], $permissions);

    $editor = $this->createUser(['edit lifebalance recommendations']);
    $owner = $this->createUser();
    $snapshot = Snapshot::create([
      'uid' => $owner->id(),
      'note' => 'Секретна нотатка редактора',
    ]);
    $this->assertFalse($snapshot->access('view', $editor));
    $this->assertFalse($snapshot->access('update', $editor));
    $this->assertFalse($snapshot->access('delete', $editor));
    $this->assertFalse($snapshot->access('view', $this->accountWithRole()));

    $recommendation = $this->loadRecommendation('finances.overview.monthly_snapshot');
    $this->setCurrentUser($editor);
    $form_object = $this->container->get('entity_type.manager')
      ->getFormObject('lifebalance_recommendation', 'default')
      ->setEntity($recommendation);
    $form_state = new FormState();
    $form = $this->container->get('form_builder')->buildForm($form_object, $form_state);
    $this->assertIsArray($form['status']['widget']['#options']);
    $this->assertArrayNotHasKey(Recommendation::STATUS_PUBLISHED, $form['status']['widget']['#options']);
    $this->assertArrayHasKey(Recommendation::STATUS_REVIEW, $form['status']['widget']['#options']);

    $recommendation->set('status', Recommendation::STATUS_PUBLISHED);
    try {
      $recommendation->save();
      $this->fail('An editor saved a published recommendation.');
    }
    catch (EntityStorageException $exception) {
      $this->assertStringContainsString('публікації', $exception->getMessage());
    }
    $this->assertSame(
      Recommendation::STATUS_DRAFT,
      $this->loadRecommendation('finances.overview.monthly_snapshot')->getStatus(),
    );

    $draft = $this->loadRecommendation('finances.overview.monthly_snapshot');
    $draft->set('status', Recommendation::STATUS_REVIEW);
    $draft->save();
    $this->assertSame(Recommendation::STATUS_REVIEW, $this->loadRecommendation('finances.overview.monthly_snapshot')->getStatus());

    $publisher = $this->createUser([
      'edit lifebalance recommendations',
      'publish lifebalance recommendations',
    ]);
    $this->setCurrentUser($publisher);
    $published = $this->loadRecommendation('finances.overview.monthly_snapshot');
    $published->set('status', Recommendation::STATUS_PUBLISHED);
    $published->save();
    $this->assertSame(Recommendation::STATUS_PUBLISHED, $this->loadRecommendation('finances.overview.monthly_snapshot')->getStatus());

    $this->assertSame('', $this->config('lifebalance_plans.settings')->get('support_block'));
  }

  /**
   * The admin list exposes sector, status, safety, and tier.
   */
  public function testRecommendationViewListsTheCatalog(): void {
    $this->importer()->import();
    $this->container->get('router.builder')->rebuild();
    $anonymous = $this->container->get('access_manager')->checkNamedRoute(
      'view.lifebalance_recommendations.page_1',
      [],
      new AnonymousUserSession(),
    );
    $this->assertFalse($anonymous);
    $allowed = $this->container->get('access_manager')->checkNamedRoute(
      'view.lifebalance_recommendations.page_1',
      [],
      $this->accountWithRole(),
    );
    $this->assertTrue($allowed);

    $view = Views::getView('lifebalance_recommendations');
    $this->assertNotNull($view);
    $view->setDisplay('page_1');
    $filters = $view->getHandlers('filter');
    foreach (['sector', 'status', 'safety', 'tier'] as $id) {
      $this->assertArrayHasKey($id, $filters);
      $this->assertNotEmpty($filters[$id]['exposed']);
    }
    $this->setCurrentUser($this->accountWithRole());
    $view->execute();
    $this->assertCount(3, $view->result);
  }

  /**
   * The importer service.
   */
  private function importer(): RecommendationImporter {
    return $this->container->get(RecommendationImporter::class);
  }

  /**
   * Stores one published criterion for a catalog trigger.
   */
  private function addCriterion(string $key, string $sector): void {
    Criterion::create([
      'key' => $key,
      'sector' => $sector,
      'tier' => Criterion::TIER_FREE,
      'text' => 'Критерій ' . $key,
      'help' => '',
      'weight' => 0,
      'status' => Criterion::STATUS_PUBLISHED,
      'origin' => Criterion::ORIGIN_SYSTEM,
      'in_formula' => TRUE,
      'uid' => $this->admin->id(),
    ])->save();
  }

  /**
   * Recommendation count.
   */
  private function countRecommendations(): int {
    $ids = $this->container->get('entity_type.manager')
      ->getStorage('lifebalance_recommendation')
      ->getQuery()
      ->accessCheck(TRUE)
      ->execute();
    return count($ids);
  }

  /**
   * Revisions of one catalog key.
   */
  private function revisionCount(string $key): int {
    $ids = $this->container->get('entity_type.manager')
      ->getStorage('lifebalance_recommendation')
      ->getQuery()
      ->accessCheck(TRUE)
      ->allRevisions()
      ->condition('key', $key)
      ->execute();
    return count($ids);
  }

  /**
   * Loads one recommendation by catalog key.
   */
  private function loadRecommendation(string $key): Recommendation {
    $storage = $this->container->get('entity_type.manager')->getStorage('lifebalance_recommendation');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('key', $key)
      ->range(0, 1)
      ->execute();
    $storage->resetCache();
    $entity = $storage->load((int) reset($ids));
    $this->assertInstanceOf(Recommendation::class, $entity);
    return $entity;
  }

  /**
   * A content editor, who has no personal-data permissions.
   */
  private function accountWithRole(): UserInterface {
    $account = $this->createUser();
    $account->addRole('content_editor');
    $account->save();
    return $account;
  }

}
