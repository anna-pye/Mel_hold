<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\State\StateInterface;

/**
 * Foundation platform-health signals from real, already-enabled sources.
 *
 * Reads cron (core automated_cron), the existing waitlist mail queue
 * (myeventlane_waitlist_mail — no new queue is created), and dblog's
 * watchdog table (dblog is already enabled). No Commerce/vendor/event
 * data sources exist yet, so none are read here.
 */
final class PlatformHealthService {

  private const WAITLIST_MAIL_QUEUE = 'myeventlane_waitlist_mail';

  public function __construct(
    private readonly Connection $database,
    private readonly QueueFactory $queueFactory,
    private readonly StateInterface $state,
  ) {}

  /**
   * Unix timestamp of the last completed cron run, or NULL if never run.
   */
  public function getCronLastRun(): ?int {
    $value = $this->state->get('system.cron_last');
    return $value !== NULL ? (int) $value : NULL;
  }

  /**
   * Number of items waiting in the waitlist mail queue.
   */
  public function getWaitlistMailQueueSize(): int {
    return $this->queueFactory->get(self::WAITLIST_MAIL_QUEUE)->numberOfItems();
  }

  /**
   * Count of dblog entries at ERROR severity or worse since a given time.
   */
  public function getRecentErrorCount(int $since): int {
    return (int) $this->database->select('watchdog', 'w')
      ->condition('severity', RfcLogLevel::ERROR, '<=')
      ->condition('timestamp', $since, '>=')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

}
