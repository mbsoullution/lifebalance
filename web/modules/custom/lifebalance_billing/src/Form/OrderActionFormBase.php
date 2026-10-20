<?php

declare(strict_types=1);

namespace Drupal\lifebalance_billing\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\lifebalance_billing\Entity\PaymentOrder;
use Drupal\lifebalance_billing\Repository\OrderRepository;
use Drupal\lifebalance_billing\Service\OrderActions;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Shared loader for a finance action on one order.
 */
abstract class OrderActionFormBase extends FormBase {

  public function __construct(
    protected OrderRepository $orders,
    protected OrderActions $actions,
  ) {}

  /**
   * The order from the route, or a 404.
   */
  protected function order(): PaymentOrder {
    $uuid = $this->getRouteMatch()->getParameter('uuid');
    $order = is_string($uuid) ? $this->orders->loadByUuid($uuid) : NULL;
    if (!$order instanceof PaymentOrder) {
      throw new NotFoundHttpException();
    }
    return $order;
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
  }

}
