<?php

declare(strict_types=1);

namespace Drupal\myeventlane_waitlist\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Minimal controller so the configured front page path exists (theme renders layout).
 */
final class HoldingPageController extends ControllerBase {

  /**
   * Returns empty main content; page--front template supplies the holding layout.
   */
  public function build(): array {
    return [
      '#markup' => '',
      '#cache' => [
        'contexts' => ['url.path'],
        'max-age' => 3600,
      ],
    ];
  }

}
