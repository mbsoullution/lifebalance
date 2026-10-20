<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Form;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\lifebalance\Entity\Criterion;
use Drupal\lifebalance\Entity\Sector;
use Drupal\lifebalance\Entity\Snapshot;
use Drupal\lifebalance\Repository\CriterionRepository;
use Drupal\lifebalance\Repository\SectorRepository;
use Drupal\lifebalance\Service\Draft;
use Drupal\lifebalance\Service\DraftRejected;
use Drupal\lifebalance\Service\DraftStorage;
use Drupal\lifebalance\Service\EntitlementCheckerInterface;
use Drupal\lifebalance\Service\EntitlementManager;
use Drupal\lifebalance\Service\GuideAnswer;
use Drupal\lifebalance\Service\GuideCalculator;
use Drupal\lifebalance\Service\RadarGeometry;
use Drupal\lifebalance\Service\ScaleLabels;

/**
 * Wheel page: guides and saving a snapshot.
 *
 * The guide is only a hint. It is copied into the score when the person
 * presses "Взяти орієнтир".
 */
final class WheelForm extends FormBase {

  private const FLOOD_EVENT = 'lifebalance.snapshot';

  private const FLOOD_WINDOW = 86400;

  private const FLOOD_LIMIT = 50;

  public function __construct(
    protected SectorRepository $sectorRepository,
    protected CriterionRepository $criterionRepository,
    protected DraftStorage $draftStorage,
    protected GuideCalculator $guideCalculator,
    protected EntitlementCheckerInterface $entitlementChecker,
    protected RadarGeometry $radarGeometry,
    protected FloodInterface $flood,
    protected EntitlementManager $entitlements,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lifebalance_wheel';
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
    $draft = $this->draftStorage->load($account);
    $sectors = $this->sectorRepository->loadActive();
    $published = $this->publishedBySector($sectors);
    $guides = $this->guides($draft, $published);

    $form['#attributes']['class'][] = 'lifebalance-wheel';
    // The draft changes before access expires, so this page is not cached.
    // Contexts and tags still come from the entitlement checker.
    $meta = $this->entitlementChecker->getCacheability($account);
    $form['#cache'] = [
      'contexts' => $meta->getCacheContexts(),
      'max-age' => 0,
      'tags' => $meta->getCacheTags(),
    ];
    $form['#attached']['library'][] = 'lifebalance/wheel';
    $form['#attached']['drupalSettings']['lifebalance'] = $this->clientSettings($sectors, $draft, $guides);

    if ($this->entitlements->trialAvailable($account)) {
      $form['trial'] = [
        '#type' => 'link',
        '#title' => $this->t('Спробувати Premium 7 днів'),
        '#url' => Url::fromRoute('lifebalance.trial'),
        '#weight' => -20,
      ];
    }
    $form['legend'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('0 — зовсім не так, як хочу. 10 — саме так, як хочу.'),
      '#attributes' => ['class' => ['lifebalance-scale']],
    ];
    $form['formula'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Орієнтир — частка тверджень, з якими ви погодились (частково = половина), переведена в шкалу 0–10.'),
    ];
    if ($draft->outdated !== []) {
      $form['outdated'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Деякі відповіді скинуто, бо формулювання критерію змінилось.'),
      ];
    }
    $chart = [];
    $form['sectors'] = ['#tree' => TRUE];
    foreach ($sectors as $id => $sector) {
      $score = $draft->scores[$id] ?? NULL;
      $guide = $guides[$id];
      $label = (string) $sector->label();
      $chart[] = [
        'label' => $label,
        'color' => $sector->getColor(),
        'value' => $score,
        'id' => $id,
      ];
      $form['sectors'][$id] = $this->sectorFields($id, $sector, $score, $guide);
    }

    $form['radar'] = [
      '#theme' => 'lifebalance_radar',
      '#geometry' => $this->radarGeometry->build($chart),
      '#weight' => -10,
    ];
    $form['status'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => '',
      '#attributes' => [
        'class' => ['lifebalance-status'],
        'data-lifebalance-status' => '1',
        'aria-live' => 'polite',
      ],
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['snapshot'] = [
      '#type' => 'submit',
      '#name' => 'save_snapshot',
      '#value' => $this->t('Зберегти результат'),
    ];
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
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement();
    $name = is_array($trigger) ? ($trigger['#name'] ?? '') : '';
    if ($name !== 'save_snapshot') {
      return;
    }
    $identifier = 'uid-' . $this->currentUser()->id();
    if (!$this->flood->isAllowed(self::FLOOD_EVENT, self::FLOOD_LIMIT, self::FLOOD_WINDOW, $identifier)) {
      $form_state->setErrorByName('sectors', $this->t('На сьогодні вже збережено максимальну кількість зрізів.'));
      return;
    }
    foreach ($this->submittedScores() as $score) {
      if ($score === NULL) {
        $form_state->setErrorByName(
          'sectors',
          $this->t('Щоб зберегти зріз, потрібна оцінка кожного сектора.'),
        );
        return;
      }
    }
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
    $trigger = $form_state->getTriggeringElement();
    $name = is_array($trigger) ? (string) ($trigger['#name'] ?? '') : '';
    if (str_starts_with($name, 'take_')) {
      $this->takeGuide($form_state, substr($name, 5));
      return;
    }
    if ($name === 'save_snapshot') {
      $this->saveSnapshot($form_state);
      return;
    }
    if (!$this->saveDraft()) {
      return;
    }
    $this->messenger()->addStatus($this->t('Чернетку збережено.'));
    $form_state->setRedirect('lifebalance.wheel');
  }

  /**
   * Copies the guide into one score and stores the draft.
   */
  private function takeGuide(FormStateInterface $form_state, string $sector): void {
    $account = $this->currentUser();
    $draft = $this->draftStorage->load($account);
    $guides = $this->guides($draft, $this->publishedBySector($this->sectorRepository->loadActive()));
    $display = $guides[$sector]['display'] ?? NULL;
    if (!is_int($display)) {
      $this->messenger()->addError($this->t('Для цього сектора ще немає орієнтира.'));
      return;
    }
    $scores = $this->submittedScores();
    if (!array_key_exists($sector, $scores)) {
      $this->messenger()->addError($this->t('Чернетку не збережено.'));
      return;
    }
    $scores[$sector] = $display;
    if (!$this->persistScores($scores) instanceof Draft) {
      return;
    }
    $this->messenger()->addStatus($this->t('Орієнтир перенесено в оцінку.'));
    $form_state->setRedirect('lifebalance.wheel');
  }

  /**
   * Stores the current scores without creating a snapshot.
   */
  private function saveDraft(): bool {
    return $this->persistScores($this->submittedScores()) instanceof Draft;
  }

  /**
   * Creates a snapshot from the submitted scores and the stored answers.
   */
  private function saveSnapshot(FormStateInterface $form_state): void {
    $account = $this->currentUser();
    $identifier = 'uid-' . $account->id();
    if (!$this->flood->isAllowed(self::FLOOD_EVENT, self::FLOOD_LIMIT, self::FLOOD_WINDOW, $identifier)) {
      $this->messenger()->addError($this->t('На сьогодні вже збережено максимальну кількість зрізів.'));
      return;
    }
    $scores = $this->submittedScores();
    $draft = $this->persistScores($scores);
    if (!$draft instanceof Draft) {
      return;
    }
    $score_items = [];
    foreach ($scores as $sector => $value) {
      $score_items[] = [
        'sector_id' => $sector,
        'value' => $value,
      ];
    }
    $answer_items = [];
    foreach ($draft->answers as $id => $answer) {
      $answer_items[] = [
        'criterion_id' => $id,
        'criterion_revision_id' => $answer['rev'],
        'answer' => $answer['a'],
      ];
    }
    $snapshot = Snapshot::create([
      'uid' => $account->id(),
      'note' => $draft->note,
      'scores' => $score_items,
      'answers' => $answer_items,
    ]);
    try {
      $snapshot->save();
    }
    catch (EntityStorageException) {
      $this->messenger()->addError($this->t('Зріз не збережено.'));
      return;
    }
    $this->flood->register(self::FLOOD_EVENT, self::FLOOD_WINDOW, $identifier);
    $this->messenger()->addStatus($this->t('Зріз збережено.'));
    $form_state->setRedirect('lifebalance.wheel');
  }

  /**
   * Writes scores and the note, or records a generic failure.
   *
   * @param array<string, int|null> $scores
   *   Scores to store. The existing note stays as it is.
   */
  private function persistScores(array $scores): ?Draft {
    $account = $this->currentUser();
    try {
      return $this->draftStorage->saveScores(
        $account,
        $scores,
        $this->draftStorage->load($account)->note,
      );
    }
    catch (DraftRejected) {
      $this->messenger()->addError($this->t('Чернетку не збережено.'));
      return NULL;
    }
  }

  /**
   * Settings for the live radar. Scores stay out of the markup string.
   *
   * @param array<string, \Drupal\lifebalance\Entity\Sector> $sectors
   *   Active sectors.
   * @param \Drupal\lifebalance\Service\Draft $draft
   *   The current draft.
   * @param array<string, array{display: int|null, answered: int, total: int, progress: int}> $guides
   *   Per-sector guide figures.
   *
   * @return array<string, mixed>
   *   drupalSettings.lifebalance.
   */
  private function clientSettings(array $sectors, Draft $draft, array $guides): array {
    $listed = [];
    foreach ($sectors as $id => $sector) {
      $score = $draft->scores[$id] ?? NULL;
      $listed[] = [
        'id' => $id,
        'label' => (string) $sector->label(),
        'color' => $sector->getColor(),
        'guide' => $guides[$id]['display'],
        'score' => is_int($score) ? $score : NULL,
      ];
    }
    $answers = [];
    foreach ($draft->answers as $id => $answer) {
      $answers[(string) $id] = [
        'rev' => $answer['rev'],
        'a' => $answer['a'],
      ];
    }
    return [
      'draftUrl' => Url::fromRoute('lifebalance.draft')->toString(),
      'sectors' => $listed,
      'answers' => $answers === [] ? new \stdClass() : $answers,
    ];
  }

  /**
   * Fields for one sector.
   *
   * @param string $id
   *   Sector ID.
   * @param \Drupal\lifebalance\Entity\Sector $sector
   *   The sector.
   * @param int|null $score
   *   Current score, or NULL when it is not assessed.
   * @param array{display: int|null, answered: int, total: int, progress: int} $guide
   *   Guide and progress for this sector.
   *
   * @return array<string, mixed>
   *   Form elements.
   */
  private function sectorFields(string $id, Sector $sector, ?int $score, array $guide): array {
    $label = (string) $sector->label();
    $color = strtoupper($sector->getColor());
    if (preg_match('/^#[0-9A-F]{6}$/', $color) !== 1) {
      $color = '#1F7A4D';
    }
    $fields = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'lifebalance-sector-' . $id,
        'class' => ['lifebalance-sector'],
        'style' => '--lifebalance-sector: ' . $color . ';',
      ],
      'head' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['lifebalance-sector__row']],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h2',
          '#value' => $label,
          '#attributes' => ['class' => ['lifebalance-sector__title']],
        ],
        'progress' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('@answered/@total', [
            '@answered' => $guide['progress'],
            '@total' => $guide['total'],
          ]),
          '#attributes' => ['class' => ['lifebalance-progress']],
        ],
        'help' => [
          '#type' => 'link',
          '#title' => $score === NULL ? $this->t('Оцінити') : $this->t('Переоцінити'),
          '#url' => Url::fromUri('internal:/wheel/' . $id),
          '#attributes' => ['class' => ['lifebalance-assess']],
        ],
      ],
    ];
    $scale = ScaleLabels::caption($sector, $this->getStringTranslation());
    if ($scale !== NULL) {
      $fields['scale'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $scale,
        '#attributes' => ['class' => ['lifebalance-scale']],
      ];
    }
    if (is_int($guide['display'])) {
      $fields['guide'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Орієнтир: @score', ['@score' => $guide['display']]),
      ];
      if ($score !== NULL && $score !== $guide['display']) {
        $fields['difference'] = [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Ваша оцінка відрізняється від орієнтиру — це нормально, орієнтир лише підказка.'),
        ];
      }
      $fields['take'] = [
        '#type' => 'submit',
        '#name' => 'take_' . $id,
        '#value' => $this->t('Взяти орієнтир (@score)', [
          '@score' => $guide['display'],
        ]),
        '#limit_validation_errors' => [
          ['sectors'],
        ],
      ];
    }
    return $fields;
  }

  /**
   * Scores already stored in the draft.
   *
   * @return array<string, int|null>
   *   Scores keyed by sector ID.
   */
  private function submittedScores(): array {
    $draft = $this->draftStorage->load($this->currentUser());
    $scores = [];
    foreach (array_keys($this->sectorRepository->loadActive()) as $id) {
      $value = $draft->scores[$id] ?? NULL;
      $scores[$id] = is_int($value) ? $value : NULL;
    }
    return $scores;
  }

  /**
   * Published criteria grouped by sector, hiding premium without entitlement.
   *
   * @param array<string, \Drupal\lifebalance\Entity\Sector> $sectors
   *   Active sectors.
   *
   * @return array<string, list<\Drupal\lifebalance\Entity\Criterion>>
   *   Criteria keyed by sector ID.
   */
  private function publishedBySector(array $sectors): array {
    $grouped = [];
    foreach (array_keys($sectors) as $id) {
      $grouped[$id] = [];
    }
    $premium = $this->entitlementChecker->hasFeatureAccess($this->currentUser(), 'premium_criteria');
    foreach ($this->criterionRepository->loadByFilters('', '', Criterion::STATUS_PUBLISHED) as $criterion) {
      $sector = $criterion->getSectorId();
      if (!isset($grouped[$sector])) {
        continue;
      }
      if ($criterion->getTier() === Criterion::TIER_PREMIUM && !$premium) {
        continue;
      }
      $grouped[$sector][] = $criterion;
    }
    return $grouped;
  }

  /**
   * Guide and progress for every sector.
   *
   * @param \Drupal\lifebalance\Service\Draft $draft
   *   The current draft.
   * @param array<string, list<\Drupal\lifebalance\Entity\Criterion>> $published
   *   Visible criteria.
   *
   * @return array<string, array{display: int|null, answered: int, total: int, progress: int}>
   *   Per-sector figures. display is the 0–10 guide.
   */
  private function guides(Draft $draft, array $published): array {
    $premium = FALSE;
    $by_id = [];
    foreach ($published as $criteria) {
      foreach ($criteria as $criterion) {
        $by_id[(int) $criterion->id()] = $criterion;
        if ($criterion->getTier() === Criterion::TIER_PREMIUM) {
          $premium = TRUE;
        }
      }
    }
    $mode = Snapshot::MODE_FREE;
    foreach ($draft->answers as $id => $answer) {
      $criterion = $by_id[$id] ?? NULL;
      if ($criterion instanceof Criterion && $criterion->getTier() === Criterion::TIER_PREMIUM) {
        $mode = Snapshot::MODE_PREMIUM;
      }
    }
    if (!$premium) {
      $mode = Snapshot::MODE_FREE;
    }
    $guides = [];
    foreach ($published as $sector => $criteria) {
      $inputs = [];
      $progress = 0;
      foreach ($criteria as $criterion) {
        $answer = $draft->answers[(int) $criterion->id()] ?? NULL;
        if ($answer === NULL) {
          continue;
        }
        $progress++;
        $inputs[] = new GuideAnswer(
          $criterion->getTier(),
          $answer['a'],
          $criterion->isInFormula(),
        );
      }
      $result = $this->guideCalculator->calculate($mode, $inputs);
      $guides[$sector] = [
        'display' => $result->guide === NULL ? NULL : intdiv($result->guide, 10),
        'answered' => $result->answered,
        'total' => count($criteria),
        'progress' => $progress,
      ];
    }
    return $guides;
  }

}
