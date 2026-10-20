<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Service;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\lifebalance\Entity\Sector;

/**
 * Formats the optional 0 and 10 captions of a sector.
 */
final class ScaleLabels {

  /**
   * Caption for the wheel, or NULL when the owner has not written one.
   */
  public static function caption(Sector $sector, TranslationInterface $translation): ?TranslatableMarkup {
    $low = $sector->getScaleLow();
    $high = $sector->getScaleHigh();
    if ($low === '' && $high === '') {
      return NULL;
    }
    if ($low !== '' && $high !== '') {
      return $translation->translate('0 — @low. 10 — @high.', [
        '@low' => $low,
        '@high' => $high,
      ]);
    }
    if ($low !== '') {
      return $translation->translate('0 — @low.', ['@low' => $low]);
    }
    return $translation->translate('10 — @high.', ['@high' => $high]);
  }

}
