<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\lifebalance\Form\CreateWheelForm;
use Drupal\lifebalance\Form\SectorQuestionnaireForm;
use Drupal\lifebalance\Form\WheelForm;
use Drupal\lifebalance\Repository\SectorRepository;
use Drupal\lifebalance\Service\WheelCreation;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Shows the create-wheel step until the person has created one.
 */
final class WheelPageController extends ControllerBase {

  public function __construct(
    private readonly WheelCreation $wheelCreation,
    private readonly SectorRepository $sectorRepository,
    private readonly FormBuilderInterface $forms,
  ) {}

  /**
   * Wheel form, or the one-time creation form.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function wheel(): array {
    if ($this->wheelCreation->createdAt($this->currentUser()) === NULL) {
      return $this->forms->getForm(CreateWheelForm::class);
    }
    return $this->forms->getForm(WheelForm::class);
  }

  /**
   * Sector questionnaire. An unknown sector is a 404.
   *
   * @return array<string, mixed>|RedirectResponse
   *   The questionnaire, or a redirect until the wheel exists.
   */
  public function questionnaire(string $sector): array|RedirectResponse {
    if (!isset($this->sectorRepository->loadActive()[$sector])) {
      throw new NotFoundHttpException();
    }
    if ($this->wheelCreation->createdAt($this->currentUser()) === NULL) {
      return $this->redirect('lifebalance.wheel');
    }
    return $this->forms->getForm(SectorQuestionnaireForm::class, $sector);
  }

}
