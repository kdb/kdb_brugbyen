<?php

declare(strict_types=1);

namespace Drupal\kdb_brugbyen\Controller;

use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
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
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
      $container->get('file_url_generator'),
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
      if ($series instanceof EventSeries) {
        $result[] = $this->seriesData($series);
      }
    }


    return new JsonResponse($result);
  }

  /**
   * Produce the JSON data for a single event series.
   * @return mixed
   */
  public function seriesData(EventSeries $series): array {
    return [
      'uuid' => $series->uuid(),
      'title' => $series->label(),
      'last_update' => $this->iso8601($series->get('changed')->value),
      'url' => $series->toUrl()->setAbsolute(TRUE)->toString(),
      'image' => $this->getImage($series),
      'teaser' => $series->get('field_description')->value,
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

}
