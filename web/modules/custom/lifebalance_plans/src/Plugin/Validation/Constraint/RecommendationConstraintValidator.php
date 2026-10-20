<?php

declare(strict_types=1);

namespace Drupal\lifebalance_plans\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\lifebalance\Entity\Criterion;
use Drupal\lifebalance\Repository\CriterionRepository;
use Drupal\lifebalance\Repository\SectorRepository;
use Drupal\lifebalance_plans\Entity\Recommendation;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates catalog links and the publish permission.
 */
final class RecommendationConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly CriterionRepository $criterionRepository,
    private readonly SectorRepository $sectorRepository,
    private readonly AccountInterface $currentUser,
    TranslationInterface $translation,
  ) {
    $this->setStringTranslation($translation);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(CriterionRepository::class),
      $container->get(SectorRepository::class),
      $container->get('current_user'),
      $container->get(TranslationInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$value instanceof Recommendation) {
      return;
    }
    if (!preg_match(Criterion::KEY_PATTERN, $value->getCatalogKey())) {
      $this->context->buildViolation((string) $this->t('Ключ може містити лише малі латинські літери, цифри, крапку, дефіс і підкреслення.'))
        ->atPath('key')
        ->addViolation();
    }
    $title = $value->getTitle();
    if ($title === '' || mb_strlen($title) > 80) {
      $this->context->buildViolation((string) $this->t('Назва має містити від 1 до 80 символів.'))
        ->atPath('title')
        ->addViolation();
    }
    if ($value->getBody() === '') {
      $this->context->buildViolation((string) $this->t('Додайте текст рекомендації.'))
        ->atPath('body')
        ->addViolation();
    }
    $steps = $value->getSteps();
    if (count($steps) < 1 || count($steps) > 5) {
      $this->context->buildViolation((string) $this->t('Додайте від одного до пʼяти кроків.'))
        ->atPath('steps')
        ->addViolation();
    }
    $answers = $value->getTriggerAnswers();
    if ($answers === [] || array_diff($answers, array_keys(Recommendation::answerLabels())) !== []) {
      $this->context->buildViolation((string) $this->t('Оберіть відповідь «Ні», «Частково» або обидві.'))
        ->atPath('trigger_answers')
        ->addViolation();
    }
    if ($value->isPublishingStatus() && !$this->currentUser->hasPermission('publish lifebalance recommendations')) {
      $this->context->buildViolation((string) $this->t('Опублікувати або архівувати може лише людина з правом публікації.'))
        ->atPath('status')
        ->addViolation();
    }

    $sector = $value->getSectorId();
    if (!isset($this->sectorRepository->loadAll()[$sector])) {
      $this->context->buildViolation((string) $this->t('Оберіть існуючу сферу.'))
        ->atPath('sector')
        ->addViolation();
    }
    $triggers = $value->getTriggerCriteria();
    if ($triggers === []) {
      $this->context->buildViolation((string) $this->t('Вкажіть хоча б один критерій.'))
        ->atPath('trigger_criteria')
        ->addViolation();
      return;
    }
    foreach ($triggers as $delta => $key) {
      $criterion = $this->criterionRepository->loadByKey($key);
      if (!$criterion instanceof Criterion) {
        $this->context->buildViolation((string) $this->t('Критерій @key не існує.', ['@key' => $key]))
          ->atPath('trigger_criteria.' . $delta)
          ->addViolation();
        continue;
      }
      if ($criterion->getSectorId() !== $sector) {
        $this->context->buildViolation((string) $this->t('Критерій @key належить іншій сфері.', ['@key' => $key]))
          ->atPath('trigger_criteria.' . $delta)
          ->addViolation();
      }
    }
  }

}
