/**
 * Grid-Aware Admin Scripts
 */
jQuery(document).ready(function($) {
  console.log('Grid-Aware: Admin script loaded');

  // Tab navigation is handled inline in the PHP

  // Dynamic form field visibility logic
  $('select[name="grid_aware_optimize_images"]').on('change', function() {
      var value = $(this).val();
      if (value === 'yes') {
          $('select[name="grid_aware_lazy_load"]').closest('tr').show();
          $('select[name="grid_aware_tiny_placeholders"]').closest('tr').show();
      } else {
          $('select[name="grid_aware_lazy_load"]').closest('tr').hide();
          $('select[name="grid_aware_tiny_placeholders"]').closest('tr').hide();
      }
  }).trigger('change');

  $('select[name="grid_aware_enable_super_eco"]').on('change', function() {
      var value = $(this).val();
      if (value === 'yes') {
          $('select[name="grid_aware_text_only_mode"]').closest('tr').show();
          $('select[name="grid_aware_optimize_video"]').closest('tr').show();
      } else {
          $('select[name="grid_aware_text_only_mode"]').closest('tr').hide();
          $('select[name="grid_aware_optimize_video"]').closest('tr').hide();
      }
  }).trigger('change');

  $('select[name="grid_aware_tiny_placeholders"]').on('change', function() {
      var value = $(this).val();
      if (value === 'yes') {
          $('select[name="grid_aware_tiny_placeholders_mode"]').closest('tr').show();
      } else {
          $('select[name="grid_aware_tiny_placeholders_mode"]').closest('tr').hide();
      }
  }).trigger('change');
});