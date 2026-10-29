<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\lifebalance\Entity\Criterion;
use Drupal\lifebalance\Entity\Sector;
use Drupal\lifebalance\Entity\Snapshot;
use Drupal\lifebalance\Repository\CriterionRepository;
use Drupal\lifebalance\Repository\SectorRepository;
use Drupal\lifebalance\Repository\SnapshotRepository;
use Drupal\lifebalance\Service\Draft;
use Drupal\lifebalance\Service\DraftRejected;
use Drupal\lifebalance\Service\DraftStorage;
use Drupal\lifebalance\Service\EntitlementCheckerInterface;
use Drupal\lifebalance\Service\GuideAnswer;
use Drupal\lifebalance\Service\GuideCalculator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * One sector of the optional questionnaire.
 *
 * Answers are stored in the draft. Beside the answer count, the page shows
 * the calculated score to one decimal. The stored guide stays a rounded
 * integer.
 */
final class SectorQuestionnaireForm extends FormBase {

  /**
   * Accepted radio values.
   *
   * @var list<string>
   */
  private const CHOICES = ['yes', 'partial', 'no', 'na'];

  /**
   * Session flag: the invitation line was already shown.
   */
  private const TEASER_SEEN = 'lifebalance.premium_teaser';

  public function __construct(
    protected SectorRepository $sectorRepository,
    protected CriterionRepository $criterionRepository,
    protected SnapshotRepository $snapshotRepository,
    protected DraftStorage $draftStorage,
    protected GuideCalculator $guideCalculator,
    protected EntitlementCheckerInterface $entitlementChecker,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lifebalance_questionnaire';
  }

