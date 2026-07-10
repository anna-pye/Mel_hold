<?php

declare(strict_types=1);

namespace Drupal\myeventlane_waitlist\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\myeventlane_waitlist\Exception\WaitlistCanonicalUrlException;
use Psr\Log\LoggerInterface;

/**
 * Queues and sends waitlist-related mail (queue worker performs actual send).
 */
final class WaitlistEmailManager {
  use StringTranslationTrait;

  public const QUEUE_ID = 'myeventlane_waitlist_mail';

  public function __construct(
    private readonly MailManagerInterface $mailManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
    private readonly QueueFactory $queueFactory,
    private readonly LanguageManagerInterface $languageManager,
    private readonly Connection $database,
    private readonly WaitlistTokenManager $tokenManager,
  ) {}

  public function queueConfirmationEmail(int $subscriberId): void {
    $this->queueFactory->get(self::QUEUE_ID)->createItem([
      'op' => 'confirmation',
      'subscriber_id' => $subscriberId,
    ]);
  }

  /**
   * Sends confirmation email after generating fresh tokens (called from queue worker).
   */
  public function sendConfirmationForSubscriber(int $subscriberId): void {
    $row = $this->database->select('myeventlane_waitlist_subscriber', 's')
      ->fields('s')
      ->condition('id', $subscriberId)
      ->range(0, 1)
      ->execute()
      ->fetchObject();
    if (!$row) {
      $this->logger->warning('Waitlist confirmation: missing subscriber @id', ['@id' => $subscriberId]);
      return;
    }
    if ($row->status !== 'pending') {
      return;
    }

    $rawConfirm = $this->tokenManager->generateRawToken();
    $rawUnsub = $this->tokenManager->generateRawToken();
    $hashConfirm = $this->tokenManager->hashToken($rawConfirm);
    $hashUnsub = $this->tokenManager->hashToken($rawUnsub);
    $ttl = (int) $this->configFactory->get('myeventlane_waitlist.settings')->get('confirm_token_ttl');
    if ($ttl < 300) {
      $ttl = 86400;
    }
    $expires = time() + $ttl;

    // Build both links BEFORE mutating anything, and refuse to send an email
    // whose links resolve to Drupal's placeholder host. That happens when a
    // CLI process (drush cron, queue worker) has no canonical base URL
    // configured — the resulting http://default/... links are useless to the
    // subscriber. Throwing here (before the token update) leaves the row
    // untouched and lets the queue worker suspend the queue so the item is
    // retried once the environment is fixed.
    $confirmUrl = Url::fromRoute('myeventlane_waitlist.confirm', ['token' => $rawConfirm], ['absolute' => TRUE])->toString();
    $unsubUrl = Url::fromRoute('myeventlane_waitlist.unsubscribe', ['token' => $rawUnsub], ['absolute' => TRUE])->toString();
    $this->assertCanonicalUrl($confirmUrl, $subscriberId, 'confirmation');

    $this->database->update('myeventlane_waitlist_subscriber')
      ->fields([
        'confirm_token' => $hashConfirm,
        'confirm_token_expires' => $expires,
        'unsubscribe_token' => $hashUnsub,
        'changed' => time(),
      ])
      ->condition('id', $subscriberId)
      ->execute();

    $langcode = $this->languageManager->getDefaultLanguage()->getId();

    $body = $this->buildMelConfirmationEmailHtml($confirmUrl, $unsubUrl);

    $params = [
      'body' => $body,
      'subject' => (string) $this->t('Confirm your MyEventLane waitlist signup'),
      // Background sends must never surface core's "Unable to send email…"
      // messenger error in a browser session (web cron / automated_cron runs
      // in a real visitor's request context). Empty string = core logs the
      // failure but adds no messenger message; we log richly below instead.
      '_error_message' => '',
    ];

    $config = $this->configFactory->get('myeventlane_waitlist.settings');
    $senderMail = $config->get('sender_mail') ?: NULL;

    $result = $this->mailManager->mail(
      'myeventlane_waitlist',
      'waitlist_confirm',
      $row->email,
      $langcode,
      $params,
      $senderMail,
      TRUE
    );

    if (empty($result['result'])) {
      $this->logger->error('Waitlist mail failed: op=@op subscriber=@id recipient=@to mail_id=@mail_id result=@result — item will be requeued.', [
        '@op' => 'confirmation',
        '@id' => $subscriberId,
        '@to' => $row->email,
        '@mail_id' => 'myeventlane_waitlist_waitlist_confirm',
        '@result' => var_export($result['result'] ?? NULL, TRUE),
      ]);
      // Throwing keeps the queue item (core releases it for retry) instead of
      // silently deleting it — a transient transport failure must not lose the
      // subscriber's confirmation email.
      throw new \RuntimeException(sprintf('Waitlist confirmation mail failed for subscriber %d.', $subscriberId));
    }

    // Only record a send that actually happened.
    $this->database->update('myeventlane_waitlist_subscriber')
      ->fields(['last_sent_at' => time()])
      ->condition('id', $subscriberId)
      ->execute();

    $this->logger->info('Waitlist mail sent: op=@op subscriber=@id recipient=@to', [
      '@op' => 'confirmation',
      '@id' => $subscriberId,
      '@to' => $row->email,
    ]);
  }

