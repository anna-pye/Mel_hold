<?php

declare(strict_types=1);

namespace Drupal\myeventlane_waitlist_mailfail\Plugin\Mail;

use Drupal\Core\Mail\MailInterface;

/**
 * A mail plugin whose send always fails — for testing failure handling.
 *
 * @Mail(
 *   id = "waitlist_failing_mail",
 *   label = @Translation("Failing mail (test only)"),
 *   description = @Translation("Always returns FALSE from mail().")
 * )
 */
final class FailingMail implements MailInterface {

  /**
   * {@inheritdoc}
   */
  public function format(array $message): array {
    if (is_array($message['body'])) {
      $message['body'] = implode("\n\n", $message['body']);
    }
    return $message;
  }

  /**
   * {@inheritdoc}
   */
  public function mail(array $message): bool {
    return FALSE;
  }

}
