/**
 * @file
 * Add summary to fieldset.
 */

(function ($) {

  'use strict';

  /**
   * Provide summary information for vertical tabs.
   */
  Drupal.behaviors.kdb_brugbyen_fieldset = {
    attach: function (context) {
      // Set help text as summary.
      $('details#edit-group-brugbyen', context).drupalSetSummary(function (context) {
        return Drupal.t('Share event with brugbyen.kk.dk');
      });
    }
  };

})(jQuery);
