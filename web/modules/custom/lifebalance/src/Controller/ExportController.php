<?php

declare(strict_types=1);

namespace Drupal\lifebalance\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\lifebalance\Service\PersonalExport;
use Symfony\Component\HttpFoundation\Response;

/**
 * Downloads the current person's data. The URL has no user id.
 */
final class ExportController extends ControllerBase {

  public function __construct(
    private readonly PersonalExport $export,
  ) {}

  /**
   * JSON attachment for the current person.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The download.
   */
  public function json(): Response {
    $encoded = json_encode(
      $this->export->document($this->currentUser()),
      JSON_UNESCAPED_UNICODE | JSON_HEX_TAG,
    );
    if ($encoded === FALSE) {
      throw new \RuntimeException('The export could not be encoded.');
    }
    return $this->download($encoded, 'application/json; charset=UTF-8', 'lifebalance.json');
  }

  /**
   * CSV attachment for the current person.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The download.
   */
  public function csv(): Response {
    return $this->download(
      $this->export->csv($this->currentUser()),
      'text/csv; charset=UTF-8',
      'lifebalance.csv',
    );
  }

  /**
   * Attachment response. The filename has no uid and no email.
   *
   * @param string $body
   *   File contents.
   * @param string $content_type
   *   Response content type.
   * @param string $filename
   *   Download name, without a user id or email.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The download.
   */
  private function download(string $body, string $content_type, string $filename): Response {
    return new Response($body, 200, [
      'Content-Type' => $content_type,
      'Content-Disposition' => 'attachment; filename="' . $filename . '"',
    ]);
  }

}
