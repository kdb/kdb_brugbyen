<?php

declare(strict_types=1);

namespace Drupal\kdb_brugbyen\Controller;

use Drupal\Component\Datetime\Time;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\dpl_event\Form\SettingsForm;
use Drupal\recurring_events\Entity\EventSeries;
use Drupal\recurring_events\Plugin\Field\FieldType\WeeklyRecurringDate;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for the Brugbyen feed.
 */
class FeedController implements ContainerInjectionInterface {

  const NTH_MAPPING = [
    'first' => '1',
    'second' => '2',
    'third' => '3',
    'fourth' => '4',
    'last' => '-1',
  ];

  /**
   * Constructor.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DateFormatter $dateFormatter,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
    protected ConfigFactoryInterface $configFactory,
    protected Time $dateTime,
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
      $container->get('datetime.time'),
    );
  }

  /**
   * Get feed response.
   */
  public function index($nid = ''): Response {
    $date = new \DateTimeImmutable('@' . $this->dateTime->getRequestTime());
    $formatted_from_date = $date->format(DateTimeItemInterface::DATETIME_STORAGE_FORMAT);

    $seriesStorage = $this->entityTypeManager->getStorage('eventseries');
    $instanceStorage = $this->entityTypeManager->getStorage('eventinstance');
    $query = $instanceStorage->getAggregateQuery()
      ->accessCheck(TRUE)
      ->groupBy('eventseries_id')
      ->condition('date.value', $formatted_from_date, '>=')
      ->condition('status', TRUE);

    $eventseriesIds = array_map(fn ($res) => $res['eventseries_id'], $query->execute());

    $result = [];

    foreach ($seriesStorage->loadMultiple($eventseriesIds) as $series) {
      if ($series instanceof EventSeries &&
      (!$nid || $series->get('field_branch')->target_id == $nid) &&
      $data = $this->seriesData($series)) {
        $result = array_merge($result, $data);
      }
    }

    return new JsonResponse($result);
  }

  /**
   * Produce the JSON data for a single event series.
   *
   * Can produce multiple instances.
   *
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

    $scheduleData = $this->getScheduleData($series);

    $result = [];
    foreach ($scheduleData as $schedule) {
      $result[] = [
        // @deprecated
        'uuid' => $series->uuid() . $schedule['id_suffix'],
        'id' => $series->uuid() . $schedule['id_suffix'],
        'title' => $series->label(),
        'last_update' => $this->timestampToIso8601($series->get('changed')->value),
        'url' => $series->toUrl()->setAbsolute(TRUE)->toString(),
        'image' => $this->getImage($series),
        'teaser' => $series->get('field_description')->value,
        'body' => $this->getBody($series),
        'start_date' => $schedule['start_date'],
        'end_date' => $schedule['end_date'],
        'schedule_type' => $schedule['schedule_type'],
        'schedule' => $schedule['schedule'],
        'contact' => $this->getContact($series),
        'ticket_url' => $series->get('field_event_link')->uri,
        'ticket_categories' => $this->getTicketCategories($series),
        'district' => $district,
        'target_groups' => $target_groups,
        'categories' => $categories,
        'tags' => $tags,
      ];
    }

    return $result;
  }

  /**
   * Convert a timestamp to an ISO8601 formatted date.
   */
  protected function timestampToIso8601(string $timestamp): string {
    $date = new \DateTimeImmutable('@' . $timestamp);

    // Use danish timezone for the sanity of developers.
    $date = $date->setTimezone(new \DateTimeZone('Europe/Copenhagen'));

    return $date->format('c');
  }

