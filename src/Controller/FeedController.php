<?php

declare(strict_types=1);

namespace Drupal\kdb_brugbyen\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\dpl_event\Form\SettingsForm;
use Drupal\recurring_events\Entity\EventSeries;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for the Brugbyen feed.
 */
class FeedController implements ContainerInjectionInterface {

  /**
   * Constructor.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DateFormatter $dateFormatter,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
      $container->get('file_url_generator'),
      $container->get('config.factory'),
    );
  }

  /**
   * Get feed response.
   */
  public function index(): Response {
    // @todo: only select event with future occurrences. Due to
    // `recurring_events` rather messed up data model, this requires a rather
    // complex query, so we'll start out by focusing on the rendering.


    $storage = $this->entityTypeManager->getStorage('eventseries');
    // @todo: sort the result?
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', TRUE);

    $result = [];

    foreach ($storage->loadMultiple($query->execute()) as $series) {
      if ($series instanceof EventSeries && $data = $this->seriesData($series)) {
        $result[] = $data;
      }
    }

    return new JsonResponse($result);
  }

  /**
   * Produce the JSON data for a single event series.
   * @return mixed
   */
  public function seriesData(EventSeries $series): ?array {
    $district = NULL;
    $target_groups = $categories = $tags = [];

    // There is at most one, but `->referencedEntities()[0]?->label() ?? NULL`
    // triggers a warning, so we use a loop instead.
    foreach ($series->get('field_bb_district')->referencedEntities() as $term) {
      $district = $term->label();
    }

    foreach ($series->get('field_bb_target_groups')->referencedEntities() as $term) {
      $target_groups[] = $term->label();
    }

    foreach ($series->get('field_bb_categories')->referencedEntities() as $term) {
      $categories[] = $term->label();
    }

    foreach ($series->get('field_bb_tags')->referencedEntities() as $term) {
      $tags[] = $term->label();
    }

    if (!$district || !$target_groups || !$categories) {
      return NULL;
    }

    return [
      'uuid' => $series->uuid(),
      'title' => $series->label(),
      'last_update' => $this->iso8601($series->get('changed')->value),
      'url' => $series->toUrl()->setAbsolute(TRUE)->toString(),
      'image' => $this->getImage($series),
      'teaser' => $series->get('field_description')->value,
      'body' => $this->getBody($series),
      'start_date' => '@todo',
      'end_date' => '@todo',
      'schedule_type' => '@todo',
      'schedule' => [
        'rrule' => '',
        'rdate' => '',
        'exdate' => '',
      ],
      'contact' => $this->getContact($series),
      'ticket_url' => $series->get('field_event_link')->uri,
      'ticket_categories' => $this->getTicketCategories($series),
      'district' => $district,
      'target_groups' => $target_groups,
      'categories' => $categories,
      'tags' => $tags,
    ];
  }

  /**
   * Convert a timestamp to an ISO8601 formatted date.
   */
  protected function iso8601(string $timestamp): string {
    $date = new \DateTimeImmutable('@' . $timestamp);

    // Use danish timezone for the sanity of developers.
    $date = $date->setTimezone(new \DateTimeZone('Europe/Copenhagen'));

    return $date->format('c');
  }

  /**
   * Return URL for event image, or an empty string.
   */
  protected function getImage(EventSeries $series): string {
    $media = $series->get('field_event_image')->referencedEntities()[0] ?? NULL;

    if (!$media) {
      return '';
    }

    $url = $media->field_media_image->entity->getFileUri();

    if (!$url) {
      return '';
    }

    return $this->fileUrlGenerator->generateAbsoluteString($url);
  }

  /**
   * Get the event body.
   *
   * Returns the content of all `text_body` paragraphs on the event.
   */
  protected function getBody(EventSeries $series): string {
    $paragraphs = $series->get('field_event_paragraphs')->referencedEntities();

    $body = '';
    foreach ($paragraphs as $paragraph) {
      if ($paragraph->bundle() === 'text_body') {
        $body .= $paragraph->get('field_body')->getValue()[0]['value'] ?? '';
      }
    }

    return $body;
  }

  /**
   * Get event contact.
   *
   * Collects the contact information and returns the JSON data fragment.
   */
  protected function getContact(EventSeries $series): ?array {
    $branch = $series->get('field_branch')->referencedEntities()[0] ?? NULL;
    $place = $series->get('field_event_place')->value ?? '';
    $address = NULL;
    $name = $location = $phone = '';

    if ($branch) {
      $name = $branch->label();
      $location = $place;
      $address = $branch->get('field_address');
      $phone = $branch->get('field_phone')->value;
    }
    elseif ($place) {
      $name = $place;
    }

    if (!$series->get('field_event_address')->isEmpty()) {
      $address = $series->get('field_event_address');
    }

    if (!$address || $address->isEmpty()) {
      return NULL;
    }

    return [
      'name' => $name,
      'location' => $location,
      'phone' => $phone,
      'street_and_num' => $address->address_line1,
      'zip' => $address->postal_code,
      'city' => $address->locality,
    ];
  }

  /**
   * Get event ticket categories.
   *
   * Collects the ticket categories and returns the JSON data fragment.
   */
  protected function getTicketCategories(EventSeries $series): ?array {
    $categories = $series->get('field_ticket_categories')->referencedEntities();
    $config = $this->configFactory->get(SettingsForm::CONFIG_NAME);

    $result = [];

    foreach ($categories as $ticketCategory) {
      if ($ticketCategory->bundle() == 'event_ticket_category') {
        $result[] = [
          'amount' => ((float) $ticketCategory->get('field_ticket_category_price')->value) * 100,
          'currency' => $config->get('price_currency') ?? 'DKK',
          'title' => $ticketCategory->get('field_ticket_category_name')->value,
        ];
      }
    }

    return $result;
  }

}
