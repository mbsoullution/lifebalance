<?php

declare(strict_types=1);

namespace Drupal\lifebalance_plans\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\lifebalance\Repository\SectorRepository;
use Drupal\lifebalance\Service\EntitlementCheckerInterface;
use Drupal\lifebalance_plans\Entity\Plan;
use Drupal\lifebalance_plans\Service\PlanManager;
use Drupal\lifebalance_plans\Service\PlanResult;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The current 30-day plan, its archive, and the early finish action.
 */
final class CurrentPlanForm extends FormBase {

  public function __construct(
    protected PlanManager $plans,
    protected SectorRepository $sectors,
    protected EntitlementCheckerInterface $entitlementChecker,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(PlanManager::class),
      $container->get(SectorRepository::class),
      $container->get(EntitlementCheckerInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lifebalance_plans_current_plan';
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
    $account = $this->currentUser();
    $uid = (int) $account->id();
    $meta = $this->entitlementChecker->getCacheability($account);
    $form['#cache'] = [
      'contexts' => $meta->getCacheContexts(),
      'tags' => array_merge($meta->getCacheTags(), [
        'lifebalance_plan_list:' . $uid,
        'lifebalance_goal_list:' . $uid,
      ]),
      'max-age' => 0,
    ];
    if ($this->plans->invitationOpen($account)) {
      $form['invite'] = [
        '#type' => 'link',
        '#title' => $this->t('Пройти нову самооцінку'),
        '#url' => Url::fromRoute('lifebalance.wheel'),
      ];
    }
    $plan = $this->plans->active($account);
    if (!$plan instanceof Plan) {
      $form['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Плану на 30 днів ще немає.'),
      ];
    }
    else {
      $form['summary'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('План на 30 днів до @date включно. Залишилось днів: @days.', [
          '@date' => $this->formatEnd($plan),
          '@days' => $this->plans->daysLeft($plan),
        ]),
      ];
      $form['sectors'] = [
        '#theme' => 'item_list',
        '#items' => $this->sectorLabels($plan->getFocusSectors()),
      ];
      $titles = [];
      foreach ($this->plans->goals($plan) as $goal) {
        $titles[] = [
          '#type' => 'link',
          '#title' => $goal->getTitle(),
          '#url' => Url::fromRoute('lifebalance_plans.goal', [
            'uuid' => $goal->uuid(),
          ]),
        ];
      }
      $form['goals'] = [
        '#theme' => 'item_list',
        '#items' => $titles,
      ];
      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['abandon'] = [
        '#type' => 'submit',
        '#value' => $this->t('Завершити достроково'),
      ];
    }
    $archive = $this->plans->archive($account);
    if ($archive !== []) {
      $form['archive_title'] = [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Архів планів'),
      ];
      $items = [];
      $labels = Plan::statusLabels();
      foreach ($archive as $item) {
        $status = $labels[$item->getStatus()] ?? $item->getStatus();
        $items[] = $this->t('@status, до @date', [
          '@status' => $status,
          '@date' => $this->formatEnd($item),
        ]);
      }
      $form['archive'] = [
        '#theme' => 'item_list',
        '#items' => $items,
      ];
    }
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
    $result = $this->plans->abandon($this->currentUser());
    if ($result->code === PlanResult::SAVED) {
      $this->messenger()->addStatus($this->t('План завершено достроково. Цілі залишаються.'));
      return;
    }
    $this->messenger()->addError($this->t('План не завершено.'));
  }

  /**
   * End date in Europe/Kyiv.
   */
  private function formatEnd(Plan $plan): string {
    $zone = new \DateTimeZone('Europe/Kyiv');
    $date = (new \DateTimeImmutable('@' . $plan->getEnds()))->setTimezone($zone);
    return $date->format('d.m.Y');
  }

  /**
   * Sector labels in catalog order.
   *
   * @param list<string> $ids
   *   Sector IDs.
   *
   * @return list<string>
   *   Labels.
   */
  private function sectorLabels(array $ids): array {
    $labels = [];
    $active = $this->sectors->loadActive();
    foreach ($ids as $id) {
      $sector = $active[$id] ?? NULL;
      $labels[] = $sector === NULL ? $id : (string) $sector->label();
    }
    return $labels;
  }

}