  /**
   * Render DrupalDateTime an ISO8601 formatted date.
   */
  protected function toIso8601(\DateTimeImmutable $date): string {
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

  /**
   * Extract scheduling information from event series.
   *
   * Can return multiple scheduling instances.
   */
  protected function getScheduleData(EventSeries $series): array {
    require_once(__DIR__ . '/../../php-rrule/src/RRuleInterface.php');
    require_once(__DIR__ . '/../../php-rrule/src/RRuleTrait.php');
    require_once(__DIR__ . '/../../php-rrule/src/RRule.php');
    require_once(__DIR__ . '/../../php-rrule/src/RSet.php');

    $renderDates = [];
    $rrule = NULL;
    $rdate = $exdate = '';
    $spec = [];
    switch ($series->get('recur_type')->value) {
      case 'weekly_recurring_date':
        $field = $series->get('weekly_recurring_date')->first();
        [$startDate, $endDate, $until] = $this->eventDates($field);
        $renderDates[] = [$startDate, $endDate];

        if ($until) {
          $spec['dtstart'] = $startDate;
          $spec['freq'] = 'weekly';
          $spec['byday'] = $this->rruleDays($field->days);
          $spec['until'] = $until;

        }
        break;

      case 'monthly_recurring_date':
        $field = $series->get('monthly_recurring_date')->first();
        [$startDate, $endDate, $until] = $this->eventDates($field);
        $renderDates[] = [$startDate, $endDate];

        if ($until) {
          $spec['dtstart'] = $startDate;
          $spec['freq'] = 'monthly';
          $spec['until'] = $until;

          if ($field->type == 'weekday') {
            // Translate the "first", "second", "last" that recurring_events
            // uses to the 1, 2, -1 that iCal uses.
            $nths = explode(',', $field->day_occurrence);
            $nths = array_map(fn ($nth) => self::NTH_MAPPING[$nth], $nths);

            $days = explode(',', $this->rruleDays($field->days));

            // Combine nths with days. "first,last" and "Tuesday,Friday" should
            // map to "1TU,-1TU,1FR,-1FR".
            $bydays = [];
            foreach ($days as $day) {
              $bydays[] = implode(',', array_map(fn ($nth) => "{$nth}{$day}", $nths));
            }

            $spec['byday'] = implode(',', $bydays);
          }
          else {
            $spec['bymonthday'] = $field->day_of_month;
          }
        }
        break;

      case 'custom':
        $i = 1;
        // We can't guess a repetition rule from an event with multiple dates,
        // so we'll just clone it. Secondly we'll just use the dates from
        // instances as they will reflect any edits to the instances which will
        // save us having to compare series dates to instance dates.
        foreach ($this->getInstances($series) as $instance) {
          $renderDates["-" . $i++] = [
            // Convert DrupalDateTime to DateTimeImmutable
            new \DateTimeImmutable($instance->get('date')->start_date->format('c')),
            new \DateTimeImmutable($instance->get('date')->end_date->format('c')),
          ];
        }
        break;

      case 'consecutive_recurring_date':
        // Consecutive events is basically repeating double, once within a day,
        // once across days. That's difficult to put into a single RRULE, and
        // editorially they're used for things that we're not interested in
        // sending to brugbyen anyway.
      default:
        return [];
    }

    if ($spec) {
      $rrule = new \RRule\RRule($spec);
      $rset = new \RRule\Rset();

      $rset->addRRule($rrule);

      $comparator = function ($a, $b) {
        return $a <=> $b;
      };

      // Compare the repeating rule with the actual instances, and add the
      // exceptions to RDATE/EXDATE.
      $rruleDates = $rset->getOccurrences();
      $instanceDates = $this->getInstanceDates($series);
      $removed = array_udiff($rruleDates, $instanceDates, $comparator);
      $added = array_udiff($instanceDates, $rruleDates, $comparator);

      // Format dates according to iCal spec.
      $format = fn($date) => $date->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');

      if ($removed) {
        $exdate = implode(',', array_map($format, $removed));
      }

      if ($added) {
        $rdate = implode(',', array_map($format, $added));
      }

      // RRule adds the DTSTART to the output, but it's implicitly given by the
      // `start_date`, so strip it from here.
      $parts = explode("\n", (string) $rrule);
      $rrule = $parts[1];
      // And strip the "RRULE:" prefix.
      $parts = explode("RRULE:", (string) $rrule);
      $rrule = $parts[1];
    }

    $result = [];

    foreach ($renderDates as $id_suffix => [$startDate, $endDate]) {
      $scheduleType = 'single';

      if ($rrule) {
        $scheduleType = 'repeated';
      }
      elseif ($startDate->format('Ymd') != $endDate->format('Ymd')) {
        $scheduleType = 'prolonged';
      }

      $result[] = [
        'id_suffix' => !empty($id_suffix) ? $id_suffix : '',
        'start_date' => $this->toIso8601($startDate),
        'end_date' => $this->toIso8601($endDate),
        'schedule_type' => $scheduleType,
        'schedule' => [
          'rrule' => $rrule,
          'rdate' => $rdate,
          'exdate' => $exdate,
        ],
      ];
    }

    return $result;
  }

  /**
   * Get event start and end as DateTimeImmutables.
   *
   * Returns an array of `start`, `end` and `until`
   */
  protected function eventDates(WeeklyRecurringDate $rdate) {
    // Start and end are stored as datetimes, but the time part is garbage.
    $startDate = explode('T', $rdate->value)[0];
    $endDate = explode('T', $rdate->end_value)[0];

    $until = NULL;
    if ($startDate != $endDate) {
      // php-rrule expects to be able to do `setTimezone()` on the until date,
      // but that doesn't work for DateTimeImmutable, so we create a regular
      // DateTime here. We set the time to the end of the day, as it's
      // inclusive.
      $until = \DateTime::createFromFormat('Y-m-d H:i:s:u', $endDate . '23:59:59:0', new \DateTimeZone('Europe/Copenhagen'));
    }

    // And times are stored in American AM/PM format, so we use createFromFormat
    // to parse the start date and the time part into a proper DateTimeImmutable.
    $start = \DateTimeImmutable::createFromFormat('Y-m-d g:i a', "{$startDate} {$rdate->time}");


    if ($rdate->duration_or_end_time == 'duration') {
      // $end = $start->add(new \DateInterval("P{$rdate->duration}S"));
      $end = $start->modify("+{$rdate->duration} seconds");
    }
    else {
      $end = \DateTimeImmutable::createFromFormat('Y-m-d g:i a', "{$startDate} {$rdate->end_time}");
    }

    return [
      $start,
      $end,
      $until,
    ];
  }

  /**
   * Convert recurring_events days to rrule days.
   *
   * `recurring_events` uses "monday, tuesday" while rrule uses "MO,TU".
   */
  protected function rruleDays(string $days): string {
    $days = explode(',', $days);

    $days = array_map(function ($day) {
      return strtoupper(substr($day, 0, 2));
    }, $days);

    return implode(',', $days);
  }

  /**
   * Get an events instances.
   *
   * @return Drupal\recurring_events\Entity\EventInstance[]
   */
  protected function getInstances(EventSeries $series): array {
    $storage = $this->entityTypeManager->getStorage('eventinstance');
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('eventseries_id', $series->id())
      ->condition('status', TRUE);

    return $storage->loadMultiple($query->execute());
  }

  protected function getInstanceDates(EventSeries $series): array {
    $result = [];

    foreach ($this->getInstances($series) as $instance) {
      $result[] = new \DateTimeImmutable($instance->get('date')->start_date->format('c'));
    }

    return $result;
  }
}
