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
  // Analytics dashboard functionality
  if ($('.grid-aware-analytics').length > 0) {
    // Auto-refresh analytics data every 5 minutes
    setInterval(function() {
      if (typeof gridAwareAnalytics !== 'undefined') {
        refreshAnalyticsData();
      }
    }, 300000); // 5 minutes

    // Initialize chart if Chart.js is available
    if (typeof Chart !== 'undefined') {
      initializeCarbonChart();
    }

    // Handle period filter changes
    $('#analytics-period').on('change', function() {
      refreshAnalyticsData();
    });

    // Handle export button clicks
    $('.export-data').on('click', function(e) {
      e.preventDefault();
      var format = $(this).data('format');
      exportAnalyticsData(format);
    });
  }

  // Dashboard widget real-time updates
  if ($('.grid-aware-dashboard-widget').length > 0) {
    // Update dashboard widget every 2 minutes
    setInterval(function() {
      updateDashboardWidget();
    }, 120000); // 2 minutes
  }
});

/**
 * Refresh analytics data
 */
function refreshAnalyticsData() {
  var period = jQuery('#analytics-period').val() || '7days';

  jQuery.ajax({
    url: ajaxurl,
    type: 'POST',
    data: {
      action: 'grid_aware_analytics_data',
      nonce: jQuery('#grid-aware-nonce').val(),
      period: period
    },
    success: function(response) {
      if (response.success) {
        updateAnalyticsDisplay(response.data);
      }
    },
    error: function() {
      console.error('Failed to refresh analytics data');
    }
  });
}

/**
 * Update analytics display with new data
 */
function updateAnalyticsDisplay(data) {
  if (!data.summary) return;

  // Update summary cards
  jQuery('.carbon-footprint .metric-value').text(
    parseFloat(data.summary.total_carbon_g).toFixed(3) + 'g CO₂'
  );
  jQuery('.carbon-saved .metric-value').text(
    parseFloat(data.summary.total_savings_g).toFixed(3) + 'g CO₂'
  );
  jQuery('.page-views .metric-value').text(
    parseInt(data.summary.total_page_views).toLocaleString()
  );
  jQuery('.data-transfer .metric-value').text(
    parseFloat(data.summary.total_data_mb).toFixed(1) + ' MB'
  );

  // Update savings percentage
  jQuery('.carbon-saved .metric-subtitle').text(
    data.summary.savings_percentage + '% reduction'
  );

  // Update chart data if available
  if (data.timeline && window.carbonChart) {
    updateCarbonChart(data.timeline);
  }
}

/**
 * Update carbon chart with new timeline data
 */
function updateCarbonChart(timelineData) {
  if (!window.carbonChart || !timelineData) return;

  // Update chart data
  window.carbonChart.data.labels = timelineData.labels || [];

  if (timelineData.datasets) {
    window.carbonChart.data.datasets[0].data = timelineData.datasets.carbon_intensity || [];
    window.carbonChart.data.datasets[1].data = timelineData.datasets.carbon_footprint || [];
    window.carbonChart.data.datasets[2].data = timelineData.datasets.carbon_savings || [];
    window.carbonChart.data.datasets[3].data = timelineData.datasets.page_views || [];
  }

  // Animate the chart update
  window.carbonChart.update('active');
}

/**
 * Initialize carbon timeline chart
 */
