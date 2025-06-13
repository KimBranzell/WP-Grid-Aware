/**
 * Grid-Aware Frontend Scripts
 * Enhanced for accessibility
 */
(function() {
  // Wait for DOM to be loaded
  document.addEventListener('DOMContentLoaded', function() {
      console.log('Grid-Aware: Frontend script loaded');

      // Add skip link to main content for keyboard users
      addSkipLink();

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

  // Add skip link for keyboard users
  function addSkipLink() {
      // Check if main content exists
      const mainContent = document.querySelector('main, #content, #main, [role="main"]');

      if (mainContent && !document.querySelector('.grid-aware-skip-link')) {
          const skipLink = document.createElement('a');
          skipLink.className = 'grid-aware-skip-link';
          skipLink.href = '#';
          skipLink.textContent = 'Skip to grid-aware content';

          skipLink.addEventListener('click', function(e) {
              e.preventDefault();

              // Find the first grid-aware element
              const firstGridAwareElement = document.querySelector('.grid-aware-alt-text-box, .grid-aware-video-placeholder');

              if (firstGridAwareElement) {
                  firstGridAwareElement.setAttribute('tabindex', '-1');
                  firstGridAwareElement.focus();
              } else {
                  mainContent.setAttribute('tabindex', '-1');
                  mainContent.focus();
              }
          });

          document.body.insertBefore(skipLink, document.body.firstChild);
      }
  }
})();