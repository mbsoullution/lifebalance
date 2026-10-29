<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\lifebalance\Form\RegisterAccountForm;
use Drupal\lifebalance\Service\EntitlementCheckerInterface;
use Drupal\lifebalance\Service\LoginEntry;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Register and premium prompts under the header.
 *
 * An anonymous visitor sees both. A registered visitor without Premium sees
 * only the premium prompt. A Premium account sees neither.
 */
#[Block(
  id: 'lifebalance_account_access',
  admin_label: new TranslatableMarkup('Реєстрація і преміум'),
  category: new TranslatableMarkup('LifeBalance'),
)]
final class AccountAccessBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Builds the prompts.
   *
   * @param array<string, mixed> $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current visitor.
   * @param \Drupal\lifebalance\Service\EntitlementCheckerInterface $entitlements
   *   The Premium check.
   * @param \Drupal\Core\Form\FormBuilderInterface $formBuilder
   *   The form builder.
   * @param \Drupal\lifebalance\Service\LoginEntry $loginEntry
   *   Social sign-in addresses.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected AccountInterface $currentUser,
    protected EntitlementCheckerInterface $entitlements,
    protected FormBuilderInterface $formBuilder,
    protected LoginEntry $loginEntry,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * Creates the header prompts.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container.
   * @param array<string, mixed> $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin definition.
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_user'),
      $container->get(EntitlementCheckerInterface::class),
      $container->get('form_builder'),
      $container->get(LoginEntry::class),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The prompts and their dialogs.
   */
  public function build(): array {
    $account = $this->currentUser;
    $cache = $this->entitlements->getCacheability($account);
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['lifebalance-access']],
      '#attached' => [
        'library' => ['lifebalance/account-access'],
      ],
      '#cache' => [
        'contexts' => $cache->getCacheContexts(),
        'tags' => $cache->getCacheTags(),
        'max-age' => 0,
      ],
      'actions' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['lifebalance-access__actions']],
      ],
    ];
    if ($account->isAnonymous()) {
      $build['actions']['register'] = $this->prompt(
        'register',
        'lifebalance-register-open',
        $this->t('Зареєструватися'),
      );
      $build['register'] = $this->registerDialog();
    }
    $build['actions']['premium'] = $this->prompt(
      'premium',
      'lifebalance-premium-open',
      $this->t('Отримати преміум'),
    );
    $build['premium'] = $this->premiumDialog();
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account): AccessResultInterface {
    $result = AccessResult::allowedIf($account->isAnonymous() || !$this->entitlements->hasPremium($account));
    $result->cachePerUser();
    $result->addCacheableDependency($this->entitlements->getCacheability($account));
    return $result;
  }

  /**
   * One prompt button.
   *
   * @return array<string, mixed>
   *   A button that opens a dialog.
   */
  private function prompt(string $kind, string $action, TranslatableMarkup $label): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => $label,
      '#attributes' => [
        'type' => 'button',
        'class' => [
          'lifebalance-access__button',
          'lifebalance-access__button--' . $kind,
        ],
        'data-' . $action => '1',
      ],
    ];
  }

  /**
   * Registration dialog: social buttons and the email form.
   *
   * @return array<string, mixed>
   *   The dialog.
   */
  private function registerDialog(): array {
    $links = [];
    $google = $this->loginEntry->networkUrl('google');
    $facebook = $this->loginEntry->networkUrl('facebook');
    if ($google !== NULL) {
      $links['google'] = $this->networkLink($google, $this->t('Продовжити з Google'));
    }
    if ($facebook !== NULL) {
      $links['facebook'] = $this->networkLink($facebook, $this->t('Продовжити з Facebook'));
    }
    $dialog = [
      '#type' => 'container',
      '#attributes' => ['class' => ['lifebalance-access-dialog__panel']],
      '#prefix' => $this->dialogOpen('register', 'lifebalance-register-title'),
      '#suffix' => Markup::create('</dialog>'),
      'close' => $this->closeButton(),
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Реєстрація'),
        '#attributes' => [
          'id' => 'lifebalance-register-title',
          'class' => ['lifebalance-access-dialog__title'],
        ],
      ],
      'lead' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Обліковий запис зберігає колесо й історію.'),
      ],
      'ways' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Увійти можна через Google, Facebook або електронну пошту.'),
      ],
    ];
    if ($links !== []) {
      $dialog['networks'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['lifebalance-access__networks']],
      ] + $links;
      $dialog['or'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Або за електронною поштою'),
        '#attributes' => ['class' => ['lifebalance-access-dialog__or']],
      ];
    }
    $dialog['form'] = $this->formBuilder->getForm(RegisterAccountForm::class);
    return $dialog;
  }

  /**
   * What Premium adds. Checkout stays closed.
   *
   * @return array<string, mixed>
   *   The dialog.
   */
  private function premiumDialog(): array {
    return [
      '#type' => 'container',
      '#prefix' => $this->dialogOpen('premium', 'lifebalance-premium-title'),
      '#suffix' => Markup::create('</dialog>'),
      'close' => $this->closeButton(),
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Що дає преміум'),
        '#attributes' => [
          'id' => 'lifebalance-premium-title',
          'class' => ['lifebalance-access-dialog__title'],
        ],
      ],
      'free' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Після реєстрації: колесо, 80 тверджень, історія, порівняння і динаміка.'),
      ],
      'list' => [
        '#theme' => 'item_list',
        '#title' => $this->t('Преміум додає'),
        '#items' => [
          $this->t('Ще 80 поглиблених тверджень.'),
          $this->t('Рекомендації за вашими відповідями.'),
          $this->t('Особисті цілі з чеклістами.'),
          $this->t('План на 30 днів.'),
        ],
      ],
      'note' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Зараз розширений доступ відкривається за запрошенням.'),
      ],
    ];
  }

  /**
   * Opens one dialog. The flag is one of the two known names.
   */
  private function dialogOpen(string $flag, string $titleId): MarkupInterface {
    return Markup::create('<dialog class="lifebalance-access-dialog"'
      . ' data-lifebalance-' . $flag . '-dialog'
      . ' aria-labelledby="' . $titleId . '">');
  }

  /**
   * Closes the dialog that contains this button.
   *
   * @return array<string, mixed>
   *   A close button.
   */
  private function closeButton(): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => '×',
      '#attributes' => [
        'type' => 'button',
        'class' => ['lifebalance-access-dialog__close'],
        'data-lifebalance-access-close' => '1',
        'aria-label' => $this->t('Закрити'),
      ],
    ];
  }

  /**
   * One social registration link.
   *
   * @return array<string, mixed>
   *   A link styled as a button.
   */
  private function networkLink(string $path, TranslatableMarkup $label): array {
    return [
      '#type' => 'link',
      '#title' => $label,
      '#url' => Url::fromUserInput($path),
      '#attributes' => [
        'class' => [
          'lifebalance-access__button',
          'lifebalance-access__button--network',
        ],
      ],
    ];
  }

}
