<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Postmark\Models\PostmarkException;
use Postmark\PostmarkClient;
use Psr\Log\LoggerInterface;

/**
 * Read-only reporting over Postmark, reusing the existing postmark module.
 *
 * drupal/postmark (already installed) only sends mail via PostmarkHandler;
 * it has no reporting API. This service adds read-only access to the same
 * Postmark server — via the wildbit/postmark-php client that drupal/postmark
 * already depends on — using the same postmark.settings API key that
 * PostmarkHandler sends with. No second client, no duplicated HTTP code,
 * no dashboard: methods return raw Postmark API models for a future caller.
 */
final class EmailAnalyticsService {

  private ?PostmarkClient $client = NULL;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {}

  private function client(): ?PostmarkClient {
    if ($this->client instanceof PostmarkClient) {
      return $this->client;
    }
    $apiKey = (string) ($this->configFactory->get('postmark.settings')->get('postmark_api_key') ?: '');
    if ($apiKey === '') {
      return NULL;
    }
    $this->client = new PostmarkClient($apiKey);
    return $this->client;
  }

  /**
   * Aggregate delivery statistics for the configured server.
   */
  public function getDeliveryStatistics(): ?array {
    return $this->call(fn (PostmarkClient $client) => $client->getDeliveryStatistics());
  }

  /**
   * Recent bounces.
   */
  public function getBounces(int $count = 100, int $offset = 0): ?array {
    return $this->call(fn (PostmarkClient $client) => $client->getBounces($count, $offset));
  }

  /**
   * Current suppressions (bounces, spam complaints, manual, etc.).
   */
  public function getSuppressions(): ?array {
    return $this->call(fn (PostmarkClient $client) => $client->getSuppressions());
  }

  /**
   * Open-tracking statistics.
   */
  public function getOpenStatistics(int $count = 100, int $offset = 0): ?array {
    return $this->call(fn (PostmarkClient $client) => $client->getOpenStatistics($count, $offset));
  }

  /**
   * Click-tracking statistics.
   */
  public function getClickStatistics(int $count = 100, int $offset = 0): ?array {
    return $this->call(fn (PostmarkClient $client) => $client->getClickStatistics($count, $offset));
  }

  /**
   * Spam complaint statistics.
   */
  public function getComplaintStatistics(): ?array {
    return $this->call(fn (PostmarkClient $client) => $client->getOutboundSpamComplaintStatistics());
  }

  /**
   * @param callable(PostmarkClient): mixed $operation
   */
  private function call(callable $operation): ?array {
    $client = $this->client();
    if ($client === NULL) {
      $this->logger->warning('Postmark reporting requested but no API key is configured.');
      return NULL;
    }
    try {
      $result = $operation($client);
      return is_array($result) ? $result : (array) $result;
    }
    catch (PostmarkException $e) {
      $this->logger->error('Postmark reporting call failed: @code @message', [
        '@code' => $e->postmarkApiErrorCode,
        '@message' => $e->message,
      ]);
      return NULL;
    }
  }

}