function initializeCarbonChart() {
  var ctx = document.getElementById('carbon-timeline-chart');
  if (!ctx || typeof gridAwareChartData === 'undefined') return;

  // Destroy existing chart if it exists
  if (window.carbonChart) {
    window.carbonChart.destroy();
  }

  // Configure chart data
  var chartData = {
    labels: gridAwareChartData.labels,
    datasets: [
      {
        label: 'Carbon Intensity (gCO₂/kWh)',
        data: gridAwareChartData.datasets.carbon_intensity,
        borderColor: 'rgb(75, 192, 192)',
        backgroundColor: 'rgba(75, 192, 192, 0.2)',
        yAxisID: 'y',
        tension: 0.1
      },
      {
        label: 'Carbon Footprint (g)',
        data: gridAwareChartData.datasets.carbon_footprint,
        borderColor: 'rgb(255, 99, 132)',
        backgroundColor: 'rgba(255, 99, 132, 0.2)',
        yAxisID: 'y1',
        tension: 0.1
      },
      {
        label: 'Carbon Savings (g)',
        data: gridAwareChartData.datasets.carbon_savings,
        borderColor: 'rgb(54, 162, 235)',
        backgroundColor: 'rgba(54, 162, 235, 0.2)',
        yAxisID: 'y1',
        tension: 0.1
      },
      {
        label: 'Page Views',
        data: gridAwareChartData.datasets.page_views,
        borderColor: 'rgb(255, 205, 86)',
        backgroundColor: 'rgba(255, 205, 86, 0.2)',
        yAxisID: 'y2',
        tension: 0.1
      }
    ]
  };

  // Configure chart options
  var options = {
    responsive: true,
    maintainAspectRatio: false,
    interaction: {
      mode: 'index',
      intersect: false,
    },
    plugins: {
      title: {
        display: true,
        text: 'Carbon Footprint Timeline'
      },
      legend: {
        position: 'top',
      },
      tooltip: {
        callbacks: {
          afterLabel: function(context) {
            if (context.datasetIndex === 0) {
              return 'Lower values = cleaner energy';
            } else if (context.datasetIndex === 2) {
              return 'Energy optimization savings';
            }
            return '';
          }
        }
      }
    },
    scales: {
      x: {
        display: true,
        title: {
          display: true,
          text: 'Time'
        }
      },
      y: {
        type: 'linear',
        display: true,
        position: 'left',
        title: {
          display: true,
          text: 'Carbon Intensity (gCO₂/kWh)'
        },
        grid: {
          color: 'rgba(75, 192, 192, 0.1)'
        }
      },
      y1: {
        type: 'linear',
        display: true,
        position: 'right',
        title: {
          display: true,
          text: 'Carbon Amount (g)'
        },
        grid: {
          drawOnChartArea: false,
          color: 'rgba(255, 99, 132, 0.1)'
        }
      },
      y2: {
        type: 'linear',
        display: false,
        position: 'right'
      }
    }
  };

  // Create the chart
  window.carbonChart = new Chart(ctx, {
    type: 'line',
    data: chartData,
    options: options
  });
}

/**
 * Update dashboard widget with latest data
 */
function updateDashboardWidget() {
  jQuery.ajax({
    url: ajaxurl,
    type: 'POST',
    data: {
      action: 'grid_aware_dashboard_update',
      nonce: jQuery('#grid-aware-dashboard-nonce').val()
    },
    success: function(response) {
      if (response.success && response.data.html) {
        jQuery('.grid-aware-dashboard-widget').html(response.data.html);
      }
    },
    error: function() {
      console.error('Failed to update dashboard widget');
    }
  });
}

/**
 * Export analytics data in specified format
 */
function exportAnalyticsData(format) {
  var period = jQuery('#analytics-period').val() || '7days';

  // Show loading state
  jQuery('.export-data[data-format="' + format + '"]').prop('disabled', true).text('Exporting...');

  jQuery.ajax({
    url: ajaxurl,
    type: 'POST',
    data: {
      action: 'grid_aware_export_analytics',
      nonce: jQuery('#grid-aware-nonce').val(),
      period: period,
      format: format
    },
    success: function(response) {
      if (response.success) {
        // Create download link
        var link = document.createElement('a');
        link.href = response.data.download_url;
        link.download = response.data.filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);

        // Show success message
        showNotice('Analytics data exported successfully!', 'success');
      } else {
        showNotice('Export failed: ' + (response.data || 'Unknown error'), 'error');
      }
    },
    error: function() {
      showNotice('Export failed due to network error', 'error');
    },
    complete: function() {
      // Reset button state
      jQuery('.export-data[data-format="' + format + '"]').prop('disabled', false).text('Export ' + format.toUpperCase());
    }
  });
}

/**
 * Show admin notice
 */
function showNotice(message, type) {
  var notice = jQuery('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
  jQuery('.grid-aware-analytics .wrap h1').after(notice);

  // Auto-dismiss after 5 seconds
  setTimeout(function() {
    notice.fadeOut(function() {
      notice.remove();
    });
  }, 5000);
}