<?php

declare(strict_types=1);

namespace Drupal\myeventlane_waitlist\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Core waitlist signup and lifecycle logic.
 */
final class WaitlistManager {
  use StringTranslationTrait;

  public function __construct(
    private readonly Connection $database,
    private readonly WaitlistTokenManager $tokenManager,
    private readonly WaitlistAttributionManager $attributionManager,
    private readonly WaitlistRateLimitManager $rateLimitManager,
    private readonly WaitlistEmailManager $emailManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
    private readonly Settings $settings,
  ) {}

  /**
   * Neutral user-facing status (no email enumeration).
   */
  public const MESSAGE_NEUTRAL = 'waitlist_neutral';

  /**
   * Processes a validated signup request (consent, honeypot done in form).
   *
   * @return string
   *   Always self::MESSAGE_NEUTRAL for public responses.
   */
  public function processSignupRequest(
    string $email,
    bool $consent,
    ?string $interestType,
    Request $request,
    string $formVariant,
    array $profile = [],
  ): string {
    $normalized = $this->normalizeEmail($email);
    $ip = $request->getClientIp() ?? '';
    $ipHash = $this->hashIp($ip);
    $ipFloodKey = WaitlistRateLimitManager::floodIpKey($ipHash);

    if (!$this->rateLimitManager->allow($ipFloodKey, $normalized)) {
      $this->logger->notice('Waitlist rate limit triggered for @ip / @mail', [
        '@ip' => $ipHash,
        '@mail' => $normalized,
      ]);
      return self::MESSAGE_NEUTRAL;
    }

    $config = $this->configFactory->get('myeventlane_waitlist.settings');
    $consentText = (string) $config->get('consent_text');
    $consentVersion = (string) $config->get('consent_version');

    $attr = $this->attributionManager->collectFromRequest($request);
    $now = time();
    // Full set, including NULLs, for the INSERT.
    $profileInsert = $this->normalizeProfile($profile);
    // Only values actually supplied, for UPDATEs. An attendee re-signup must not
    // blank the organiser details captured on an earlier submission.
    $profileUpdate = array_filter($profileInsert, static fn($v) => $v !== NULL);

    $existing = $this->database->select('myeventlane_waitlist_subscriber', 's')
      ->fields('s')
      ->condition('email_normalized', $normalized)
      ->range(0, 1)
      ->execute()
      ->fetchObject();

    if ($existing && $existing->status === 'confirmed') {
      $this->logEvent((int) $existing->id, 'signup_duplicate_confirmed', []);
      $this->logger->info('Waitlist signup ignored (already confirmed) for normalized email hash prefix @h', [
        '@h' => substr(hash('sha256', $normalized), 0, 12),
      ]);
      $this->rateLimitManager->register($ipFloodKey, $normalized);
      return self::MESSAGE_NEUTRAL;
    }

    if ($existing && $existing->status === 'pending') {
      $this->database->update('myeventlane_waitlist_subscriber')
        ->fields($profileUpdate + [
          'email' => $email,
          'interest_type' => $interestType,
          'consent_given' => $consent ? 1 : 0,
          'consent_text' => $consentText,
          'consent_version' => $consentVersion,
          'source_url' => $attr['source_url'],
          'landing_path' => $attr['landing_path'],
          'referrer' => $attr['referrer'],
          'utm_source' => $attr['utm_source'],
          'utm_medium' => $attr['utm_medium'],
          'utm_campaign' => $attr['utm_campaign'],
          'utm_term' => $attr['utm_term'],
          'utm_content' => $attr['utm_content'],
          'last_touch_json' => $attr['last_touch_json'],
          'user_agent' => $attr['user_agent'],
          'ip_hash' => $ipHash,
          'form_variant' => $formVariant,
          'locale' => substr($request->getLocale(), 0, 16),
          'changed' => $now,
        ])
        ->condition('id', $existing->id)
        ->execute();
      $this->logEvent((int) $existing->id, 'signup_refresh_pending', []);
      $this->emailManager->queueConfirmationEmail((int) $existing->id);
      $this->rateLimitManager->register($ipFloodKey, $normalized);
      return self::MESSAGE_NEUTRAL;
    }

    if ($existing && $existing->status === 'unsubscribed') {
      $this->database->update('myeventlane_waitlist_subscriber')
        ->fields($profileUpdate + [
          'email' => $email,
          'status' => 'pending',
          'interest_type' => $interestType,
          'consent_given' => $consent ? 1 : 0,
          'consent_text' => $consentText,
          'consent_version' => $consentVersion,
          'confirm_token' => NULL,
          'confirm_token_expires' => NULL,
          'unsubscribe_token' => NULL,
          'source_url' => $attr['source_url'],
          'landing_path' => $attr['landing_path'],
          'referrer' => $attr['referrer'],
          'utm_source' => $attr['utm_source'],
          'utm_medium' => $attr['utm_medium'],
          'utm_campaign' => $attr['utm_campaign'],
          'utm_term' => $attr['utm_term'],
          'utm_content' => $attr['utm_content'],
          'last_touch_json' => $attr['last_touch_json'],
          'user_agent' => $attr['user_agent'],
          'ip_hash' => $ipHash,
          'form_variant' => $formVariant,
          'locale' => substr($request->getLocale(), 0, 16),
          'confirmed_at' => NULL,
          'unsubscribed_at' => NULL,
          'changed' => $now,
        ])
        ->condition('id', $existing->id)
        ->execute();
      $this->logEvent((int) $existing->id, 'resubscribe_after_unsub', []);
      $this->emailManager->queueConfirmationEmail((int) $existing->id);
      $this->rateLimitManager->register($ipFloodKey, $normalized);
      return self::MESSAGE_NEUTRAL;
    }

    try {
      $id = (int) $this->database->insert('myeventlane_waitlist_subscriber')
        ->fields($profileInsert + [
          'email' => $email,
          'email_normalized' => $normalized,
          'first_name' => NULL,
          'interest_type' => $interestType,
          'status' => 'pending',
          'consent_given' => $consent ? 1 : 0,
          'consent_text' => $consentText,
          'consent_version' => $consentVersion,
          'double_opt_in_required' => 1,
          'confirm_token' => NULL,
          'confirm_token_expires' => NULL,
          'unsubscribe_token' => NULL,
          'source_url' => $attr['source_url'],
          'landing_path' => $attr['landing_path'],
          'referrer' => $attr['referrer'],
          'utm_source' => $attr['utm_source'],
          'utm_medium' => $attr['utm_medium'],
          'utm_campaign' => $attr['utm_campaign'],
          'utm_term' => $attr['utm_term'],
          'utm_content' => $attr['utm_content'],
          'first_touch_json' => $attr['first_touch_json'],
          'last_touch_json' => $attr['last_touch_json'],
          'user_agent' => $attr['user_agent'],
          'ip_hash' => $ipHash,
          'form_variant' => $formVariant,
          'locale' => substr($request->getLocale(), 0, 16),
          'notes' => NULL,
          'confirmed_at' => NULL,
          'unsubscribed_at' => NULL,
          'last_sent_at' => NULL,
          'created' => $now,
          'changed' => $now,
        ])
        ->execute();
    }
    catch (IntegrityConstraintViolationException) {
      $race = $this->database->select('myeventlane_waitlist_subscriber', 's')
        ->fields('s')
        ->condition('email_normalized', $normalized)
        ->range(0, 1)
        ->execute()
        ->fetchObject();
      if ($race && $race->status === 'pending') {
        $this->database->update('myeventlane_waitlist_subscriber')
          ->fields($profileUpdate + [
            'email' => $email,
            'interest_type' => $interestType,
            'consent_given' => $consent ? 1 : 0,
            'consent_text' => $consentText,
            'consent_version' => $consentVersion,
            'source_url' => $attr['source_url'],
            'landing_path' => $attr['landing_path'],
            'referrer' => $attr['referrer'],
            'utm_source' => $attr['utm_source'],
            'utm_medium' => $attr['utm_medium'],
            'utm_campaign' => $attr['utm_campaign'],
            'utm_term' => $attr['utm_term'],
            'utm_content' => $attr['utm_content'],
            'last_touch_json' => $attr['last_touch_json'],
            'user_agent' => $attr['user_agent'],
            'ip_hash' => $ipHash,
            'form_variant' => $formVariant,
            'locale' => substr($request->getLocale(), 0, 16),
            'changed' => $now,
          ])
          ->condition('id', $race->id)
          ->execute();
        $this->logEvent((int) $race->id, 'signup_refresh_pending', ['reason' => 'unique_race']);
        $this->emailManager->queueConfirmationEmail((int) $race->id);
        $this->rateLimitManager->register($ipFloodKey, $normalized);
        return self::MESSAGE_NEUTRAL;
      }
      throw new \RuntimeException('Unexpected unique constraint on waitlist signup.');
    }

    if ($id > 0) {
      $this->logEvent($id, 'signup_created', []);
      $this->emailManager->queueConfirmationEmail($id);
    }

    $this->rateLimitManager->register($ipFloodKey, $normalized);
    return self::MESSAGE_NEUTRAL;
  }

