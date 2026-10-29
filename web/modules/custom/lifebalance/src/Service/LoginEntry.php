<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Service;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\user\Form\UserLoginForm;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Anonymous sign-in icons and the password form they open.
 */
final class LoginEntry {

  use StringTranslationTrait;

  public function __construct(
    private readonly RouteProviderInterface $routeProvider,
    private readonly FormBuilderInterface $formBuilder,
    private readonly RouteMatchInterface $routeMatch,
    private readonly RequestStack $requestStack,
    private readonly RendererInterface $renderer,
  ) {}

  /**
   * Label and provider icons. The door opens the password form.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function icons(): array {
    return [
      '#theme' => 'lifebalance_login_icons',
      '#google_url' => $this->providerUrl('google'),
      '#facebook_url' => $this->providerUrl('facebook'),
      '#attached' => [
        'library' => ['lifebalance/login-icons'],
      ],
      '#cache' => [
        'contexts' => ['route'],
      ],
    ];
  }

  /**
   * Password form in a dialog.
   *
   * The login page wraps its own form, so this stays empty there.
   *
   * @return array<string, mixed>|null
   *   A dialog, or NULL on the login page.
   */
  public function dialog(): ?array {
    if ($this->routeMatch->getRouteName() === 'user.login') {
      return NULL;
    }
    $form = $this->formBuilder->getForm(UserLoginForm::class);
    $path = $this->requestStack->getCurrentRequest()?->getPathInfo() ?? '/';
    $form['#action'] = Url::fromRoute('user.login', [], [
      'query' => ['destination' => $path],
    ])->toString();
    $dialog = '<dialog class="lifebalance-login-dialog"'
      . ' data-lifebalance-login-dialog'
      . ' aria-labelledby="lifebalance-login-title">';
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['lifebalance-login-dialog__panel']],
      '#prefix' => Markup::create($dialog),
      '#suffix' => Markup::create('</dialog>'),
      'close' => [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => '×',
        '#attributes' => [
          'type' => 'button',
          'class' => ['lifebalance-login-dialog__close'],
          'data-lifebalance-login-close' => '1',
          'aria-label' => $this->t('Закрити'),
        ],
      ],
      'form' => $form,
    ];
  }

  /**
   * Centers the password form and gives it the shared heading.
   *
   * @param array<string, mixed> $form
   *   The user login form.
   * @param bool $show_dialog
   *   Open the login-page dialog immediately, after a failed sign-in.
   *
   * @return array<string, mixed>
   *   The same form.
   */
  public function prepareForm(array $form, bool $show_dialog = FALSE): array {
    $attributes = $form['#attributes'] ?? [];
    if (!is_array($attributes)) {
      $attributes = [];
    }
    $classes = $attributes['class'] ?? [];
    if (!is_array($classes)) {
      $classes = [$classes];
    }
    if (!in_array('lifebalance-login-form', $classes, TRUE)) {
      $classes[] = 'lifebalance-login-form';
    }
    $attributes['class'] = $classes;
    $form['#attributes'] = $attributes;
    if (isset($form['name']['#attributes']['autofocus'])) {
      unset($form['name']['#attributes']['autofocus']);
    }
    $form['name']['#title'] = $this->t("Ім'я або пошта");
    $form['pass']['#title'] = $this->t('Пароль');
    $form['actions']['submit']['#value'] = $this->t('Увійти');
    if (!isset($form['lifebalance_heading'])) {
      $form['lifebalance_heading'] = [
        '#type' => 'html_tag',
        '#tag' => $this->routeMatch->getRouteName() === 'user.login' ? 'h1' : 'h2',
        '#value' => $this->t('ВХІД'),
        '#weight' => -10,
        '#attributes' => [
          'class' => ['lifebalance-login-form__title'],
          'id' => 'lifebalance-login-title',
        ],
      ];
    }
    if ($this->routeMatch->getRouteName() === 'user.login' && empty($form['#lifebalance_dialog'])) {
      $form['#lifebalance_dialog'] = TRUE;
      $form['#attached']['library'][] = 'lifebalance/login-icons';
      $form['#prefix'] = Markup::create($this->loginPageShell($show_dialog));
      $form['#suffix'] = Markup::create('</dialog>');
    }
    return $form;
  }

  /**
   * Visible icons, then a closed dialog around the login-page form.
   *
   * @param bool $show_dialog
   *   TRUE after a failed sign-in, so the dialog opens immediately.
   */
  private function loginPageShell(bool $show_dialog): string {
    $icons = $this->icons();
    $icons_markup = (string) $this->renderer->renderInIsolation($icons);
    $show = $show_dialog ? ' data-lifebalance-login-show' : '';
    $close_label = Html::escape((string) $this->t('Закрити'));
    return '<div class="lifebalance-login-page-icons">' . $icons_markup
      . '</div><dialog class="lifebalance-login-dialog"'
      . ' data-lifebalance-login-dialog'
      . ' aria-labelledby="lifebalance-login-title"' . $show . '>'
      . '<button type="button" class="lifebalance-login-dialog__close"'
      . ' data-lifebalance-login-close="1" aria-label="' . $close_label
      . '">×</button>';
  }

  /**
   * Social Auth redirect, or NULL when that provider is not installed.
   */
  public function networkUrl(string $network): ?string {
    return $this->providerUrl($network);
  }

  /**
   * Social Auth redirect, or NULL when that provider is not installed.
   */
  private function providerUrl(string $network): ?string {
    if ($this->routeProvider->getRoutesByNames(['social_auth.network.redirect']) === []) {
      return NULL;
    }
    return Url::fromRoute('social_auth.network.redirect', [
      'network' => $network,
    ])->toString();
  }

}
