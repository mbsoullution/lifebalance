<?php

declare(strict_types=1);

namespace Drupal\lifebalance_billing\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\lifebalance_billing\Form\OrderFilterForm;
use Drupal\lifebalance_billing\Service\OrderAdminList;
use Drupal\lifebalance_billing\Service\OrderTable;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Finance list of orders. It does not show mail, scores, or card data.
 */
final class OrdersController extends ControllerBase {

  public function __construct(
    protected OrderAdminList $orders,
    protected OrderTable $table,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(OrderAdminList::class),
      $container->get(OrderTable::class),
    );
  }

  /**
   * One page, filtered by status, plan, and creation date.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function list(Request $request): array {
    $status = $request->query->get('status');
    $plan = $request->query->get('plan');
    $from = $request->query->get('from');
    $to = $request->query->get('to');
    $from_time = $this->orders->dayStart(is_string($from) ? $from : '');
    $to_start = $this->orders->dayStart(is_string($to) ? $to : '');
    $to_time = $to_start > 0 ? $to_start + 86399 : 0;
    return [
      '#cache' => [
        'contexts' => ['user', 'user.permissions', 'url.query_args'],
        'max-age' => 0,
      ],
      'filter' => $this->formBuilder()->getForm(OrderFilterForm::class),
      'table' => $this->table->build($this->orders->page(
        is_string($status) ? $status : '',
        is_string($plan) ? $plan : '',
        $from_time,
        $to_time,
      )),
      'pager' => ['#type' => 'pager'],
    ];
  }

}