  public function confirmByRawToken(string $rawToken): string {
    $hash = $this->tokenManager->hashToken($rawToken);
    $row = $this->database->select('myeventlane_waitlist_subscriber', 's')
      ->fields('s')
      ->condition('confirm_token', $hash)
      ->range(0, 1)
      ->execute()
      ->fetchObject();
    if (!$row) {
      $this->logger->notice('Waitlist confirm failed: unknown token hash.');
      return 'invalid';
    }
    if ($row->status !== 'pending') {
      return 'invalid';
    }
    if ($row->confirm_token_expires && (int) $row->confirm_token_expires < time()) {
      $this->logEvent((int) $row->id, 'confirm_expired', []);
      return 'invalid';
    }

    $this->database->update('myeventlane_waitlist_subscriber')
      ->fields([
        'status' => 'confirmed',
        'confirmed_at' => time(),
        'confirm_token' => NULL,
        'confirm_token_expires' => NULL,
        'changed' => time(),
      ])
      ->condition('id', $row->id)
      ->execute();

    $this->logEvent((int) $row->id, 'confirmed', []);
    $this->logger->info('Waitlist subscriber @id confirmed.', ['@id' => $row->id]);
    return 'ok';
  }

  public function unsubscribeByRawToken(string $rawToken): string {
    $hash = $this->tokenManager->hashToken($rawToken);
    $row = $this->database->select('myeventlane_waitlist_subscriber', 's')
      ->fields('s')
      ->condition('unsubscribe_token', $hash)
      ->range(0, 1)
      ->execute()
      ->fetchObject();
    if (!$row) {
      $this->logger->notice('Waitlist unsubscribe failed: unknown token hash.');
      return 'invalid';
    }

    $this->database->update('myeventlane_waitlist_subscriber')
      ->fields([
        'status' => 'unsubscribed',
        'unsubscribed_at' => time(),
        'unsubscribe_token' => NULL,
        'changed' => time(),
      ])
      ->condition('id', $row->id)
      ->execute();

    $this->logEvent((int) $row->id, 'unsubscribed', []);
    return 'ok';
  }

