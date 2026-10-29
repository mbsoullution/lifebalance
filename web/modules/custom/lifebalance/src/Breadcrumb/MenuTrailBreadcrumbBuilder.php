<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Breadcrumb;

use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\TitleResolverInterface;
use Drupal\Core\Link;
use Drupal\Core\Menu\MenuLinkInterface;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Menu\MenuTreeParameters;
use Drupal\Core\ParamConverter\ParamNotConvertedException;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\PathProcessor\InboundPathProcessorInterface;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;

/**
 * Breadcrumbs follow the main menu, then any deeper page.
 *
 * A page in a submenu is Головна, the parent item, and that submenu item.
 * A page past the menu item keeps those links and adds its own title.
 */
final class MenuTrailBreadcrumbBuilder implements BreadcrumbBuilderInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly MenuLinkTreeInterface $menuTree,
    private readonly CurrentPathStack $currentPath,
    private readonly TitleResolverInterface $titleResolver,
    private readonly AccessManagerInterface $accessManager,
    private readonly InboundPathProcessorInterface $pathProcessor,
    private readonly RequestMatcherInterface $router,
    private readonly PathMatcherInterface $pathMatcher,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match): bool {
    $route = $route_match->getRouteObject();
    if ($route !== NULL && $route->getOption('_admin_route')) {
      return FALSE;
    }
    $path = $this->currentPath->getPath();
    return !str_starts_with($path, '/admin') && !str_starts_with($path, '/api/');
  }

  /**
   * {@inheritdoc}
   */
  public function build(RouteMatchInterface $route_match): Breadcrumb {
    $breadcrumb = new Breadcrumb();
    $breadcrumb->addCacheContexts([
      'languages:language_interface',
      'route',
      'url.path',
      'url.path.is_front',
      'user',
      'user.roles:authenticated',
    ]);
    $breadcrumb->addCacheTags([
      'config:system.menu.main',
      'config:system.menu.footer',
    ]);
    $current = $this->currentPath->getPath();
    $indexed = $this->indexMenus(['main', 'footer'], $breadcrumb);
    if ($this->pathMatcher->isFrontPage()) {
      return $breadcrumb;
    }
    $match = $this->bestLink($indexed['by_path'], $current);
    $base = '';
    $items = [];
    $this->remember($items, (string) $this->t('Home'), Url::fromRoute('<front>'), '/');
    if ($match instanceof MenuLinkInterface) {
      foreach ($this->chain($match, $indexed['by_id']) as $link) {
        $path = $this->linkPath($link);
        if ($path === NULL) {
          continue;
        }
        $this->remember($items, (string) $link->getTitle(), $link->getUrlObject(), $path);
        $base = $path;
      }
    }
    foreach ($this->tailLinks($base, $current, $breadcrumb) as $tail) {
      $this->remember($items, $tail['title'], $tail['url'], $tail['path']);
    }
    $links = [];
    foreach ($items as $item) {
      $links[] = $item['link'];
    }
    return $breadcrumb->setLinks($links);
  }

  /**
   * Enabled menu links the current person may open, keyed two ways.
   *
   * @param list<string> $menus
   *   Menu names, earlier menus win a shared path.
   * @param \Drupal\Core\Breadcrumb\Breadcrumb $breadcrumb
   *   Trail that records the access checks.
   *
   * @return array{by_id: array<string, MenuLinkInterface>, by_path: array<string, MenuLinkInterface>}
   *   Links by plugin id and by internal path.
   */
  private function indexMenus(array $menus, Breadcrumb $breadcrumb): array {
    $by_id = [];
    $by_path = [];
    foreach ($menus as $menu) {
      try {
        $parameters = (new MenuTreeParameters())->onlyEnabledLinks();
        $tree = $this->menuTree->load($menu, $parameters);
      }
      catch (\Exception) {
        continue;
      }
      $tree = $this->menuTree->transform($tree, [
        ['callable' => 'menu.default_tree_manipulators:checkAccess'],
        ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
      ]);
      $this->collect($tree, $by_id, $by_path, $breadcrumb);
    }
    return ['by_id' => $by_id, 'by_path' => $by_path];
  }

  /**
   * Adds one menu level to the path index.
   *
   * @param \Drupal\Core\Menu\MenuLinkTreeElement[] $tree
   *   One level of the menu.
   * @param array<string, MenuLinkInterface> $by_id
   *   Links keyed by plugin id.
   * @param array<string, MenuLinkInterface> $by_path
   *   Links keyed by internal path. The first menu keeps the path.
   * @param \Drupal\Core\Breadcrumb\Breadcrumb $breadcrumb
   *   Trail that records the access checks.
   */
  private function collect(array $tree, array &$by_id, array &$by_path, Breadcrumb $breadcrumb): void {
    foreach ($tree as $element) {
      $access = $element->access;
      if ($access instanceof CacheableDependencyInterface) {
        $breadcrumb->addCacheableDependency($access);
      }
      $allowed = !$access instanceof AccessResultInterface || $access->isAllowed();
      if ($allowed) {
        $link = $element->link;
        $by_id[$link->getPluginId()] = $link;
        $path = $this->linkPath($link);
        if ($path !== NULL && !isset($by_path[$path])) {
          $by_path[$path] = $link;
        }
      }
      if ($element->subtree !== []) {
        $this->collect($element->subtree, $by_id, $by_path, $breadcrumb);
      }
    }
  }

  /**
   * The deepest menu link that contains this path.
   *
   * @param array<string, MenuLinkInterface> $by_path
   *   Links keyed by internal path.
   * @param string $current
   *   Internal path of the page being built.
   */
  private function bestLink(array $by_path, string $current): ?MenuLinkInterface {
    $best = NULL;
    $length = -1;
    foreach ($by_path as $path => $link) {
      $fits = $current === $path || str_starts_with($current, $path . '/');
      if ($fits && strlen($path) > $length) {
        $best = $link;
        $length = strlen($path);
      }
    }
    return $best;
  }

  /**
   * The link and its menu parents, from the top of the menu down.
   *
   * @param \Drupal\Core\Menu\MenuLinkInterface $link
   *   The deepest matching menu link.
   * @param array<string, MenuLinkInterface> $by_id
   *   Links the person may open.
   *
   * @return list<MenuLinkInterface>
   *   Parents first.
   */
  private function chain(MenuLinkInterface $link, array $by_id): array {
    $chain = [];
    $guard = 0;
    while ($guard < 8) {
      $chain[] = $link;
      $parent = $link->getParent();
      if ($parent === '' || !isset($by_id[$parent])) {
        break;
      }
      $link = $by_id[$parent];
      $guard++;
    }
    return array_reverse($chain);
  }

  /**
   * Internal path of a menu link, when it can sit in a public trail.
   */
  private function linkPath(MenuLinkInterface $link): ?string {
    $url = $link->getUrlObject();
    if (!$url->isRouted()) {
      return NULL;
    }
    $route = $url->getRouteName();
    if ($route === '<front>' || $route === 'user.login') {
      return NULL;
    }
    try {
      $path = $url->getInternalPath();
    }
    catch (\Exception) {
      return NULL;
    }
    if ($path === '' || $path === '<front>') {
      return NULL;
    }
    return '/' . $path;
  }

  /**
   * Titles for the path below the matched menu item, including this page.
   *
   * @return list<array{title: string, url: Url, path: string}>
   *   One entry per deeper path.
   */
  private function tailLinks(string $base, string $current, Breadcrumb $breadcrumb): array {
    $links = [];
    $built = '';
    foreach (explode('/', trim($current, '/')) as $part) {
      if ($part === '') {
        continue;
      }
      $built .= '/' . $part;
      if ($base !== '' && ($built === $base || !str_starts_with($built, $base . '/'))) {
        continue;
      }
      $request = $this->requestForPath($built);
      if (!$request instanceof Request) {
        continue;
      }
      $match = RouteMatch::createFromRequest($request);
      $access = $this->accessManager->check($match, $this->currentUser, NULL, TRUE);
      if ($access instanceof CacheableDependencyInterface) {
        $breadcrumb->addCacheableDependency($access);
      }
      if (!$access->isAllowed()) {
        continue;
      }
      $title = $this->title($request, $match);
      if ($title === '') {
        continue;
      }
      $links[] = [
        'title' => $title,
        'url' => Url::fromRouteMatch($match),
        'path' => $built,
      ];
    }
    return $links;
  }

  /**
   * A request matched to a public path, or NULL when the path is not a page.
   */
  private function requestForPath(string $path): ?Request {
    $front = (string) $this->configFactory->get('system.site')->get('page.front');
    if ($path === $front) {
      return NULL;
    }
    try {
      $request = Request::create($path);
    }
    catch (BadRequestException) {
      return NULL;
    }
    $request->headers->set('Accept', 'text/html');
    $processed = $this->pathProcessor->processInbound($path, $request);
    if ($processed === '' || $processed === $front) {
      return NULL;
    }
    $this->currentPath->setPath($processed, $request);
    try {
      $request->attributes->add($this->router->matchRequest($request));
      return $request;
    }
    catch (ParamNotConvertedException | ResourceNotFoundException | MethodNotAllowedException | AccessDeniedHttpException | NotFoundHttpException) {
      return NULL;
    }
  }

  /**
   * The route title, or an empty string when it must stay hidden.
   */
  private function title(Request $request, RouteMatchInterface $match): string {
    $route = $match->getRouteObject();
    if ($route === NULL) {
      return '';
    }
    try {
      $title = $this->titleResolver->getTitle($request, $route);
    }
    catch (\Throwable) {
      return '';
    }
    if ($title instanceof TranslatableMarkup || is_string($title)) {
      return trim((string) $title);
    }
    return '';
  }

  /**
   * Keeps the first link for a path.
   *
   * @param list<array{path: string, link: Link}> $items
   *   Trail collected so far.
   * @param string $text
   *   Visible label.
   * @param \Drupal\Core\Url $url
   *   Destination of the crumb.
   * @param string $path
   *   Internal path used to skip a duplicate.
   */
  private function remember(array &$items, string $text, Url $url, string $path): void {
    $text = trim($text);
    if ($text === '') {
      return;
    }
    foreach ($items as $item) {
      if ($item['path'] === $path) {
        return;
      }
    }
    $items[] = [
      'path' => $path,
      'link' => new Link($text, $url),
    ];
  }

}
