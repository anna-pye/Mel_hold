<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\TempStore\TempStoreException;
use Psr\Log\LoggerInterface;

/**
 * Gates and dispatches first-party GA4 tracking for the HOLD site.
 *
 * This is the one extension point for a future Commerce platform: any later
 * module (e.g. myeventlane_commerce_analytics) tracks new events by calling
 * track() — no changes to this class are required to add new event names.
 */
final class AnalyticsService {

  private const TEMPSTORE_COLLECTION = 'myeventlane_analytics';

  private const TEMPSTORE_KEY = 'pending_events';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly PrivateTempStoreFactory $tempStoreFactory,
    private readonly Settings $settings,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Whether analytics should load at all for the given visitor.
   *
   * Never loads locally, never loads for administrators. Loads for
   * anonymous visitors and all other authenticated roles.
   */
  public function shouldTrack(AccountInterface $account): bool {
    $config = $this->configFactory->get('myeventlane_analytics.settings');
    if (!$config->get('enabled')) {
      return FALSE;
    }
    $environment = (string) ($this->settings->get('mel.environment') ?: 'local');
    if ($environment === 'local') {
      return FALSE;
    }
    if ($account->hasRole('administrator')) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * The environment-resolved GA4 measurement ID, or '' if unset.
   */
  public function getMeasurementId(): string {
    return (string) ($this->configFactory->get('myeventlane_analytics.settings')->get('ga4_measurement_id') ?: '');
  }

  /**
   * The Search Console verification meta tag content, or '' if unset.
   */
  public function getSearchConsoleVerification(): string {
    return (string) ($this->configFactory->get('myeventlane_analytics.settings')->get('search_console_verification') ?: '');
  }

  /**
   * Queues a GA4 event to fire on the visitor's next page load.
   *
   * Use this from form submit handlers and other code paths that redirect
   * before a response can carry the event (e.g. waitlist_join, sign_up).
   * For an event on the current response (404, search), pass it directly
   * to buildDrupalSettings() instead.
   *
   * @param array<string, mixed> $params
   */
  public function track(string $eventName, array $params = []): void {
    try {
      $store = $this->tempStoreFactory->get(self::TEMPSTORE_COLLECTION);
      $pending = $store->get(self::TEMPSTORE_KEY) ?? [];
      $pending[] = ['name' => $eventName, 'params' => $params];
      $store->set(self::TEMPSTORE_KEY, $pending);
    }
    catch (TempStoreException $e) {
      $this->logger->warning('Could not queue analytics event @event: @message', [
        '@event' => $eventName,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Reads and clears any events queued by track() on a prior request.
   *
   * Callers should only invoke this when the current request already has
   * an active session (e.g. `\Drupal::request()->getSession()->isStarted()`)
   * — touching PrivateTempStore for an anonymous visitor with no session
   * would start one, defeating Internal Page Cache for that visit. Route
   * derived events (404, search) do not need a session and should be
   * merged in separately by the caller.
   *
   * @return array<int, array{name: string, params: array<string, mixed>}>
   */
  public function consumePendingEvents(): array {
    try {
      $store = $this->tempStoreFactory->get(self::TEMPSTORE_COLLECTION);
      $pending = $store->get(self::TEMPSTORE_KEY);
      if (empty($pending)) {
        return [];
      }
      $store->delete(self::TEMPSTORE_KEY);
      return $pending;
    }
    catch (TempStoreException $e) {
      $this->logger->warning('Could not read queued analytics events: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Builds the drupalSettings payload for the current response.
   *
   * @param array<int, array{name: string, params: array<string, mixed>}> $events
   *   Events to fire on this page load — the caller merges together any
   *   route-derived events (404, search) with, when a session is already
   *   active, whatever consumePendingEvents() returned.
   *
   * @return array<string, mixed>
   *   Empty array when analytics should not load at all.
   */
  public function buildDrupalSettings(AccountInterface $account, array $events = []): array {
    if (!$this->shouldTrack($account)) {
      return [];
    }
    $measurementId = $this->getMeasurementId();
    if ($measurementId === '') {
      return [];
    }
    return [
      'enabled' => TRUE,
      'measurementId' => $measurementId,
      'events' => $events,
    ];
  }

}
