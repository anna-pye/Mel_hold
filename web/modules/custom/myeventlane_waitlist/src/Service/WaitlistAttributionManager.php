<?php

declare(strict_types=1);

namespace Drupal\myeventlane_waitlist\Service;

use Symfony\Component\HttpFoundation\Request;

/**
 * Collects attribution and touch data from the current request.
 */
final class WaitlistAttributionManager {

  /**
   * @return array<string, mixed>
   *   Flat fields plus first_touch / last_touch JSON blobs.
   */
  public function collectFromRequest(Request $request): array {
    $q = $request->query;
    $utm = [
      'utm_source' => $this->truncate($q->get('utm_source'), 255),
      'utm_medium' => $this->truncate($q->get('utm_medium'), 255),
      'utm_campaign' => $this->truncate($q->get('utm_campaign'), 255),
      'utm_term' => $this->truncate($q->get('utm_term'), 255),
      'utm_content' => $this->truncate($q->get('utm_content'), 255),
    ];
    $referrer = $request->headers->get('referer');
    $touch = [
      'path' => $request->getPathInfo(),
      'query' => $request->query->all(),
      'time' => time(),
    ];
    return [
      'source_url' => $this->truncate($request->getUri(), 2048),
      'landing_path' => $this->truncate($request->getPathInfo(), 512),
      'referrer' => $referrer ? $this->truncate($referrer, 2048) : NULL,
      'utm_source' => $utm['utm_source'],
      'utm_medium' => $utm['utm_medium'],
      'utm_campaign' => $utm['utm_campaign'],
      'utm_term' => $utm['utm_term'],
      'utm_content' => $utm['utm_content'],
      'first_touch_json' => json_encode($touch),
      'last_touch_json' => json_encode($touch),
      'user_agent' => $this->truncate((string) $request->headers->get('User-Agent'), 65535),
    ];
  }

  private function truncate(?string $value, int $max): ?string {
    if ($value === NULL || $value === '') {
      return NULL;
    }
    if (strlen($value) <= $max) {
      return $value;
    }
    return substr($value, 0, $max);
  }

}
