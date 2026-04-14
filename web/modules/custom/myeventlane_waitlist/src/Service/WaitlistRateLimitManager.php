<?php

declare(strict_types=1);

namespace Drupal\myeventlane_waitlist\Service;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Flood\FloodInterface;

/**
 * Flood-based rate limits for waitlist signups.
 */
final class WaitlistRateLimitManager {

  private const WINDOW = 3600;

  private const IP_LIMIT = 30;

  private const EMAIL_LIMIT = 5;

  public function __construct(
    private readonly FloodInterface $flood,
  ) {}

  public function allow(string $ipIdentifier, string $emailNormalized): bool {
    if (!$this->flood->isAllowed('myeventlane_waitlist.ip', self::IP_LIMIT, self::WINDOW, $ipIdentifier)) {
      return FALSE;
    }
    if (!$this->flood->isAllowed('myeventlane_waitlist.email', self::EMAIL_LIMIT, self::WINDOW, $emailNormalized)) {
      return FALSE;
    }
    return TRUE;
  }

  public function register(string $ipIdentifier, string $emailNormalized): void {
    $this->flood->register('myeventlane_waitlist.ip', self::WINDOW, $ipIdentifier);
    $this->flood->register('myeventlane_waitlist.email', self::WINDOW, $emailNormalized);
  }

  /**
   * Stable identifier for flood (hashed IP only).
   */
  public static function floodIpKey(string $ipHash): string {
    return Crypt::hashBase64($ipHash);
  }

}
