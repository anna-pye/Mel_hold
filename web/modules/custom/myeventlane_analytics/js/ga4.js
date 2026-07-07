/**
 * @file
 * Loads GA4 and fires queued events, driven entirely by drupalSettings.
 *
 * No inline script tags exist anywhere in Twig — every value here (whether
 * to load, the measurement ID, and any pending events) comes from
 * AnalyticsService::buildDrupalSettings() via hook_page_attachments().
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.myeventlaneAnalyticsGa4 = {
    attach: function (context) {
      once('myeventlane-analytics-ga4', 'html', context).forEach(function () {
        var config = drupalSettings.myeventlaneAnalytics;
        if (!config || !config.enabled || !config.measurementId) {
          return;
        }

        window.dataLayer = window.dataLayer || [];
        var gtag = window.gtag || function () {
          window.dataLayer.push(arguments);
        };
        window.gtag = gtag;

        var script = document.createElement('script');
        script.async = true;
        script.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(config.measurementId);
        document.head.appendChild(script);

        gtag('js', new Date());
        gtag('config', config.measurementId);

        (config.events || []).forEach(function (event) {
          if (event && event.name) {
            gtag('event', event.name, event.params || {});
          }
        });
      });
    }
  };
})(Drupal, drupalSettings, once);