  public function normalizeEmail(string $email): string {
    return strtolower(trim($email));
  }

  /**
   * Reduces a submitted profile array to the five storable columns.
   *
   * Values are trimmed and truncated to their column length. Anything not in
   * the allow-list is discarded. Empty values become NULL.
   *
   * @return array<string, string|null>
   */
  private function normalizeProfile(array $profile): array {
    $lengths = [
      'organisation' => 255,
      'event_type' => 64,
      'next_event_date' => 32,
      'audience_size' => 64,
      'current_platform' => 128,
    ];

    $clean = [];
    foreach ($lengths as $key => $max) {
      $value = trim((string) ($profile[$key] ?? ''));
      $clean[$key] = $value === '' ? NULL : mb_substr($value, 0, $max);
    }

    return $clean;
  }

  private function hashIp(string $ip): string {
    $pepper = $this->settings->get('myeventlane_waitlist.ip_pepper');
    if (!is_string($pepper) || $pepper === '') {
      $pepper = (string) $this->settings->get('hash_salt');
    }
    return hash('sha256', $ip . '|' . $pepper);
  }

  /**
   * @param array<string, mixed> $payload
   */
  private function logEvent(int $subscriberId, string $eventType, array $payload): void {
    $this->database->insert('myeventlane_waitlist_event')
      ->fields([
        'subscriber_id' => $subscriberId,
        'event_type' => $eventType,
        'payload_json' => $payload ? json_encode($payload) : NULL,
        'created' => time(),
      ])
      ->execute();
  }

}
