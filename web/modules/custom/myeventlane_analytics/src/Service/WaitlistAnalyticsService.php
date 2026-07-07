<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Service;

use Drupal\Core\Database\Connection;

/**
 * Read-only business metrics over the existing waitlist schema.
 *
 * This is the first-party, precise counterpart to the coarse client-side
 * GA4 "waitlist_join" event: it reads myeventlane_waitlist_subscriber and
 * myeventlane_waitlist_event directly (owned by myeventlane_waitlist) and
 * never writes to them — all inserts remain the exclusive responsibility
 * of WaitlistManager.
 */
final class WaitlistAnalyticsService {

  public function __construct(
    private readonly Connection $database,
  ) {}

  /**
   * Subscriber counts grouped by lifecycle status.
   *
   * @return array<string, int>
   *   Status (pending/confirmed/unsubscribed) => count.
   */
  public function getSubscriberCountsByStatus(): array {
    $query = $this->database->select('myeventlane_waitlist_subscriber', 's');
    $query->addField('s', 'status');
    $query->addExpression('COUNT(*)', 'total');
    $query->groupBy('s.status');
    /** @var array<string, string> $rows */
    $rows = $query->execute()->fetchAllKeyed(0, 1);
    return array_map('intval', $rows);
  }

  /**
   * Total subscriber rows regardless of status.
   */
  public function getTotalSubscribers(): int {
    return (int) $this->database->select('myeventlane_waitlist_subscriber', 's')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Unix timestamp of the most recent signup, or NULL if there are none.
   *
   * Reads the same myeventlane_waitlist_subscriber.created column the admin
   * dashboard already orders by — no new source, no write access.
   */
  public function getLastSignupTimestamp(): ?int {
    $value = $this->database->select('myeventlane_waitlist_subscriber', 's')
      ->fields('s', ['created'])
      ->orderBy('created', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();
    return $value !== FALSE && $value !== NULL ? (int) $value : NULL;
  }

  /**
   * Lifecycle event counts (signup_created, confirmed, unsubscribed, etc.).
   *
   * @return array<string, int>
   *   Event type => count.
   */
  public function getEventTypeCounts(?int $since = NULL): array {
    $query = $this->database->select('myeventlane_waitlist_event', 'e');
    $query->addField('e', 'event_type');
    $query->addExpression('COUNT(*)', 'total');
    if ($since !== NULL) {
      $query->condition('e.created', $since, '>=');
    }
    $query->groupBy('e.event_type');
    /** @var array<string, string> $rows */
    $rows = $query->execute()->fetchAllKeyed(0, 1);
    return array_map('intval', $rows);
  }

  /**
   * Subscriber counts by UTM source.
   *
   * Reads the utm_source column WaitlistAttributionManager already
   * populated at signup time (via WaitlistManager) — no UTM/referrer
   * parsing happens here or anywhere in this module. WaitlistAttributionManager
   * remains the sole, canonical attribution implementation.
   *
   * @return array<string, int>
   *   UTM source (or '' for direct/no source) => count.
   */
  public function getSubscriberCountsByUtmSource(): array {
    return $this->countByColumn('utm_source');
  }

  /**
   * Subscriber counts by UTM campaign.
   *
   * Same provenance as getSubscriberCountsByUtmSource() — reads data
   * already captured by WaitlistAttributionManager, does not re-derive it.
   *
   * @return array<string, int>
   *   UTM campaign (or '' for none) => count.
   */
  public function getSubscriberCountsByUtmCampaign(): array {
    return $this->countByColumn('utm_campaign');
  }

  /**
   * @return array<string, int>
   */
  private function countByColumn(string $column): array {
    $query = $this->database->select('myeventlane_waitlist_subscriber', 's');
    $query->addExpression("COALESCE(s.$column, '')", 'grouping_value');
    $query->addExpression('COUNT(*)', 'total');
    $query->groupBy('grouping_value');
    /** @var array<string, string> $rows */
    $rows = $query->execute()->fetchAllKeyed(0, 1);
    return array_map('intval', $rows);
  }

}
