/**
 * @file
 * Syncs entity_autocomplete display field to hidden UID value field.
 */

(function (Drupal, once, $) {
  'use strict';

  Drupal.behaviors.userReferenceAutocomplete = {
    attach: function (context) {
      once('user-ref-autocomplete', '[data-user-ref-display]', context).forEach(function (displayField) {
        var wrapper = displayField.closest('.form-type--user-reference-autocomplete');
        var valueField = wrapper ? wrapper.querySelector('[data-user-ref-value]') : null;
        if (!valueField) {
          return;
        }

        // Use jQuery to listen — Drupal's autocomplete fires jQuery events,
        // not native DOM events.
        $(displayField).on('autocompleteclose', function () {
          var text = displayField.value;
          var match = text.match(/\((\d+)\)$/);
          if (match) {
            valueField.value = match[1];
          } else if (text.trim() === '') {
            valueField.value = '0';
          } else {
            return;
          }
          $(valueField).trigger('change');
        });
      });
    }
  };

})(Drupal, once, jQuery);
