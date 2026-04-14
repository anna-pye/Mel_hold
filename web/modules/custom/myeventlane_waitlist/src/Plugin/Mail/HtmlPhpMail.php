<?php

declare(strict_types=1);

namespace Drupal\myeventlane_waitlist\Plugin\Mail;

use Drupal\Core\Mail\Attribute\Mail;
use Drupal\Core\Mail\MailFormatHelper;
use Drupal\Core\Mail\Plugin\Mail\PhpMail;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * PHP mail backend that preserves HTML when Content-Type is text/html.
 *
 * Core's php_mail always converts the body with MailFormatHelper::htmlToText(),
 * which strips tags and turns links into plain-text footnotes. Waitlist
 * confirmation mail is authored as HTML; this plugin leaves it intact.
 */
#[Mail(
  id: 'myeventlane_html_php_mail',
  label: new TranslatableMarkup('PHP mailer (HTML-capable)'),
  description: new TranslatableMarkup('Same as the default PHP mailer, but does not convert bodies marked as text/html to plain text.'),
)]
final class HtmlPhpMail extends PhpMail {

  /**
   * {@inheritdoc}
   */
  public function format(array $message): array {
    $message['body'] = implode("\n\n", $message['body']);
    $contentType = $this->getHeader($message['headers'] ?? [], 'Content-Type');
    if ($contentType !== '' && stripos($contentType, 'text/html') !== FALSE) {
      return $message;
    }
    $message['body'] = MailFormatHelper::htmlToText($message['body']);
    return $message;
  }

  /**
   * @param array<string, string> $headers
   */
  private function getHeader(array $headers, string $name): string {
    foreach ($headers as $key => $value) {
      if (strcasecmp((string) $key, $name) === 0 && is_string($value)) {
        return $value;
      }
    }
    return '';
  }

}
