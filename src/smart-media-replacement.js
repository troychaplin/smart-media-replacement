/**
 * Media Library Replacement Functionality
 *
 * Handles the file replacement functionality in the WordPress Media Library.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Handle the media replacement functionality.
 */
document.addEventListener('DOMContentLoaded', function () {
	// Check if replaceMediaData is available
	if (typeof window.replaceMediaData === 'undefined') {
		// Silently fail if data is not available
		return;
	}

	// Function to show error messages
	function showErrorMessage(message) {
		// Create a WordPress-style admin notice
		const noticeId = 'replace-media-error-notice';

		// Remove any existing error notices
		const existingNotice = document.getElementById(noticeId);
		if (existingNotice) {
			existingNotice.remove();
		}

		// Create the notice element
		const notice = document.createElement('div');
		notice.id = noticeId;
		notice.className = 'notice notice-error is-dismissible';
		notice.style.marginTop = '20px';
		notice.style.marginBottom = '20px';

		// Create the paragraph element
		const paragraph = document.createElement('p');
		paragraph.innerHTML =
			'<strong>' +
			__('Media Replacement Error:', 'smart-media-replacement') +
			'</strong> ' +
			message;
		notice.appendChild(paragraph);

		// Create the dismiss button
		const dismissButton = document.createElement('button');
		dismissButton.type = 'button';
		dismissButton.className = 'notice-dismiss';
		dismissButton.innerHTML =
			'<span class="screen-reader-text">' +
			__('Dismiss this notice.', 'smart-media-replacement') +
			'</span>';
		dismissButton.addEventListener('click', function () {
			notice.remove();
		});
		notice.appendChild(dismissButton);

		// Try to find the notices wrapper first
		const noticesWrapper =
			document.querySelector('.wrap h1') ||
			document.querySelector('.wrap h2') ||
			document.querySelector('#wpbody-content .wrap');

		if (noticesWrapper) {
			// Insert after the heading
			if (noticesWrapper.tagName === 'H1' || noticesWrapper.tagName === 'H2') {
				noticesWrapper.parentNode.insertBefore(notice, noticesWrapper.nextSibling);
			} else {
				// Insert as first child of .wrap
				noticesWrapper.insertBefore(notice, noticesWrapper.firstChild);
			}
		} else {
			// Fallback to inserting at the top of wpbody-content
			const wpbodyContent = document.getElementById('wpbody-content');
			if (wpbodyContent) {
				wpbodyContent.insertBefore(notice, wpbodyContent.firstChild);
			}
		}

		// Auto-dismiss after 10 seconds
		setTimeout(function () {
			if (notice.parentNode) {
				notice.style.transition = 'opacity 0.3s';
				notice.style.opacity = '0';
				setTimeout(function () {
					notice.remove();
				}, 300);
			}
		}, 10000);

		// Scroll to the notice so it's visible
		notice.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
	}

	// Function to perform the actual replacement
	function performReplacement(attachmentId, file, button) {
		const formData = new FormData();
		formData.append('action', 'replace_media_file');
		formData.append('nonce', window.replaceMediaData.nonce);
		formData.append('attachment_id', attachmentId);
		formData.append('replacement_file', file);

		// Send AJAX request
		fetch(window.replaceMediaData.ajaxUrl, {
			method: 'POST',
			body: formData,
			credentials: 'same-origin',
		})
			.then(response => {
				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`);
				}
				return response.json();
			})
			.then(data => {
				if (data.success) {
					// Refresh the media library
					window.location.reload();
				} else {
					const errorMessage =
						data.data || __('Error replacing file.', 'smart-media-replacement');
					showErrorMessage(errorMessage);
					if (button) {
						button.disabled = false;
						button.textContent = __('Replace File', 'smart-media-replacement');
					}
				}
			})
			.catch(error => {
				showErrorMessage(
					__('Error replacing file:', 'smart-media-replacement') + ' ' + error.message
				);
				if (button) {
					button.disabled = false;
					button.textContent = __('Replace File', 'smart-media-replacement');
				}
			});
	}

	// Function to initialize replace buttons
	function initReplaceButtons() {
		const replaceButtons = document.querySelectorAll('.replace-media-button');

		replaceButtons.forEach(button => {
			// Remove any existing click handlers
			button.removeEventListener('click', handleReplaceClick);
			// Add new click handler
			button.addEventListener('click', handleReplaceClick);
		});
	}

	// Handle replace button click
	function handleReplaceClick(e) {
		e.preventDefault();

		const attachmentId = this.getAttribute('data-attachment-id');
		if (!attachmentId) {
			return;
		}

		// Create file input
		const fileInput = document.createElement('input');
		fileInput.type = 'file';
		fileInput.style.display = 'none';
		document.body.appendChild(fileInput);

		const button = this;

		fileInput.addEventListener('change', function () {
			if (this.files.length === 0) {
				document.body.removeChild(fileInput);
				return;
			}

			const selectedFile = this.files[0];

			// Show loading state
			button.disabled = true;
			button.textContent = __('Replacing…', 'smart-media-replacement');

			// Perform the replacement directly (strict dimension checking is now server-side)
			performReplacement(attachmentId, selectedFile, button);

			// Clean up file input
			document.body.removeChild(fileInput);
		});

		fileInput.click();
	}

	// Initialize buttons on page load
	initReplaceButtons();

	// Re-initialize buttons when attachment details are shown in the media modal
	// This handles the modal opening and attachment detail views
	if (typeof wp !== 'undefined' && wp.media) {
		const originalMediaView = wp.media.view.Attachment.Details;
		if (originalMediaView) {
			wp.media.view.Attachment.Details = originalMediaView.extend({
				render() {
					originalMediaView.prototype.render.apply(this, arguments);
					// Re-initialize buttons after the view renders
					setTimeout(initReplaceButtons, 100);
					return this;
				},
			});
		}
	}
});
