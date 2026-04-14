<?php

declare(strict_types=1);

namespace Drupal\myeventlane_waitlist\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration for waitlist email, consent, and token TTL.
 */
final class WaitlistSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['myeventlane_waitlist.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_waitlist_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('myeventlane_waitlist.settings');

    $form['sender_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Sender name'),
      '#default_value' => $config->get('sender_name'),
      '#required' => TRUE,
    ];
    $form['sender_mail'] = [
      '#type' => 'email',
      '#title' => $this->t('Sender email'),
      '#default_value' => $config->get('sender_mail'),
      '#required' => TRUE,
    ];
    $form['contact_mail'] = [
      '#type' => 'email',
      '#title' => $this->t('Public contact email'),
      '#description' => $this->t('Shown on the holding page for direct inquiries.'),
      '#default_value' => $config->get('contact_mail'),
      '#required' => TRUE,
    ];
    $form['consent_text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Consent checkbox label'),
      '#default_value' => $config->get('consent_text'),
      '#required' => TRUE,
    ];
    $form['consent_version'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Consent version'),
      '#default_value' => $config->get('consent_version'),
      '#maxlength' => 32,
    ];
    $form['privacy_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Privacy page path'),
      '#description' => $this->t('Relative path, e.g. /privacy'),
      '#default_value' => $config->get('privacy_url'),
    ];
    $form['privacy_body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Privacy page body (HTML allowed, filtered on display)'),
      '#default_value' => $config->get('privacy_body'),
    ];
    $form['confirm_token_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Confirmation link lifetime (seconds)'),
      '#default_value' => $config->get('confirm_token_ttl'),
      '#min' => 300,
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('myeventlane_waitlist.settings')
      ->set('sender_name', $form_state->getValue('sender_name'))
      ->set('sender_mail', $form_state->getValue('sender_mail'))
      ->set('contact_mail', $form_state->getValue('contact_mail'))
      ->set('consent_text', $form_state->getValue('consent_text'))
      ->set('consent_version', $form_state->getValue('consent_version'))
      ->set('privacy_url', $form_state->getValue('privacy_url'))
      ->set('privacy_body', $form_state->getValue('privacy_body'))
      ->set('confirm_token_ttl', (int) $form_state->getValue('confirm_token_ttl'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
