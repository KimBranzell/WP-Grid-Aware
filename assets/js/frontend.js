/**
 * Grid-Aware Frontend Scripts
 * Enhanced for accessibility and smart image serving
 */
(function() {
  // Wait for DOM to be loaded
  document.addEventListener('DOMContentLoaded', function() {
      console.log('Grid-Aware: Frontend script loaded');

      // Initialize Smart Image Serving
      initSmartImageServing();

      // Handle "Show Image" buttons in alt text boxes
      document.addEventListener('click', function(e) {
          if (e.target.classList.contains('grid-aware-show-image')) {
              const button = e.target;
              const box = button.closest('.grid-aware-alt-text-box');

              if (box) {
                  const src = button.getAttribute('data-src');
                  if (src) {
                      // Add loading state with proper aria attributes
                      button.innerHTML = 'Loading...';
                      button.disabled = true;
                      button.setAttribute('aria-busy', 'true');

                      // Announce to screen readers that loading has started
                      announceToScreenReaders('Loading image. Please wait.');

                      // Replace the box with the actual image
                      const img = document.createElement('img');
                      img.src = src;
                      img.alt = box.getAttribute('aria-label') || '';
                      img.className = box.className.replace('grid-aware-alt-text-box', '').trim();

                      img.onload = function() {
                          box.parentNode.replaceChild(img, box);
                          // Announce to screen readers that image has loaded
                          announceToScreenReaders('Image loaded successfully.');
                      };

                      img.onerror = function() {
                          button.innerHTML = 'Failed to load image';
                          button.disabled = false;
                          button.setAttribute('aria-busy', 'false');
                          // Announce error to screen readers
                          announceToScreenReaders('Failed to load image. Please try again.');
                          setTimeout(function() {
                              button.innerHTML = 'Try again';
                          }, 2000);
                      };
                  }
              }
          }
      });

      // Handle video placeholders
      document.addEventListener('click', function(e) {
          if (e.target.classList.contains('grid-aware-load-video') ||
              (e.target.closest('.grid-aware-video-placeholder') && !e.target.classList.contains('grid-aware-load-video'))) {

              const button = e.target.classList.contains('grid-aware-load-video') ?
                  e.target : e.target.querySelector('.grid-aware-load-video');

              if (!button) return;

              const placeholder = button.closest('.grid-aware-video-placeholder');
              if (placeholder) {
                  // Add loading state
                  button.innerHTML = 'Loading...';
                  button.disabled = true;
                  button.setAttribute('aria-busy', 'true');

                  // Announce loading to screen readers
                  announceToScreenReaders('Loading video. Please wait.');

                  const videoEmbed = placeholder.getAttribute('data-video-embed');
                  if (videoEmbed) {
                      // Replace placeholder with actual embed
                      placeholder.outerHTML = decodeURIComponent(videoEmbed);

                      // Announce to screen readers
                      setTimeout(() => {
                          announceToScreenReaders('Video loaded successfully.');
                      }, 500);
                  }
              }
          }
      });

      // Process tiny images with lazy loading
      processTinyImages();

      // Initialize tabindex for all interactive elements
      makeElementsFocusable();

      // Add role attributes to improve screen reader experience
      addRoleAttributes();
  });

  /**
   * Initialize Smart Image Serving
   */
  function initSmartImageServing() {
      // Check if connection detection is available
      if (typeof window.GridAwareConnection !== 'undefined') {
          // Monitor connection changes and update image serving strategy
          monitorConnectionChanges();

          // Optimize existing images based on current connection
          optimizeExistingImages();

          // Set up observer for new images
          setupImageObserver();
      }
  }

  /**
   * Monitor connection changes
   */
  function monitorConnectionChanges() {
      if ('connection' in navigator && navigator.connection) {
          navigator.connection.addEventListener('change', function() {
              console.log('Grid-Aware: Connection changed, re-evaluating image optimization');
              setTimeout(function() {
                  optimizeExistingImages();
              }, 1000);
          });
      }
  }

  /**
   * Optimize existing images based on connection
   */
  function optimizeExistingImages() {
      const images = document.querySelectorAll('img[data-grid-aware-src]');
      const connectionInfo = window.GridAwareConnection.get();

      images.forEach(function(img) {
          const originalSrc = img.getAttribute('data-grid-aware-src');
          const optimizedSrc = getOptimizedImageSrc(originalSrc, connectionInfo);

          if (optimizedSrc && optimizedSrc !== img.src) {
              // Preload the optimized image
              const preloadImg = new Image();
              preloadImg.onload = function() {
                  img.src = optimizedSrc;
              };
              preloadImg.src = optimizedSrc;
          }
      });
  }

  /**
   * Set up observer for new images
   */
  function setupImageObserver() {
      const observer = new MutationObserver(function(mutations) {
          mutations.forEach(function(mutation) {
              mutation.addedNodes.forEach(function(node) {
                  if (node.nodeType === 1) { // Element node
                      const images = node.tagName === 'IMG' ?
                          [node] :
                          node.querySelectorAll ? node.querySelectorAll('img') : [];

                      images.forEach(function(img) {
                          if (img.hasAttribute('data-grid-aware-src')) {
                              optimizeSingleImage(img);
                          }
                      });
                  }
              });
          });
      });

      observer.observe(document.body, {
          childList: true,
          subtree: true
      });
  }

  /**
   * Optimize a single image
   */
  function optimizeSingleImage(img) {
      const connectionInfo = window.GridAwareConnection.get();
      const originalSrc = img.getAttribute('data-grid-aware-src');
      const optimizedSrc = getOptimizedImageSrc(originalSrc, connectionInfo);

      if (optimizedSrc) {
          img.src = optimizedSrc;
      }
  }

  /**
   * Get optimized image source based on connection
   */
  function getOptimizedImageSrc(originalSrc, connectionInfo) {
      // This would typically make a request to the server to get the optimized URL
      // For now, we'll add query parameters to indicate the optimization level

      const url = new URL(originalSrc, window.location.origin);
      const optimizationLevel = calculateOptimizationLevel(connectionInfo);

      // Add query parameters for server-side processing
      url.searchParams.set('grid_aware_optimization', optimizationLevel);
      url.searchParams.set('grid_aware_connection', JSON.stringify({
          effective_type: connectionInfo.effective_type,
          downlink: connectionInfo.downlink,
          rtt: connectionInfo.rtt,
          save_data: connectionInfo.save_data
      }));

      return url.toString();
  }

  /**
   * Calculate optimization level based on connection info
   */
  function calculateOptimizationLevel(connectionInfo) {
      // Aggressive optimization for slow connections or data saver
      if (connectionInfo.save_data ||
          connectionInfo.effective_type === 'slow-2g' ||
          connectionInfo.effective_type === '2g' ||
          (connectionInfo.downlink && connectionInfo.downlink < 1.5)) {
          return 'aggressive';
      }

      // Medium optimization for 3G
      if (connectionInfo.effective_type === '3g' ||
          (connectionInfo.downlink && connectionInfo.downlink < 5)) {
          return 'medium';
      }

      // Minimal optimization for fast connections
      return 'minimal';
  }

  // Add keyboard handling for the alt text boxes
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' || e.key === ' ') { // Enter or Space key
        const target = e.target;
        if (target.classList.contains('grid-aware-alt-text-box')) {
            // Find and click the show image button
            const button = target.querySelector('.grid-aware-show-image');
            if (button) {
                e.preventDefault(); // Prevent page scroll on space
                button.click();
            }
        } else if (target.classList.contains('grid-aware-video-placeholder') ||
                   target.closest('.grid-aware-video-placeholder')) {
            // Find and click the load video button
            const button = target.classList.contains('grid-aware-load-video') ?
                target : target.querySelector('.grid-aware-load-video');

            if (button) {
                e.preventDefault();
                button.click();
            }
        }
    }
  });

  // Add focus management for better accessibility
  function makeElementsFocusable() {
      // Make alt text boxes keyboard focusable
      document.querySelectorAll('.grid-aware-alt-text-box').forEach(box => {
          if (!box.hasAttribute('tabindex')) {
              box.setAttribute('tabindex', '0');
          }

          // Set role for better screen reader support
          if (!box.hasAttribute('role')) {
              box.setAttribute('role', 'img');
          }

          // Add focus and blur handlers for visual indication
          box.addEventListener('focus', function() {
              this.classList.add('focus');
          });

          box.addEventListener('blur', function() {
              this.classList.remove('focus');
          });
      });

      // Make video placeholders keyboard focusable
      document.querySelectorAll('.grid-aware-video-placeholder').forEach(placeholder => {
          if (!placeholder.hasAttribute('tabindex')) {
              placeholder.setAttribute('tabindex', '0');
          }

          if (!placeholder.hasAttribute('role')) {
              placeholder.setAttribute('role', 'button');
          }

          if (!placeholder.hasAttribute('aria-label')) {
              placeholder.setAttribute('aria-label', 'Click to load video');
          }
      });
  }

  // Add role attributes for better screen reader experience
  function addRoleAttributes() {
      // Add roles for any missing elements
      document.querySelectorAll('.grid-aware-eco-info').forEach(info => {
          if (!info.hasAttribute('role')) {
              info.setAttribute('role', 'status');
          }
      });
  }

  // Process tiny images
  function processTinyImages() {
      if ('IntersectionObserver' in window) {
          const imageObserver = new IntersectionObserver((entries, observer) => {
              entries.forEach(entry => {
                  if (entry.isIntersecting) {
                      const img = entry.target;
                      const fullSrc = img.getAttribute('data-full-src');

                      if (fullSrc) {
                          // Create a new image to load the full version
                          const fullImg = new Image();
                          fullImg.onload = function() {
                              // Replace src with full version
                              img.src = fullSrc;
                              img.classList.add('loaded');

                              // Remove blur effect via aria attribute for screen readers
                              img.setAttribute('aria-busy', 'false');
                          };

                          // Set loading state
                          img.setAttribute('aria-busy', 'true');
                          fullImg.src = fullSrc;
                      }

                      // Stop observing after loading
                      observer.unobserve(img);
                  }
              });
          });

          // Observe all tiny images
          document.querySelectorAll('.grid-aware-tiny-image').forEach(img => {
              // Add appropriate attributes
              img.setAttribute('aria-busy', 'true');
              imageObserver.observe(img);
          });
      }
  }

  // Helper to announce messages to screen readers
  function announceToScreenReaders(message) {
      // Create or use existing live region
      let liveRegion = document.getElementById('grid-aware-live-region');

      if (!liveRegion) {
          liveRegion = document.createElement('div');
          liveRegion.id = 'grid-aware-live-region';
          liveRegion.className = 'screen-reader-text';
          liveRegion.setAttribute('aria-live', 'polite');
          liveRegion.setAttribute('aria-atomic', 'true');
          document.body.appendChild(liveRegion);
      }

      // Update the live region text
      liveRegion.textContent = message;
  }
})();