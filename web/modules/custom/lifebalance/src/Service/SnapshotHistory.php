<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Service;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lifebalance\Entity\Criterion;
use Drupal\lifebalance\Entity\Snapshot;
use Drupal\lifebalance\Plugin\Field\FieldType\AnswerItem;
use Drupal\lifebalance\Repository\SectorRepository;

/**
 * Read-only presentation of a stored snapshot.
 *
 * Answer text comes from the revision the person answered, including premium
 * statements, whether or not that person still has premium.
 */
final class SnapshotHistory {

  use StringTranslationTrait;

  /**
   * Characters kept from a note on the history list.
   */
  private const EXCERPT = 80;

  public function __construct(
    private readonly SectorRepository $sectorRepository,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Creation time in the person's timezone.
   */
  public function formatCreated(Snapshot $snapshot, AccountInterface $account): string {
    $timezone = $account->getTimeZone();
    return $this->dateFormatter->format(
      $snapshot->getCreatedTime(),
      'custom',
      'd.m.Y H:i',
      $timezone !== '' ? $timezone : NULL,
    );
  }

  /**
   * Mean of the stored scores, with one decimal place.
   */
  public function average(Snapshot $snapshot): string {
    $scores = $snapshot->getScores();
    if ($scores === []) {
      return '';
    }
    $sum = 0;
    foreach ($scores as $item) {
      $sum += $item->getScore();
    }
    return number_format($sum / count($scores), 1, '.', '');
  }

  /**
   * Mode badge label.
   */
  public function modeLabel(Snapshot $snapshot): TranslatableMarkup {
    $labels = Snapshot::modeLabels();
    return $labels[$snapshot->getAssessmentMode()] ?? $labels[Snapshot::MODE_NONE];
  }

  /**
   * Catalog badge. The number is the version stored on the snapshot.
   */
  public function catalogLabel(Snapshot $snapshot): string {
    return (string) $this->t('Каталог @version', [
      '@version' => $snapshot->getCatalogVersion(),
    ]);
  }

  /**
   * Short note for the list. The full note stays on the snapshot page.
   */
  public function excerpt(string $note): string {
    if (mb_strlen($note) <= self::EXCERPT) {
      return $note;
    }
    return mb_substr($note, 0, self::EXCERPT - 1) . '…';
  }

  /**
   * Score rows in sector order.
   *
   * @return list<array{sector: string, score: string, guide: string, color: string}>
   *   Display rows. The guide is the stored 0–10 hint, or an em dash.
   */
  public function scoreRows(Snapshot $snapshot): array {
    $sectors = $this->sectorRepository->loadAll();
    $by_sector = [];
    foreach ($snapshot->getScores() as $item) {
      $id = $item->getSectorId();
      $guide = $item->getGuide();
      $sector = $sectors[$id] ?? NULL;
      $by_sector[$id] = [
        'sector' => $sector === NULL ? $id : (string) $sector->label(),
        'score' => (string) $item->getScore(),
        'guide' => $guide === NULL ? '—' : (string) intdiv($guide, 10),
        'color' => $sector === NULL ? '#6b7280' : $sector->getColor(),
      ];
    }
    $rows = [];
    foreach ($sectors as $id => $sector) {
      if (isset($by_sector[$id])) {
        $rows[] = $by_sector[$id];
        unset($by_sector[$id]);
      }
    }
    foreach ($by_sector as $row) {
      $rows[] = $row;
    }
    return $rows;
  }

  /**
   * Answers with the wording of the revision that was answered.
   *
   * @return list<array{sector: string, text: string, help: string, answer: string, premium: bool}>
   *   Display rows. Text is empty when that revision cannot be loaded.
   */
  public function answerRows(Snapshot $snapshot): array {
    $sectors = $this->sectorRepository->loadAll();
    $storage = $this->entityTypeManager->getStorage('lifebalance_criterion');
    if (!$storage instanceof RevisionableStorageInterface) {
      return [];
    }
    $sortable = [];
    foreach ($snapshot->getAnswers() as $item) {
      $revision = $storage->loadRevision($item->getCriterionRevisionId());
      $text = '';
      $help = '';
      $premium = FALSE;
      $weight = 0;
      $sector_id = '';
      if ($revision instanceof Criterion
        && (int) $revision->id() === $item->getCriterionId()
        && (int) $revision->getRevisionId() === $item->getCriterionRevisionId()
      ) {
        $text = $revision->getText();
        $help = $revision->getHelp();
        $premium = $revision->getTier() === Criterion::TIER_PREMIUM;
        $weight = $revision->getWeight();
        $sector_id = $revision->getSectorId();
      }
      $sector = $sectors[$sector_id] ?? NULL;
      $sortable[] = [
        'sector' => $sector === NULL ? $sector_id : (string) $sector->label(),
        'text' => $text,
        'help' => $help,
        'answer' => $this->answerLabel($item),
        'premium' => $premium,
        'weight' => $weight,
        'sector_weight' => $sector === NULL ? PHP_INT_MAX : $sector->getWeight(),
      ];
    }
    usort($sortable, static function (array $a, array $b): int {
      $sector = $a['sector_weight'] <=> $b['sector_weight'];
      return $sector !== 0 ? $sector : $a['weight'] <=> $b['weight'];
    });
    $rows = [];
    foreach ($sortable as $row) {
      $rows[] = [
        'sector' => $row['sector'],
        'text' => $row['text'],
        'help' => $row['help'],
        'answer' => $row['answer'],
        'premium' => $row['premium'],
      ];
    }
    return $rows;
  }

  /**
   * Ukrainian label for a stored answer.
   */
  private function answerLabel(AnswerItem $item): string {
    return match ($item->getAnswer()) {
      AnswerItem::YES => (string) $this->t('Так'),
      AnswerItem::PARTIAL => (string) $this->t('Частково'),
      AnswerItem::NO => (string) $this->t('Ні'),
      AnswerItem::NA => (string) $this->t('Не стосується'),
      default => '',
    };
  }

}
