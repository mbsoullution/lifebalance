<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\lifebalance\Controller\CriterionListController;
use Drupal\lifebalance\Controller\CriterionRevisionController;
use Drupal\lifebalance\CriterionStorage;
use Drupal\lifebalance\Entity\Criterion;
use Drupal\lifebalance\Form\CriterionForm;
use Drupal\lifebalance\Repository\CriterionRepository;
use Drupal\lifebalance\Service\CriterionImporter;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Criteria import, revisions, and the rule that nothing is deleted.
 */
#[Group('lifebalance')]
final class CriterionKernelTest extends KernelTestBase {

  use UserCreationTrait;

  private const CHECKSUM = '2fa2c2ff01f5f6e347b3e04123238827536c8c0b917f4aa5bb5be78d1d355729';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'options',
    'lifebalance',
  ];

  /**
   * Sector IDs in concept order.
   *
   * @var list<string>
   */
  private array $sectors = [
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
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('lifebalance_criterion');
    $this->installConfig(['lifebalance']);
    $this->setCurrentUser($this->createUser(['administer lifebalance criteria']));
  }

  /**
   * The shipped catalog imports 160 criteria and a second run changes nothing.
   */
  public function testCatalogImportIsIdempotent(): void {
    $path = $this->importer()->defaultPath();
    $this->assertSame(self::CHECKSUM, hash_file('sha256', $path));

    $first = $this->importer()->import();
    $this->assertSame(160, $first->created);
    $this->assertSame(0, $first->updated);
    $this->assertSame(0, $first->warned);

    foreach ($this->sectors as $sector) {
      $this->assertCount(10, $this->repository()->loadPublished($sector, Criterion::TIER_FREE));
      $this->assertCount(10, $this->repository()->loadPublished($sector, Criterion::TIER_PREMIUM));
      $published = $this->repository()->loadPublished($sector);
      $this->assertCount(20, $published);
      $weights = array_map(static fn (Criterion $criterion): int => $criterion->getWeight(), $published);
      $sorted = $weights;
      sort($sorted);
      $this->assertSame($sorted, $weights);
    }

    $neutral = $this->repository()->loadByKey('health.f02');
    $this->assertInstanceOf(Criterion::class, $neutral);
    $this->assertSame('Загалом мене влаштовує мій фізичний стан', $neutral->getText());
    $this->assertSame(Criterion::STATUS_PUBLISHED, $neutral->getStatus());
    $this->assertSame(Criterion::ORIGIN_SYSTEM, $neutral->getOrigin());
    $this->assertTrue($neutral->isInFormula());

    $revisions = $this->revisionMap();
    $second = $this->importer()->import();
    $this->assertSame(0, $second->created);
    $this->assertSame(0, $second->updated);
    $this->assertSame(160, $second->unchanged);
    $this->assertSame(0, $second->warned);
    $this->assertSame($revisions, $this->revisionMap());

    $schema = $this->container->get('database')->schema();
    $this->assertTrue($schema->tableExists('lifebalance_criterion'));
    $this->assertTrue($schema->tableExists('lifebalance_criterion_revision'));
    $this->assertFalse($schema->tableExists('lifebalance_criterion_field_data'));
    $this->assertTrue($schema->indexExists('lifebalance_criterion', 'criterion__sector_tier_status_weight'));

    $role = Role::load('site_manager');
    $this->assertInstanceOf(Role::class, $role);
    $this->assertTrue($role->hasPermission('administer lifebalance criteria'));
    $this->assertTrue($role->hasPermission('administer lifebalance sectors'));
  }

  /**
   * Wording changes add a revision. Order and archive do not.
   */
  public function testRevisionsFollowWording(): void {
    $this->importer()->import();
    $before = $this->revisionMap();

    $weighted = $this->mustLoad('health.f02');
    $catalog_weight = $weighted->getWeight();
    $weighted->set('weight', 50);
    $weighted->setNewRevision(FALSE);
    $weighted->save();
    $this->assertSame($before['health.f02'], (int) $weighted->getRevisionId());

    $this->importer()->import();
    $weighted = $this->mustLoad('health.f02');
    $this->assertSame($catalog_weight, $weighted->getWeight());
    $this->assertSame($before['health.f02'], (int) $weighted->getRevisionId());

    $archived = $this->mustLoad('health.f06');
    $archived_revision = (int) $archived->getRevisionId();
    $archived->set('status', Criterion::STATUS_ARCHIVED);
    $archived->save();
    $this->assertSame($archived_revision, (int) $archived->getRevisionId());
    $this->assertSame(Criterion::STATUS_ARCHIVED, $archived->getStatus());

    $this->importer()->import();
    $archived = $this->mustLoad('health.f06');
    $this->assertSame(Criterion::STATUS_ARCHIVED, $archived->getStatus());
    $this->assertSame($archived_revision, (int) $archived->getRevisionId());
    $this->assertSame(Criterion::ORIGIN_SYSTEM, $archived->getOrigin());
    $this->assertTrue($archived->isInFormula());

    $target = $this->mustLoad('health.f05');
    $file_revision = (int) $target->getRevisionId();
    $original_text = $target->getText();
    $temp = $this->temporaryCatalog(static function (array &$data): void {
      foreach ($data['criteria'] as &$row) {
        if (is_array($row) && ($row['key'] ?? NULL) === 'health.f05') {
          $row['text'] = 'Текст змінено у файлі каталогу';
        }
      }
    });
    $imported = $this->importer()->import($temp);
    @unlink($temp);
    $this->assertSame(1, $imported->updated);
    $target = $this->mustLoad('health.f05');
    $this->assertSame('health.f05', $target->getCatalogKey());
    $this->assertSame('Текст змінено у файлі каталогу', $target->getText());
    $this->assertNotSame($file_revision, (int) $target->getRevisionId());
    $this->assertSame(
      [$file_revision, (int) $target->getRevisionId()],
      $this->repository()->revisionIds($target),
    );
    $file_previous = $this->repository()->loadRevision($file_revision);
    $this->assertInstanceOf(Criterion::class, $file_previous);
    $this->assertSame($original_text, $file_previous->getText());

    $with_help = $this->mustLoad('health.f03');
    $help_revision = (int) $with_help->getRevisionId();
    $with_help->set('help', 'Коротке пояснення');
    $with_help->setNewRevision(FALSE);
    $with_help->save();
    $this->assertNotSame($help_revision, (int) $with_help->getRevisionId());
    $this->assertSame(
      [$help_revision, (int) $with_help->getRevisionId()],
      $this->repository()->revisionIds($with_help),
    );
    $previous = $this->repository()->loadRevision($help_revision);
    $this->assertInstanceOf(Criterion::class, $previous);
    $this->assertSame('', $previous->getHelp());
    $this->assertSame('Коротке пояснення', $with_help->getHelp());

    $wording = $this->mustLoad('health.f04');
    $old_text = $wording->getText();
    $old_revision = (int) $wording->getRevisionId();
    $wording->set('text', 'Оновлене формулювання для перевірки ревізії');
    $wording->setNewRevision(FALSE);
    $wording->save();
    $this->assertNotSame($old_revision, (int) $wording->getRevisionId());
    $this->assertSame('health.f04', $wording->getCatalogKey());
    $stored = $this->repository()->loadRevision($old_revision);
    $this->assertInstanceOf(Criterion::class, $stored);
    $this->assertSame($old_text, $stored->getText());
  }

  /**
   * A criterion and every revision stay in storage. Archive is the exit.
   */
  public function testCannotDeleteCriterionOrRevision(): void {
    $this->importer()->import();
    $criterion = $this->mustLoad('health.f01');
    $admin = $this->createUser(['administer lifebalance criteria']);
    $this->assertFalse($criterion->access('delete', $admin));
    $this->assertFalse($criterion->access('delete revision', $admin));
    $this->assertTrue($criterion->access('update', $admin));
    $this->assertTrue($criterion->access('view', new AnonymousUserSession()));
    $this->assertFalse($criterion->access('update', new AnonymousUserSession()));

    $old_revision = (int) $criterion->getRevisionId();
    $criterion->set('text', 'Інше формулювання');
    $criterion->setNewRevision(FALSE);
    $criterion->save();
    $storage = $this->container->get('entity_type.manager')->getStorage('lifebalance_criterion');
    $this->assertInstanceOf(CriterionStorage::class, $storage);

    try {
      $storage->deleteRevision($old_revision);
    }
    catch (EntityStorageException $exception) {
      $this->assertStringContainsString('cannot be deleted', $exception->getMessage());
    }
    $this->assertInstanceOf(Criterion::class, $this->repository()->loadRevision($old_revision));

    $id = $criterion->id();
    try {
      $criterion->delete();
      $this->fail('A criterion delete must be rejected.');
    }
    catch (EntityStorageException $exception) {
      $this->assertStringContainsString('cannot be deleted', $exception->getMessage());
    }
    $this->assertInstanceOf(Criterion::class, Criterion::load($id));

    try {
      $criterion = $this->mustLoad('health.f01');
      $criterion->set('key', 'health.f99');
      $criterion->save();
      $this->fail('A key change must be rejected.');
    }
    catch (EntityStorageException $exception) {
      $this->assertStringContainsString('cannot be changed', $exception->getMessage());
    }
    $this->assertInstanceOf(Criterion::class, $this->repository()->loadByKey('health.f01'));
    $this->assertNull($this->repository()->loadByKey('health.f99'));
  }

  /**
   * The admin list and form expose archive and hide deletion.
   */
  public function testAdminListAndForm(): void {
    $this->importer()->import();
    $account = $this->createUser(['administer lifebalance criteria']);
    $this->setCurrentUser($account);
    $access_manager = $this->container->get('access_manager');
    $anonymous = new AnonymousUserSession();
    $this->assertFalse($access_manager->checkNamedRoute('entity.lifebalance_criterion.collection', [], $anonymous));
    $this->assertTrue($access_manager->checkNamedRoute('entity.lifebalance_criterion.collection', [], $account));

    $request = Request::create('/admin/lifebalance/criteria', 'GET', ['sector' => 'health']);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
    $build = CriterionListController::create($this->container)->list();
    $this->assertCount(20, $build['table']['#rows']);
    $links = $build['table']['#rows'][0][6]['data']['#links'];
    $this->assertArrayHasKey('edit', $links);
    $this->assertArrayHasKey('revisions', $links);
    $this->assertArrayHasKey('archive', $links);
    $this->assertArrayNotHasKey('delete', $links);

    $criterion = $this->mustLoad('health.f01');
    $this->assertFalse($access_manager->checkNamedRoute('entity.lifebalance_criterion.edit_form', [
      'lifebalance_criterion' => $criterion->id(),
    ], $anonymous));
    $form = $this->container->get('entity.form_builder')->getForm($criterion);
    $this->assertTrue($form['key']['widget'][0]['value']['#disabled']);
    $this->assertArrayNotHasKey('delete', $form['actions']);
    $this->assertArrayHasKey('archive', $form['actions']);
    $this->assertSame('select', $form['sector']['widget'][0]['value']['#type']);
    $this->assertArrayHasKey('subtopic', $form);

    $post = Request::create('/admin/lifebalance/criteria/' . $criterion->id() . '/edit', 'POST');
    $post->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($post);
    $old_revision = (int) $criterion->getRevisionId();
    $form_object = $this->container->get('entity_type.manager')->getFormObject('lifebalance_criterion', 'default');
    $this->assertInstanceOf(CriterionForm::class, $form_object);
    $form_object->setEntity($criterion);
    $form_state = new FormState();
    $form_state->setTriggeringElement($form['actions']['submit']);
    $form_state->setValues([
      'text' => [0 => ['value' => 'Формулювання збережене через форму']],
      'help' => [0 => ['value' => '']],
      'weight' => [0 => ['value' => $criterion->getWeight()]],
      'sector' => [0 => ['value' => 'health']],
      'tier' => Criterion::TIER_FREE,
      'status' => Criterion::STATUS_PUBLISHED,
      'in_formula' => ['value' => 1],
      'key' => [0 => ['value' => 'health.changed']],
      'subtopic' => [0 => ['value' => 'Сон']],
    ]);
    $this->container->get('form_builder')->submitForm($form_object, $form_state);
    $this->assertTrue($form_state->isExecuted());
    $this->assertSame([], $form_state->getErrors());
    $saved = $this->mustLoad('health.f01');
    $this->assertSame('Формулювання збережене через форму', $saved->getText());
    $this->assertSame('Сон', $saved->getSubtopic());
    $this->assertSame('health.f01', $saved->getCatalogKey());
    $this->assertGreaterThan($old_revision, (int) $saved->getRevisionId());

    $revisions = CriterionRevisionController::create($this->container)->list($saved);
    $this->assertCount(2, $revisions['table']['#rows']);
    $this->assertSame(self::CHECKSUM, hash_file('sha256', $this->importer()->defaultPath()));
  }

  /**
   * A bad catalog file writes nothing. A removed key is kept and reported.
   */
  public function testInvalidCatalogAndMissingKey(): void {
    $temp = $this->temporaryCatalog(static function (array &$data): void {
      $data['criteria'] = [
        [
          'key' => 'nope.f01',
          'sector' => 'nope',
          'tier' => 'free',
          'weight' => 1,
          'text' => 'Чужий сектор',
        ],
      ];
    });
    try {
      $this->importer()->import($temp);
      $this->fail('An unknown sector must be rejected.');
    }
    catch (\InvalidArgumentException $exception) {
      $this->assertStringContainsString('Unknown sector', $exception->getMessage());
    }
    @unlink($temp);
    $this->assertSame([], $this->repository()->loadByFilters());

    $this->importer()->import();
    $removed = $this->temporaryCatalog(static function (array &$data): void {
      $criteria = [];
      foreach ($data['criteria'] as $row) {
        if (is_array($row) && ($row['key'] ?? NULL) !== 'health.f01') {
          $criteria[] = $row;
        }
      }
      $data['criteria'] = $criteria;
    });
    $result = $this->importer()->import($removed);
    @unlink($removed);
    $this->assertGreaterThanOrEqual(1, $result->warned);
    $this->assertInstanceOf(Criterion::class, $this->repository()->loadByKey('health.f01'));
    $this->assertCount(160, $this->repository()->loadByFilters());
  }

  /**
   * Subtopic is stored without a revision and survives a catalog import.
   */
  public function testSubtopicSkipsRevisionAndImport(): void {
    $this->importer()->import();
    $criterion = $this->mustLoad('health.f01');
    $this->assertSame('', $criterion->getSubtopic());
    $revision = (int) $criterion->getRevisionId();
    $criterion->set('subtopic', '  Сон  ');
    $criterion->setNewRevision(FALSE);
    $criterion->save();
    $criterion = $this->mustLoad('health.f01');
    $this->assertSame('Сон', $criterion->getSubtopic());
    $this->assertSame($revision, (int) $criterion->getRevisionId());

    $kept = $this->importer()->import();
    $this->assertSame(0, $kept->updated);
    $this->assertSame('Сон', $this->mustLoad('health.f01')->getSubtopic());
    $this->assertSame($revision, (int) $this->mustLoad('health.f01')->getRevisionId());

    $with = $this->temporaryCatalog(static function (array &$data): void {
      foreach ($data['criteria'] as &$row) {
        if (is_array($row) && ($row['key'] ?? NULL) === 'health.f01') {
          $row['subtopic'] = 'Режим';
        }
      }
    });
    $updated = $this->importer()->import($with);
    @unlink($with);
    $criterion = $this->mustLoad('health.f01');
    $this->assertSame(1, $updated->updated);
    $this->assertSame('Режим', $criterion->getSubtopic());
    $this->assertSame($revision, (int) $criterion->getRevisionId());
  }

  /**
   * Importer service.
   */
  private function importer(): CriterionImporter {
    return $this->container->get(CriterionImporter::class);
  }

  /**
   * Repository service.
   */
  private function repository(): CriterionRepository {
    return $this->container->get(CriterionRepository::class);
  }

  /**
   * Loads a catalog key or fails the test.
   */
  private function mustLoad(string $key): Criterion {
    $criterion = $this->repository()->loadByKey($key);
    $this->assertInstanceOf(Criterion::class, $criterion);
    return $criterion;
  }

  /**
   * Current revision ID of every criterion, keyed by catalog key.
   *
   * @return array<string, int>
   *   Revision IDs.
   */
  private function revisionMap(): array {
    $map = [];
    foreach ($this->repository()->loadByFilters() as $criterion) {
      $map[$criterion->getCatalogKey()] = (int) $criterion->getRevisionId();
    }
    ksort($map);
    return $map;
  }

  /**
   * Writes a modified copy of the shipped catalog.
   *
   * @param callable(array<string, mixed>): void $alter
   *   Alters the decoded catalog.
   */
  private function temporaryCatalog(callable $alter): string {
    $decoded = Yaml::decode((string) file_get_contents($this->importer()->defaultPath()));
    $this->assertIsArray($decoded);
    $alter($decoded);
    $path = tempnam(sys_get_temp_dir(), 'lb-criteria-');
    $this->assertIsString($path);
    file_put_contents($path, Yaml::encode($decoded));
    return $path;
  }

}
