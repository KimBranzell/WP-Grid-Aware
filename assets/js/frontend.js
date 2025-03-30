/**
 * Grid-Aware Frontend Scripts
 */
(function() {
  // Wait for DOM to be loaded
  document.addEventListener('DOMContentLoaded', function() {
      console.log('Grid-Aware: Frontend script loaded');

      // Handle "Show Image" buttons in alt text boxes
      document.addEventListener('click', function(e) {
          if (e.target.classList.contains('grid-aware-show-image')) {
              const button = e.target;
              const box = button.closest('.grid-aware-alt-text-box');

              if (box) {
                  const src = button.getAttribute('data-src');
                  if (src) {
                      // Replace the box with the actual image
                      const img = document.createElement('img');
                      img.src = src;
                      img.alt = box.getAttribute('aria-label') || '';
                      img.className = box.className.replace('grid-aware-alt-text-box', '').trim();

                      // Add loading spinner while the image loads
                      button.innerHTML = 'Loading...';
                      button.disabled = true;

                      img.onload = function() {
                          box.parentNode.replaceChild(img, box);
                      };

                      img.onerror = function() {
                          button.innerHTML = 'Failed to load image';
                          setTimeout(function() {
                              button.innerHTML = 'Try again';
                              button.disabled = false;
                          }, 2000);
                      };
                  }
              }
          }
      });

      // code for video placeholders and tiny image handling here


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
        }
    }
  });

  // Add focus management
  const altTextBoxes = document.querySelectorAll('.grid-aware-alt-text-box');
  altTextBoxes.forEach(box => {
    // Add visual focus indicator
    box.addEventListener('focus', function() {
        this.style.boxShadow = '0 0 0 2px #0073aa';
    });

    box.addEventListener('blur', function() {
        this.style.boxShadow = '';
    });
  });
})();