  /**
   * Page title: the sector label.
   */
  public function title(string $sector): string {
    $entity = $this->sectorRepository->loadActive()[$sector] ?? NULL;
    if ($entity instanceof Sector) {
      return (string) $entity->label();
    }
    return (string) $this->t('Оцінювання');
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $sector
   *   Sector ID from the route.
   *
   * @return array<string, mixed>
   *   The form structure.
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $sector = ''): array {
    if (!isset($this->sectorRepository->loadActive()[$sector])) {
      throw new NotFoundHttpException();
    }
    $sectorId = $sector;
    $account = $this->currentUser();
    $premium = $this->entitlementChecker->hasFeatureAccess($account, 'premium_criteria');
    $draft = $this->draftStorage->load($account);
    $visible = $this->visible($sectorId, $premium);
    $figure = $this->figure($draft, $sectorId, $visible, $premium);
    $sectorEntity = $this->sectorRepository->loadActive()[$sectorId];
    $label = (string) $sectorEntity->label();

    $form['#attributes']['class'][] = 'lifebalance-questionnaire';
    // The draft changes before access expires, so this page is not cached.
    // The premium block below keeps the checker's max-age.
    $meta = $this->entitlementChecker->getCacheability($account);
    $contexts = $meta->getCacheContexts();
    $contexts[] = 'session';
    $form['#cache'] = [
      'contexts' => $contexts,
      'max-age' => 0,
      'tags' => $meta->getCacheTags(),
    ];
    $form['#attached']['library'][] = 'lifebalance/questionnaire';
    $form['#attached']['drupalSettings']['lifebalance'] = $this->clientSettings(
      $sectorId,
      $label,
      $premium,
      $draft,
    );

    $form['heading'] = [
      '#type' => 'container',
      '#weight' => -20,
      '#attributes' => ['class' => ['lifebalance-questionnaire__heading']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h1',
        '#value' => $label,
        '#attributes' => ['class' => ['lifebalance-questionnaire__title']],
      ],
      'figures' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['lifebalance-questionnaire__figures']],
        'progress' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('@answered/@total', [
            '@answered' => $figure['progress'],
            '@total' => $figure['total'],
          ]),
          '#attributes' => [
            'class' => ['lifebalance-progress'],
            'data-lifebalance-progress' => $sectorId,
          ],
        ],
        'score' => ScoreColumn::element($this->t('Оцінка'), $figure['precise']),
      ],
    ];
    if ($this->sectorOutdated($draft, $sectorId)) {
      $form['outdated'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Деякі відповіді скинуто, бо формулювання критерію змінилось.'),
        '#weight' => -17,
      ];
    }

    $form['criteria'] = [
      '#tree' => TRUE,
      '#weight' => 0,
    ];
    $premiumCriteria = [];
    $number = 1;
    foreach ($visible as $criterion) {
      if ($criterion->getTier() === Criterion::TIER_PREMIUM) {
        $premiumCriteria[] = $criterion;
        continue;
      }
      $form['criteria'][(int) $criterion->id()] = $this->statement($criterion, $draft, $number);
      $number++;
    }
    if ($premiumCriteria !== []) {
      $form['premium_block'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Розширене оцінювання'),
        '#weight' => 10,
        '#attributes' => ['class' => ['lifebalance-premium']],
      ];
      foreach ($premiumCriteria as $criterion) {
        $row = $this->statement($criterion, $draft, $number);
        $number++;
        $row['#group'] = 'premium_block';
        $row['#weight'] = 1000 + $criterion->getWeight();
        $form['criteria'][(int) $criterion->id()] = $row;
      }
      $form['premium_block']['#cache'] = [
        'contexts' => $meta->getCacheContexts(),
        'tags' => $meta->getCacheTags(),
        'max-age' => $meta->getCacheMaxAge(),
      ];
    }
    elseif ($this->showTeaser($sectorId)) {
      $count = count($this->criterionRepository->loadPublished($sectorId, Criterion::TIER_PREMIUM));
      $form['teaser'] = [
        '#type' => 'container',
        '#weight' => 10,
        '#attributes' => ['class' => ['lifebalance-premium-teaser']],
        'text' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->formatPlural(
            $count,
            'Є ще 1 поглиблене твердження у розширеному оцінюванні.',
            'Є ще @count поглиблених тверджень у розширеному оцінюванні.',
          ),
        ],
        'link' => [
          '#type' => 'link',
          '#title' => $this->t('Детальніше'),
          '#url' => Url::fromRoute('lifebalance.premium'),
        ],
      ];
    }

    $form['summary'] = $this->summary();
    $form['actions'] = [
      '#type' => 'actions',
      '#weight' => 40,
    ];
    $form['actions']['back'] = [
      '#type' => 'submit',
      '#name' => 'back',
      '#value' => $this->t('Назад до колеса'),
      '#weight' => 0,
    ];
    $form['actions']['save'] = [
      '#type' => 'submit',
      '#name' => 'save',
      '#value' => $this->t('Зберегти відповіді'),
      '#weight' => 1,
    ];
    $next = $this->nextSector($sectorId);
    if ($next !== NULL) {
      $form['actions']['next'] = [
        '#type' => 'submit',
        '#name' => 'next',
        '#value' => $this->t('Наступний сектор'),
        '#weight' => 2,
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
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $sectorId = $this->sectorId();
    if ($sectorId === NULL) {
      $form_state->setErrorByName('criteria', $this->t('Відповіді не збережено.'));
      return;
    }
    $premium = $this->entitlementChecker->hasFeatureAccess($this->currentUser(), 'premium_criteria');
    $raw = $form_state->getValue('criteria');
    $raw = is_array($raw) ? $raw : [];
    foreach ($this->visible($sectorId, $premium) as $criterion) {
      $posted = $this->postedAnswer($raw, (int) $criterion->id());
      if ($posted === NULL) {
        continue;
      }
      if (!in_array($posted, self::CHOICES, TRUE)) {
        $form_state->setErrorByName('criteria', $this->t('Відповіді не збережено.'));
        return;
      }
      if ($criterion->getTier() === Criterion::TIER_PREMIUM && !$premium) {
        $form_state->setErrorByName('criteria', $this->t('Відповіді не збережено.'));
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
    $sectorId = $this->sectorId();
    if ($sectorId === NULL) {
      $this->messenger()->addError($this->t('Відповіді не збережено.'));
      return;
    }
    $name = $this->triggerName($form_state);
    $clearId = $this->clearId($name);
    $draft = $this->persist($form_state, $sectorId, $clearId);
    if (!$draft instanceof Draft) {
      return;
    }
    if ($clearId !== NULL) {
      $this->messenger()->addStatus($this->t('Відповідь очищено.'));
    }
    else {
      $this->messenger()->addStatus($this->t('Відповіді збережено.'));
    }
    $this->redirectAfter($form_state, $name, $sectorId);
  }

  /**
   * Writes this sector's answers. The stored score stays unchanged.
   */
  private function persist(FormStateInterface $form_state, string $sectorId, ?int $clearId): ?Draft {
    $account = $this->currentUser();
    $premium = $this->entitlementChecker->hasFeatureAccess($account, 'premium_criteria');
    $draft = $this->draftStorage->load($account);
    $raw = $form_state->getValue('criteria');
    $raw = is_array($raw) ? $raw : [];
    $answers = $draft->answers;
    foreach ($this->visible($sectorId, $premium) as $criterion) {
      $id = (int) $criterion->id();
      unset($answers[$id]);
      if ($clearId === $id) {
        continue;
      }
      $posted = $this->postedAnswer($raw, $id);
      if ($posted === NULL) {
        continue;
      }
      if (!in_array($posted, self::CHOICES, TRUE)) {
        $this->messenger()->addError($this->t('Відповіді не збережено.'));
        return NULL;
      }
      if ($criterion->getTier() === Criterion::TIER_PREMIUM && !$premium) {
        $this->messenger()->addError($this->t('Відповіді не збережено.'));
        return NULL;
      }
      $answers[$id] = [
        'rev' => (int) $criterion->getRevisionId(),
        'a' => $posted,
      ];
    }
    try {
      return $this->draftStorage->store($account, [
        'scores' => $draft->scores,
        'answers' => $answers,
      ]);
    }
    catch (DraftRejected) {
      $this->messenger()->addError($this->t('Відповіді не збережено.'));
      return NULL;
    }
  }

  /**
   * Fieldset for one statement. Nothing is selected unless a draft has it.
   *
   * @param \Drupal\lifebalance\Entity\Criterion $criterion
   *   The statement.
   * @param \Drupal\lifebalance\Service\Draft $draft
   *   Stored answers.
   * @param int $number
   *   Position of this statement in the sector, starting at 1.
   *
   * @return array<string, mixed>
   *   Form elements.
   */
  private function statement(Criterion $criterion, Draft $draft, int $number): array {
    $id = (int) $criterion->id();
    $stored = $draft->answers[$id]['a'] ?? NULL;
    $options = [
      'yes' => $this->t('Так'),
      'partial' => $this->t('Частково'),
      'no' => $this->t('Ні'),
    ];
    $element = [
      '#type' => 'container',
      '#weight' => $criterion->getWeight(),
      'answer' => [
        '#type' => 'radios',
        '#title' => $number . '. ' . $criterion->getText(),
        '#options' => $options,
        '#attributes' => ['class' => ['lifebalance-choices']],
      ],
    ];
    if (is_string($stored) && isset($options[$stored])) {
      $element['answer']['#default_value'] = $stored;
    }
    $help = $criterion->getHelp();
    if ($help !== '') {
      $element['help'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $help,
        '#attributes' => ['class' => ['lifebalance-help']],
        '#weight' => 20,
      ];
    }
    return $element;
  }

  /**
   * Holds a draft-save error. A successful save stays silent.
   *
   * @return array<string, mixed>
   *   The summary render array.
   */
  private function summary(): array {
    return [
      '#type' => 'container',
      '#weight' => 30,
      '#attributes' => ['class' => ['lifebalance-summary']],
      'status' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => '',
        '#attributes' => [
          'class' => ['lifebalance-status'],
          'data-lifebalance-status' => '1',
          'aria-live' => 'polite',
        ],
      ],
    ];
  }

  /**
   * Settings for live guide text and draft autosave.
   *
   * @param string $sectorId
   *   Sector shown on this page.
   * @param string $label
   *   Sector label.
   * @param bool $premium
   *   Whether this person may answer premium criteria.
   * @param \Drupal\lifebalance\Service\Draft $draft
   *   The current draft.
   *
   * @return array<string, mixed>
   *   drupalSettings.lifebalance.
   */
  private function clientSettings(string $sectorId, string $label, bool $premium, Draft $draft): array {
    $criteria = [];
    foreach ($this->criterionRepository->loadByFilters('', '', Criterion::STATUS_PUBLISHED) as $criterion) {
      if ($criterion->getTier() === Criterion::TIER_PREMIUM && !$premium) {
        continue;
      }
      $criteria[] = [
        'id' => (int) $criterion->id(),
        'sector' => $criterion->getSectorId(),
        'tier' => $criterion->getTier(),
        'inFormula' => $criterion->isInFormula(),
        'rev' => (int) $criterion->getRevisionId(),
      ];
    }
    $answers = [];
    foreach ($draft->answers as $id => $answer) {
      $answers[(string) $id] = $answer['a'];
    }
    return [
      'draftUrl' => Url::fromRoute('lifebalance.draft')->toString(),
      'sector' => $sectorId,
      'label' => $label,
      'entitled' => $premium,
      'criteria' => $criteria,
      'answers' => $answers === [] ? new \stdClass() : $answers,
      'scores' => $draft->scores,
    ];
  }

  /**
   * Active sector from the route, or NULL when the URL is unknown.
   */
  private function sectorId(): ?string {
    $sector = $this->getRouteMatch()->getParameter('sector');
    if (!is_string($sector) || !isset($this->sectorRepository->loadActive()[$sector])) {
      return NULL;
    }
    return $sector;
  }

  /**
   * Published criteria of this sector that this person may answer.
   *
   * @return list<\Drupal\lifebalance\Entity\Criterion>
   *   Free criteria, then premium criteria when entitled.
   */
  private function visible(string $sectorId, bool $premium): array {
    $free = $this->criterionRepository->loadPublished($sectorId, Criterion::TIER_FREE);
    if (!$premium) {
      return $free;
    }
    return array_merge(
      $free,
      $this->criterionRepository->loadPublished($sectorId, Criterion::TIER_PREMIUM),
    );
  }

  /**
   * Guide, progress, and answer counters for one sector.
   *
   * The mode matches a snapshot: any visible premium answer makes the
   * minimum 10 and counts both tiers together.
   *
   * @param \Drupal\lifebalance\Service\Draft $draft
   *   The current draft.
   * @param string $sectorId
   *   Sector being shown.
   * @param list<\Drupal\lifebalance\Entity\Criterion> $visible
   *   Criteria shown on this page.
   * @param bool $premium
   *   Whether this person may answer premium criteria.
   *
   * @return array{progress: int, total: int, precise: int|null}
   *   Progress and the one-decimal score in tenths.
   */
  private function figure(Draft $draft, string $sectorId, array $visible, bool $premium): array {
    $mode = Snapshot::MODE_FREE;
    if ($premium) {
      foreach ($this->criterionRepository->loadByFilters('', '', Criterion::STATUS_PUBLISHED) as $criterion) {
        if ($criterion->getTier() !== Criterion::TIER_PREMIUM) {
          continue;
        }
        if (isset($draft->answers[(int) $criterion->id()])) {
          $mode = Snapshot::MODE_PREMIUM;
          break;
        }
      }
    }
    $counts = [
      'yes' => 0,
      'partial' => 0,
      'no' => 0,
      'na' => 0,
    ];
    $inputs = [];
    $progress = 0;
    foreach ($visible as $criterion) {
      if ($criterion->getSectorId() !== $sectorId) {
        continue;
      }
      $answer = $draft->answers[(int) $criterion->id()] ?? NULL;
      if ($answer === NULL) {
        continue;
      }
      $progress++;
      $choice = $answer['a'];
      if (isset($counts[$choice])) {
        $counts[$choice]++;
      }
      $inputs[] = new GuideAnswer(
        $criterion->getTier(),
        $choice,
        $criterion->isInFormula(),
      );
    }
    $result = $this->guideCalculator->calculate($mode, $inputs);
    return [
      'display' => $result->guide === NULL ? NULL : intdiv($result->guide, 10),
      'progress' => $progress,
      'total' => count($visible),
      'yes' => $counts['yes'],
      'partial' => $counts['partial'],
      'no' => $counts['no'],
      'na' => $counts['na'],
      'precise' => $result->precise,
    ];
  }

  /**
   * Whether a changed criterion in this sector dropped an answer.
   */
  private function sectorOutdated(Draft $draft, string $sectorId): bool {
    if ($draft->outdated === []) {
      return FALSE;
    }
    foreach ($this->criterionRepository->loadByIds($draft->outdated) as $criterion) {
      if ($criterion->getSectorId() === $sectorId) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Invitation line: flag on, no access, a saved snapshot, once per session.
   */
  private function showTeaser(string $sectorId): bool {
    $enabled = (bool) $this->config('lifebalance.settings')->get('premium_enabled');
    if (!$enabled || $this->entitlementChecker->hasFeatureAccess($this->currentUser(), 'premium_criteria')) {
      return FALSE;
    }
    $premium = $this->criterionRepository->loadPublished($sectorId, Criterion::TIER_PREMIUM);
    if ($premium === []) {
      return FALSE;
    }
    $uid = (int) $this->currentUser()->id();
    if ($this->snapshotRepository->loadForOwner($uid) === []) {
      return FALSE;
    }
    $session = $this->getRequest()->getSession();
    if ((int) $session->get(self::TEASER_SEEN) === 1) {
      return FALSE;
    }
    $session->set(self::TEASER_SEEN, 1);
    return TRUE;
  }

  /**
   * Next active sector, or NULL on the last one.
   */
  private function nextSector(string $sectorId): ?string {
    $ids = array_keys($this->sectorRepository->loadActive());
    $index = array_search($sectorId, $ids, TRUE);
    if ($index === FALSE) {
      return NULL;
    }
    return $ids[$index + 1] ?? NULL;
  }

  /**
   * Selected radio, or NULL when the statement was left empty.
   *
   * @param array<mixed> $raw
   *   Submitted criteria tree.
   * @param int $id
   *   Criterion ID.
   */
  private function postedAnswer(array $raw, int $id): ?string {
    $row = $raw[$id] ?? $raw[(string) $id] ?? NULL;
    if (!is_array($row)) {
      return NULL;
    }
    $answer = $row['answer'] ?? NULL;
    if (!is_string($answer) || $answer === '') {
      return NULL;
    }
    return $answer;
  }

  /**
   * HTML name of the button that submitted the form.
   */
  private function triggerName(FormStateInterface $form_state): string {
    $trigger = $form_state->getTriggeringElement();
    if (!is_array($trigger)) {
      return '';
    }
    $name = $trigger['#name'] ?? '';
    return is_string($name) ? $name : '';
  }

  /**
   * Criterion ID from a clear button, or NULL for the other buttons.
   */
  private function clearId(string $name): ?int {
    if (preg_match('/^clear_([1-9][0-9]*)$/', $name) !== 1) {
      return NULL;
    }
    return (int) substr($name, 6);
  }

  /**
   * Sends the person to the wheel, the next sector, or back here.
   */
  private function redirectAfter(FormStateInterface $form_state, string $name, string $sectorId): void {
    if ($name === 'back') {
      $form_state->setRedirect('lifebalance.wheel');
      return;
    }
    if ($name === 'next') {
      $next = $this->nextSector($sectorId);
      if ($next !== NULL) {
        $form_state->setRedirect('lifebalance.questionnaire', ['sector' => $next]);
        return;
      }
    }
    $form_state->setRedirect('lifebalance.questionnaire', ['sector' => $sectorId]);
  }

}
