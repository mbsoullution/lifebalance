<?php

declare(strict_types=1);

namespace Drupal\Tests\lifebalance\Kernel;

use Drupal\Core\Config\ConfigException;
use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\lifebalance\Entity\Sector;
use Drupal\lifebalance\Form\SectorSettingsForm;
use Drupal\lifebalance\Repository\SectorRepository;
use Drupal\lifebalance\Service\ScaleLabels;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;

/**
 * Sectors: order, immutable IDs, and no deletion.
 */
#[Group('lifebalance')]
final class SectorKernelTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'lifebalance'];

  /**
   * Expected active sector IDs.
   *
   * @var list<string>
   */
  private array $expectedIds = [
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
    $this->installConfig(['lifebalance']);
  }

  /**
   * Eight active sectors are installed in concept order.
   */
  public function testActiveSectorsInOrder(): void {
    $sectors = $this->repository()->loadActive();
    $this->assertSame($this->expectedIds, array_keys($sectors));
    $this->assertSame("Здоров'я", $sectors['health']->label());
    $this->assertSame('Стосунки', $sectors['relationships']->label());
    $this->assertSame('Оточення', $sectors['environment']->label());
    $this->assertSame(
      "Близькі люди: спілкування, дружба, любов, сім'я.",
      $sectors['relationships']->getDescription(),
    );
    $this->assertSame(
      'Ширше коло: родичі, колеги, друзі, сусіди, опоненти.',
      $sectors['environment']->getDescription(),
    );
    $this->assertTrue($sectors['purpose']->status());
    $this->assertSame('Покликання', $sectors['purpose']->label());
    $this->assertSame('', $sectors['health']->getScaleLow());
    $this->assertSame('', $sectors['health']->getScaleHigh());
    $translation = $this->container->get('string_translation');
    $this->assertNull(ScaleLabels::caption($sectors['health'], $translation));

    $sectors['health']->setStatus(FALSE)->save();
    $active = $this->repository()->loadActive();
    $this->assertArrayNotHasKey('health', $active);
    $this->assertCount(7, $active);
    $this->assertArrayHasKey('health', $this->repository()->loadAll());
  }

  /**
   * A sector ID cannot be renamed and the sector cannot be deleted.
   */
  public function testIdAndDeletionAreLocked(): void {
    $sector = Sector::load('health');
    $this->assertInstanceOf(Sector::class, $sector);
    $sector->set('id', 'career');
    $this->expectException(ConfigException::class);
    $sector->save();
  }

  /**
   * Deleting a sector throws and leaves the config in place.
   */
  public function testSectorCannotBeDeleted(): void {
    $admin = $this->createUser(['administer lifebalance sectors']);
    $this->assertFalse(Sector::load('health')->access('delete', $admin));
    $this->assertTrue(Sector::load('health')->access('update', $admin));
    $member = $this->createUser([]);
    $this->assertTrue(Sector::load('health')->access('view', $member));
    $this->assertFalse(Sector::load('health')->access('update', $member));

    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage('cannot be deleted');
    Sector::load('health')->delete();
  }

  /**
   * The site manager role receives the sector permission.
   */
  public function testSiteManagerRole(): void {
    $role = Role::load('site_manager');
    $this->assertInstanceOf(Role::class, $role);
    $this->assertTrue($role->hasPermission('administer lifebalance sectors'));
    $this->assertSame('Менеджер сайту', $role->label());
  }

  /**
   * The admin form updates fields and does not expose deletion or the ID.
   */
  public function testSettingsFormLocksId(): void {
    $account = $this->createUser(['administer lifebalance sectors']);
    $this->setCurrentUser($account);
    $access_manager = $this->container->get('access_manager');
    $this->assertTrue($access_manager->checkNamedRoute('lifebalance.sectors', [], $account));
    $this->assertFalse($access_manager->checkNamedRoute('lifebalance.sectors', [], new AnonymousUserSession()));

    $form = $this->container->get('form_builder')->getForm(SectorSettingsForm::class);
    $this->assertSame('item', $form['sectors']['health']['id']['#type']);
    $this->assertArrayNotHasKey('delete', $form);
    $this->assertArrayNotHasKey('delete', $form['actions']);

    $values = [];
    foreach ($this->repository()->loadAll() as $id => $sector) {
      $values[$id] = [
        'label' => $id === 'health' ? 'Нова назва' : $sector->label(),
        'description' => $sector->getDescription(),
        'scale_low' => $id === 'health' ? 'зовсім ні' : $sector->getScaleLow(),
        'scale_high' => $id === 'health' ? 'саме так' : $sector->getScaleHigh(),
        'weight' => $sector->getWeight(),
        'color' => $sector->getColor(),
        'status' => $sector->status(),
      ];
    }
    $values['career'] = $values['health'];
    $form_state = new FormState();
    $form_state->setValues(['sectors' => $values]);
    $this->container->get('form_builder')->submitForm(SectorSettingsForm::class, $form_state);
    $this->assertSame([], $form_state->getErrors());

    $health = Sector::load('health');
    $this->assertInstanceOf(Sector::class, $health);
    $this->assertSame('health', $health->id());
    $this->assertSame('Нова назва', $health->label());
    $this->assertSame('зовсім ні', $health->getScaleLow());
    $this->assertSame('саме так', $health->getScaleHigh());
    $translation = $this->container->get('string_translation');
    $this->assertSame('0 — зовсім ні. 10 — саме так.', (string) ScaleLabels::caption($health, $translation));
    $this->assertNull(Sector::load('career'));
  }

  /**
   * Repository used by the test.
   */
  private function repository(): SectorRepository {
    return $this->container->get(SectorRepository::class);
  }

}
