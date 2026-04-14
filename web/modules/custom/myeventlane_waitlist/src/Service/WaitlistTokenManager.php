<?php

declare(strict_types=1);

namespace Drupal\myeventlane_waitlist\Service;

use Drupal\Core\Site\Settings;

/**
 * Generates raw tokens and stores only derived hashes (compare using hash_equals).
 */
final class WaitlistTokenManager {

  public function __construct(
    private readonly Settings $settings,
  ) {}

  public function generateRawToken(): string {
    return bin2hex(random_bytes(32));
  }

  /**
   * Deterministic hash for storing in DB (never store raw tokens in the database).
   */
  public function hashToken(string $raw): string {
    $secret = $this->settings->get('myeventlane_waitlist.token_secret');
    if (!is_string($secret) || $secret === '') {
      $secret = (string) $this->settings->get('hash_salt');
    }
    return hash('sha256', $raw . '|' . $secret);
  }

  public function tokensMatch(string $raw, ?string $storedHash): bool {
    if ($storedHash === NULL || $storedHash === '') {
      return FALSE;
    }
    return hash_equals($storedHash, $this->hashToken($raw));
  }

}
