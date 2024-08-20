<?php

declare(strict_types=1);

namespace Drupal\kdb_brugbyen\EventSubscriber;

use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\StorageTransformEvent;
use Drupal\Core\Extension\ExtensionPathResolver;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * EventListener to overlay configuration on configuration import.
 */
class OverlayConfigEventSubscriber implements EventSubscriberInterface {

  /**
   * Constructor.
   */
  public function __construct(protected ExtensionPathResolver $pathResolver) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events = [];

    $events[ConfigEvents::STORAGE_TRANSFORM_IMPORT][] = ['onImportTransform'];

    return $events;
  }

  /**
   * Transform configuration import by overlaying our configuration.
   */
  public function onImportTransform(StorageTransformEvent $event): void {
    $storage = $event->getStorage();

    $path = $this->pathResolver->getPath('module', 'kdb_brugbyen') . '/config/sync';
    $overlay = new FileStorage($path);

    self::overlayConfig($storage, $overlay);
  }

  /**
   * Overlay some configuration.
   *
   * Simply overrides the configuration entries from the overlay, creating
   * them if they do not exist.
   */
  public static function overlayConfig(StorageInterface $storage, StorageInterface $overlay): void {
    // getAllCollectionNames() doesn't return the default collection, so we have
    // to add it explicitly.
    $collectionNames = [StorageInterface::DEFAULT_COLLECTION] +
      $overlay->getAllCollectionNames();

    foreach ($collectionNames as $collectionName) {
      $storageCollection = $storage->createCollection($collectionName);
      $overlayCollection = $overlay->createCollection($collectionName);

      foreach ($overlayCollection->listAll() as $name) {
        $data = $overlayCollection->read($name);
        if ($data) {
          $storageCollection->write($name, $data);
        }
      }
    }
  }

}
