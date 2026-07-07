<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration for GA4 and Search Console verification.
 */
final class AnalyticsSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['myeventlane_analytics.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_analytics_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('myeventlane_analytics.settings');

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Analytics enabled'),
      '#description' => $this->t('Master switch. Even when enabled, analytics never loads in the local environment or for administrators.'),
      '#default_value' => $config->get('enabled'),
    ];
    $form['ga4_measurement_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('GA4 measurement ID'),
      '#description' => $this->t('Format: G-XXXXXXXXXX. Normally set per environment via the MEL_GA4_MEASUREMENT_ID environment variable in settings.php rather than here — see docs/analytics/launch-checklist.md.'),
      '#default_value' => $config->get('ga4_measurement_id'),
      '#maxlength' => 32,
    ];
    $form['search_console_verification'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Search Console verification value'),
      '#description' => $this->t('The content value of the google-site-verification meta tag. Normally set per environment via the MEL_SEARCH_CONSOLE_VERIFICATION environment variable in settings.php.'),
      '#default_value' => $config->get('search_console_verification'),
      '#maxlength' => 255,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('myeventlane_analytics.settings')
      ->set('enabled', (bool) $form_state->getValue('enabled'))
      ->set('ga4_measurement_id', (string) $form_state->getValue('ga4_measurement_id'))
      ->set('search_console_verification', (string) $form_state->getValue('search_console_verification'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
