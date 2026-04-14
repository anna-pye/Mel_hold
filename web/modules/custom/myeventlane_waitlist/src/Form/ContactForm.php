<?php

declare(strict_types=1);

namespace Drupal\myeventlane_waitlist\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Public contact form for the holding site (messages go to configured contact email).
 */
final class ContactForm extends FormBase {

  private const FLOOD_NAME = 'myeventlane_waitlist.contact_form';

  private const FLOOD_LIMIT = 10;

  private const FLOOD_WINDOW = 3600;

  public function __construct(
    private readonly MailManagerInterface $mailManager,
    private readonly LanguageManagerInterface $languageManager,
    private readonly FloodInterface $flood,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('plugin.manager.mail'),
      $container->get('language_manager'),
      $container->get('flood'),
      $container->get('logger.channel.myeventlane_waitlist'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_contact_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attributes']['class'][] = 'mel-contact-form';
    $form['#attributes']['novalidate'] = 'novalidate';

    $form['intro'] = [
      '#type' => 'markup',
      '#markup' => '<p class="mel-contact-form__lead">' . $this->t('Have a question or idea? We would love to hear from you.') . '</p>'
        . '<p class="mel-contact-form__intro">' . $this->t('Send us a note — we read every message.') . '</p>',
      '#weight' => -20,
    ];

    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Your name'),
      '#required' => TRUE,
      '#maxlength' => 128,
      '#attributes' => [
        'class' => ['mel-contact-form__input'],
        'autocomplete' => 'name',
      ],
    ];

    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email address'),
      '#required' => TRUE,
      '#maxlength' => 254,
      '#attributes' => [
        'class' => ['mel-contact-form__input'],
        'autocomplete' => 'email',
      ],
    ];

    $form['message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Message'),
      '#required' => TRUE,
      '#rows' => 6,
      '#maxlength' => 5000,
      '#attributes' => [
        'class' => ['mel-contact-form__textarea'],
      ],
    ];

    $form['website'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Leave blank'),
      '#attributes' => [
        'class' => ['mel-visually-hidden', 'mel-honeypot'],
        'tabindex' => '-1',
        'autocomplete' => 'off',
      ],
      '#wrapper_attributes' => ['class' => ['mel-honeypot-wrap']],
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send message'),
      '#attributes' => ['class' => ['mel-btn', 'mel-btn--primary']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $honeypot = trim((string) $form_state->getValue('website'));
    if ($honeypot !== '') {
      $this->logger->notice('Contact form honeypot triggered.');
      $form_state->set('honeypot_tripped', TRUE);
      return;
    }

    $request = $this->getRequest();
    $ip = $request?->getClientIp() ?? 'unknown';
    $flood_key = 'contact:' . $ip;
    if (!$this->flood->isAllowed(self::FLOOD_NAME, self::FLOOD_LIMIT, self::FLOOD_WINDOW, $flood_key)) {
      $form_state->setErrorByName('', $this->t('Too many messages from this connection. Please try again later.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($form_state->get('honeypot_tripped')) {
      $this->messenger()->addStatus($this->t('Thanks — your message has been sent.'));
      $form_state->setRedirect('myeventlane_waitlist.contact');
      return;
    }

    $config = $this->config('myeventlane_waitlist.settings');
    $to = (string) ($config->get('contact_mail') ?: '');
    if ($to === '') {
      $this->logger->error('Contact form: contact_mail is not configured.');
      $this->messenger()->addError($this->t('Contact is temporarily unavailable. Please try again later.'));
      return;
    }

    $name = trim((string) $form_state->getValue('name'));
    $email = trim((string) $form_state->getValue('email'));
    $message = trim((string) $form_state->getValue('message'));

    $langcode = $this->languageManager->getDefaultLanguage()->getId();

    $safe_name = Html::escape($name);
    $safe_email = Html::escape($email);
    $safe_message = nl2br(Html::escape($message));

    $body = ''
      . '<p style="margin:0 0 12px 0;font-family:ui-sans-serif,system-ui,sans-serif;font-size:15px;line-height:1.5;color:#1e1e2a;">'
      . '<strong>' . $this->t('Name') . ':</strong> ' . $safe_name . '</p>'
      . '<p style="margin:0 0 12px 0;font-family:ui-sans-serif,system-ui,sans-serif;font-size:15px;line-height:1.5;color:#1e1e2a;">'
      . '<strong>' . $this->t('Email') . ':</strong> ' . $safe_email . '</p>'
      . '<p style="margin:0;font-family:ui-sans-serif,system-ui,sans-serif;font-size:15px;line-height:1.55;color:#1e1e2a;">'
      . '<strong>' . $this->t('Message') . ':</strong></p>'
      . '<div style="margin-top:8px;font-family:ui-sans-serif,system-ui,sans-serif;font-size:15px;line-height:1.55;color:#1e1e2a;">' . $safe_message . '</div>';

    $params = [
      'subject' => (string) $this->t('MyEventLane contact: @name', ['@name' => $name]),
      'body' => $body,
    ];

    $result = $this->mailManager->mail(
      'myeventlane_waitlist',
      'contact_inquiry',
      $to,
      $langcode,
      $params,
      $email,
      TRUE
    );

    $request = $this->getRequest();
    $ip = $request?->getClientIp() ?? 'unknown';
    $flood_key = 'contact:' . $ip;

    if (empty($result['result'])) {
      $this->logger->error('Contact form mail send reported failure for @email.', ['@email' => $email]);
      $this->messenger()->addError($this->t('We could not send your message. Please try again in a few minutes.'));
      return;
    }

    $this->flood->register(self::FLOOD_NAME, self::FLOOD_WINDOW, $flood_key);
    $this->messenger()->addStatus($this->t('Thanks — we have received your message.'));
    $form_state->setRedirectUrl(Url::fromRoute('myeventlane_waitlist.contact'));
  }

}
