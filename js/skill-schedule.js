/**
 * @file
 * Updates schedule type radio labels when the date input changes.
 */

(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.scheduledTaskLabels = {
    attach(context) {
      const dateInputs = once('task-schedule', '[name="schedule_date[date]"]', context);
      if (!dateInputs.length) return;

      dateInputs[0].addEventListener('change', function (e) {
        const d = new Date(e.target.value);
        if (isNaN(d.getTime())) return;

        const dayName = d.toLocaleDateString('en-US', { weekday: 'long' });
        const day = d.getDate();
        const suffixes = ['th', 'st', 'nd', 'rd'];
        const mod100 = day % 100;
        const suffix = (mod100 >= 11 && mod100 <= 13) ? 'th' : (suffixes[day % 10] || 'th');
        const ordinal = day + suffix;
        const monthDay = d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });

        context.querySelectorAll('[name="schedule_type"]').forEach(function (radio) {
          const label = radio.parentElement ? radio.parentElement.querySelector('label') : null;
          if (!label) return;
          if (radio.value === 'weekly') label.textContent = 'Weekly (' + dayName + ')';
          if (radio.value === 'monthly') label.textContent = 'Monthly (' + ordinal + ')';
          if (radio.value === 'yearly') label.textContent = 'Yearly (' + monthDay + ')';
        });
      });
    }
  };

})(Drupal, once);
