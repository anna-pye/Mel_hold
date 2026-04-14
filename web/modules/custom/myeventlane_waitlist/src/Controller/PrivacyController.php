<?php

declare(strict_types=1);

namespace Drupal\myeventlane_waitlist\Controller;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Controller\ControllerBase;

/**
 * Privacy stub page (content from configuration).
 */
final class PrivacyController extends ControllerBase {

  public function page(): array {
    $config = $this->config('myeventlane_waitlist.settings');
    $raw = (string) $config->get('privacy_body');
    $html = Xss::filterAdmin($raw);

    return [
      '#theme' => 'mel_privacy_page',
      'title' => $this->t('Privacy'),
      'content' => [
        '#markup' => '<div class="mel-prose">' . $html . '</div>',
      ],
      '#cache' => [
        'tags' => $config->getCacheTags(),
      ],
    ];
  }

}
