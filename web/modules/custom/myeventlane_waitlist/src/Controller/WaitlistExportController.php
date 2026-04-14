<?php

declare(strict_types=1);

namespace Drupal\myeventlane_waitlist\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV export for waitlist subscribers.
 */
final class WaitlistExportController extends ControllerBase {

  public function __construct(
    private readonly Connection $database,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database')
    );
  }

  public function export(): StreamedResponse {
    $database = $this->database;
    $response = new StreamedResponse(function () use ($database): void {
      $out = fopen('php://output', 'w');
      if ($out === FALSE) {
        return;
      }
      fputcsv($out, [
        'id',
        'email',
        'email_normalized',
        'first_name',
        'interest_type',
        'status',
        'consent_given',
        'consent_version',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'landing_path',
        'referrer',
        'locale',
        'form_variant',
        'created',
        'changed',
        'confirmed_at',
        'unsubscribed_at',
      ]);
      $q = $database->select('myeventlane_waitlist_subscriber', 's')
        ->fields('s')
        ->orderBy('id', 'ASC');
      $result = $q->execute();
      foreach ($result as $row) {
        /** @var object $row */
        fputcsv($out, [
          $row->id,
          $row->email,
          $row->email_normalized,
          $row->first_name,
          $row->interest_type,
          $row->status,
          $row->consent_given,
          $row->consent_version,
          $row->utm_source,
          $row->utm_medium,
          $row->utm_campaign,
          $row->utm_term,
          $row->utm_content,
          $row->landing_path,
          $row->referrer,
          $row->locale,
          $row->form_variant,
          $row->created,
          $row->changed,
          $row->confirmed_at,
          $row->unsubscribed_at,
        ]);
      }
      fclose($out);
    });
    $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="myeventlane-waitlist.csv"');
    return $response;
  }

}
