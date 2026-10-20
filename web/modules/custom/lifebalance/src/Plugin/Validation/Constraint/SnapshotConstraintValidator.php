<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\lifebalance\Entity\Snapshot;
use Drupal\lifebalance\Service\CatalogVersion;
use Drupal\lifebalance\Service\GuideResult;
use Drupal\lifebalance\Service\SnapshotPreparer;
use Drupal\lifebalance\Service\SnapshotProblem;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates scores, answers, and the server-side guide.
 */
final class SnapshotConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly SnapshotPreparer $preparer,
    private readonly CatalogVersion $catalogVersion,
    TranslationInterface $translation,
  ) {
    $this->setStringTranslation($translation);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(SnapshotPreparer::class),
      $container->get(CatalogVersion::class),
      $container->get(TranslationInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$value instanceof Snapshot) {
      return;
    }
    if (!$value->isNew()) {
      if (mb_strlen($value->getNote()) > 1000) {
        $this->context->buildViolation((string) $this->message(SnapshotProblem::NOTE_TOO_LONG))
          ->atPath('note')
          ->addViolation();
      }
      return;
    }
    $review = $this->preparer->review($value);
    foreach ($review->problems as $code) {
      $this->context->buildViolation((string) $this->message($code))
        ->atPath($this->path($code))
        ->addViolation();
    }
    if ($value->getAssessmentMode() !== $review->mode || $value->getFormulaVersion() !== $review->formulaVersion) {
      $this->context->buildViolation((string) $this->t('Орієнтир перераховується на сервері.'))
        ->atPath('scores')
        ->addViolation();
    }
    if ($value->getCatalogVersion() !== $this->catalogVersion->current()) {
      $this->context->buildViolation((string) $this->t('Версія каталогу записується на сервері.'))
        ->atPath('catalog_version')
        ->addViolation();
    }
    foreach ($value->getScores() as $delta => $item) {
      $expected = $review->guides[$item->getSectorId()] ?? NULL;
      if (!$expected instanceof GuideResult) {
        continue;
      }
      if ($item->getGuide() !== $expected->guide || $item->getAnswered() !== $expected->answered) {
        $this->context->buildViolation((string) $this->t('Орієнтир перераховується на сервері.'))
          ->atPath('scores.' . $delta)
          ->addViolation();
      }
    }
  }

  /**
   * Ukrainian message for a problem code.
   */
  private function message(string $code): TranslatableMarkup {
    return match ($code) {
      SnapshotProblem::MISSING_SECTOR => $this->t('Кожен активний сектор має оцінку.'),
      SnapshotProblem::DUPLICATE_SECTOR => $this->t('Оцінка сектору повторюється.'),
      SnapshotProblem::UNKNOWN_SECTOR => $this->t('Оцінка вказує на невідомий або вимкнений сектор.'),
      SnapshotProblem::DUPLICATE_ANSWER => $this->t('Відповідь на критерій повторюється.'),
      SnapshotProblem::STALE_OR_UNPUBLISHED => $this->t('Відповідь приймається лише для опублікованого критерію з поточною ревізією.'),
      SnapshotProblem::INACTIVE_SECTOR_ANSWER => $this->t('Відповідь стосується сектору, якого немає серед активних.'),
      SnapshotProblem::PREMIUM_DENIED => $this->t('Відповідь на розширений критерій потребує доступу до розширеного оцінювання.'),
      SnapshotProblem::NOTE_TOO_LONG => $this->t('Нотатка може містити не більше 1000 символів.'),
      default => throw new \LogicException('Unknown snapshot problem.'),
    };
  }

  /**
   * Form path for a problem code.
   */
  private function path(string $code): string {
    return match ($code) {
      SnapshotProblem::DUPLICATE_ANSWER,
      SnapshotProblem::STALE_OR_UNPUBLISHED,
      SnapshotProblem::INACTIVE_SECTOR_ANSWER,
      SnapshotProblem::PREMIUM_DENIED => 'answers',
      SnapshotProblem::NOTE_TOO_LONG => 'note',
      default => 'scores',
    };
  }

}
