<?php

namespace Drupal\kdb_brugbyen\Config;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorageInterface;

/**
 * Overriding config dynamically.
 *
 * Instead of shipping (and continually re-syncing) full copies of the
 * eventseries form/view displays, we let dpl-cms own them and inject our
 * bb_* fields here. See kdb_cludo for another example of this.
 */
class ConfigOverrides implements ConfigFactoryOverrideInterface {

  /**
   * The bb_* fields managed by this module.
   *
   * Order in this array also drives the form-widget weight (lowest first).
   */
  protected const BB_FIELDS = [
    'field_bb_district',
    'field_bb_categories',
    'field_bb_target_groups',
    'field_bb_tags',
  ];

  /**
   * Form display config name we inject the bb_* fields into.
   */
  protected const FORM_DISPLAY = 'core.entity_form_display.eventseries.default.default';

  /**
   * Default view display: bb_* shown as entity_reference_label (except district).
   */
  protected const VIEW_DISPLAY_DEFAULT = 'core.entity_view_display.eventseries.default.default';

  /**
   * View displays where every bb_* field is hidden.
   */
  protected const VIEW_DISPLAYS_HIDDEN = [
    'core.entity_view_display.eventseries.default.card',
    'core.entity_view_display.eventseries.default.list',
    'core.entity_view_display.eventseries.default.nav_spot',
    'core.entity_view_display.eventseries.default.nav_teaser',
  ];

  /**
   * {@inheritdoc}
   *
   * @param array<mixed> $names
   *   The available config names.
   *
   * @return array<mixed>
   *   The config overrides.
   */
  public function loadOverrides($names): array {
    $overrides = [];

    if (in_array(self::FORM_DISPLAY, $names, TRUE)) {
      $overrides[self::FORM_DISPLAY] = $this->buildFormDisplayOverride();
    }

    if (in_array(self::VIEW_DISPLAY_DEFAULT, $names, TRUE)) {
      $overrides[self::VIEW_DISPLAY_DEFAULT] = $this->buildDefaultViewDisplayOverride();
    }

    foreach (self::VIEW_DISPLAYS_HIDDEN as $name) {
      if (in_array($name, $names, TRUE)) {
        $overrides[$name] = $this->buildHiddenViewDisplayOverride();
      }
    }

    return $overrides;
  }

  /**
   * Build the eventseries form display override.
   *
   * Adds:
   * - All bb_* fields as select2_entity_reference widgets in `content`.
   * - A `group_bb` field_group ("Brugbyen") in the sidebar containing them.
   * - Module dependencies so the override doesn't dangle.
   *
   * @return array<string, mixed>
   *   The override structure.
   */
  protected function buildFormDisplayOverride(): array {
    $content = [];
    $weight = 2;
    foreach (self::BB_FIELDS as $field) {
      $content[$field] = [
        'type' => 'select2_entity_reference',
        'weight' => $weight++,
        'region' => 'content',
        'settings' => [
          'width' => '100%',
          'autocomplete' => FALSE,
          'match_operator' => 'CONTAINS',
          'match_limit' => 10,
        ],
        'third_party_settings' => [],
      ];
    }

    return [
      'dependencies' => [
        'config' => array_map(
          static fn (string $field): string => "field.field.eventseries.default.$field",
          self::BB_FIELDS,
        ),
        'module' => ['select2'],
      ],
      'content' => $content,
      'third_party_settings' => [
        'field_group' => [
          'group_bb' => [
            'children' => self::BB_FIELDS,
            'label' => 'Brugbyen',
            'region' => 'content',
            'parent_name' => '',
            'weight' => 27,
            'format_type' => 'details_sidebar',
            'format_settings' => [
              'classes' => '',
              'show_empty_fields' => FALSE,
              'id' => '',
              'label_as_html' => FALSE,
              'open' => TRUE,
              'description' => '',
              'required_fields' => TRUE,
              'weight' => 0,
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * Build the override for the default eventseries view display.
   *
   * bb_categories, bb_tags and bb_target_groups render as entity_reference_label.
   * bb_district stays hidden.
   *
   * @return array<string, mixed>
   *   The override structure.
   */
  protected function buildDefaultViewDisplayOverride(): array {
    $visible = [
      'field_bb_target_groups' => 15,
      'field_bb_categories' => 16,
      'field_bb_tags' => 17,
    ];

    $content = [];
    foreach ($visible as $field => $weight) {
      $content[$field] = [
        'type' => 'entity_reference_label',
        'label' => 'above',
        'settings' => ['link' => TRUE],
        'third_party_settings' => [],
        'weight' => $weight,
        'region' => 'content',
      ];
    }

    return [
      'dependencies' => [
        'config' => array_map(
          static fn (string $field): string => "field.field.eventseries.default.$field",
          self::BB_FIELDS,
        ),
      ],
      'content' => $content,
      'hidden' => [
        'field_bb_district' => TRUE,
      ],
    ];
  }

  /**
   * Build a view-display override that hides every bb_* field.
   *
   * Used for card, list, nav_spot and nav_teaser view modes.
   *
   * @return array<string, mixed>
   *   The override structure.
   */
  protected function buildHiddenViewDisplayOverride(): array {
    $hidden = [];
    foreach (self::BB_FIELDS as $field) {
      $hidden[$field] = TRUE;
    }

    return [
      'dependencies' => [
        'config' => array_map(
          static fn (string $field): string => "field.field.eventseries.default.$field",
          self::BB_FIELDS,
        ),
      ],
      'hidden' => $hidden,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheSuffix() {
    return 'ConfigOverrides';
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata($name) {
    return new CacheableMetadata();
  }

  /**
   * {@inheritdoc}
   */
  public function createConfigObject($name, $collection = StorageInterface::DEFAULT_COLLECTION) {
    return NULL;
  }

}