  /**
   * Refuses URLs on Drupal's placeholder "default" host (no canonical base).
   *
   * @throws \Drupal\myeventlane_waitlist\Exception\WaitlistCanonicalUrlException
   */
  private function assertCanonicalUrl(string $url, int $subscriberId, string $op): void {
    $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
    if ($host === 'default') {
      $this->logger->error('Waitlist mail blocked: op=@op subscriber=@id — generated URL uses the placeholder host (@url). Set DRUSH_OPTIONS_URI for this CLI environment (see deploy/production.env.example). The queue is suspended; no broken email was sent.', [
        '@op' => $op,
        '@id' => $subscriberId,
        '@url' => $url,
      ]);
      throw new WaitlistCanonicalUrlException(sprintf('Canonical base URL unavailable (host "default") while sending %s for subscriber %d.', $op, $subscriberId));
    }
  }

  /**
   * HTML body styled to match the MyEventLane Hold theme (glass card, brand gradient).
   */
  private function buildMelConfirmationEmailHtml(string $confirmUrl, string $unsubUrl): string {
    $e = static function (string $s): string {
      return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    };

    $thanks = $e((string) $this->t('Thanks for your interest in MyEventLane.'));
    $instruction = $e((string) $this->t('Please confirm your email by clicking the link below:'));
    $ctaText = $e((string) $this->t('Confirm my email'));
    $unsubLead = $e((string) $this->t('To unsubscribe later, use:'));
    $unsubText = $e((string) $this->t('Unsubscribe'));

    $confirmUrlSafe = $e($confirmUrl);
    $unsubUrlSafe = $e($unsubUrl);

    // Mirrors css/style.css :root tokens (MyEventLane Hold).
    $ink = '#1e1e2a';
    $muted = '#5e5e78';
    $bgOuter = '#f6f4ff';
    $card = '#ffffff';
    $border = 'rgba(30, 30, 42, 0.1)';
    $shadow = '0 18px 60px rgba(30, 30, 42, 0.12)';
    $grad = 'linear-gradient(135deg, #8a7dff 0%, #ff8ac7 100%)';

    return ''
      . '<!DOCTYPE html>'
      . '<html lang="en">'
      . '<head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />'
      . '<meta name="viewport" content="width=device-width, initial-scale=1.0" />'
      . '<title>MyEventLane</title></head>'
      . '<body style="margin:0;padding:0;background-color:' . $e($bgOuter) . ';">'
      . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:' . $e($bgOuter) . ';padding:32px 16px;">'
      . '<tr><td align="center">'
      . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:520px;">'
      . '<tr><td style="padding:0 0 20px 0;">'
      . '<table role="presentation" cellspacing="0" cellpadding="0" border="0">'
      . '<tr>'
      . '<td style="vertical-align:middle;padding-right:12px;">'
      . '<div style="width:42px;height:42px;border-radius:14px;background:' . $grad . ';box-shadow:0 10px 22px rgba(138,125,255,0.25);"></div>'
      . '</td>'
      . '<td style="vertical-align:middle;font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Helvetica Neue,Arial,sans-serif;">'
      . '<div style="font-weight:800;font-size:18px;letter-spacing:-0.02em;color:' . $e($ink) . ';line-height:1.1;">MyEventLane</div>'
      . '</td>'
      . '</tr>'
      . '</table>'
      . '</td></tr>'
      . '<tr><td style="background:' . $e($card) . ';border-radius:22px;border:1px solid ' . $e($border) . ';box-shadow:' . $e($shadow) . ';overflow:hidden;">'
      . '<div style="height:4px;background:' . $grad . ';"></div>'
      . '<div style="padding:28px 28px 24px 28px;font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Helvetica Neue,Arial,sans-serif;">'
      . '<p style="margin:0 0 16px 0;font-size:17px;line-height:1.55;color:' . $e($ink) . ';">' . $thanks . '</p>'
      . '<p style="margin:0 0 24px 0;font-size:15px;line-height:1.55;color:' . $e($muted) . ';">' . $instruction . '</p>'
      . '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 28px 0;">'
      . '<tr><td style="border-radius:999px;background:' . $grad . ';">'
      . '<a href="' . $confirmUrlSafe . '" style="display:inline-block;padding:14px 28px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:999px;">' . $ctaText . '</a>'
      . '</td></tr></table>'
      . '<p style="margin:0;font-size:13px;line-height:1.6;color:' . $e($muted) . ';">' . $unsubLead
      . ' <a href="' . $unsubUrlSafe . '" style="color:#8a7dff;font-weight:600;text-decoration:underline;">' . $unsubText . '</a></p>'
      . '</div>'
      . '</td></tr>'
      . '<tr><td style="padding:20px 8px 0 8px;text-align:center;font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Helvetica Neue,Arial,sans-serif;font-size:11px;color:' . $e($muted) . ';">'
      . '© ' . $e((string) date('Y')) . ' MyEventLane'
      . '</td></tr>'
      . '</table>'
      . '</td></tr></table>'
      . '</body></html>';
  }

}
