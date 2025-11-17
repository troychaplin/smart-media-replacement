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
	// Check if smartMediaReplacementData is available
	if (typeof window.smartMediaReplacementData === 'undefined') {
		// Silently fail if data is not available
		return;
	}

	// Function to show inline error message in table row
	function showInlineErrorMessage(button, message) {
		// Find the table row
		const tableRow = button.closest('tr');
		if (!tableRow) {
			return false;
		}

		// Remove any existing inline error for this row (check next sibling)
		const nextRow = tableRow.nextElementSibling;
		if (nextRow && nextRow.classList.contains('smart-media-replacement-inline-error')) {
			nextRow.remove();
		}

		// Create a new row for the error message
		const errorRow = document.createElement('tr');
		errorRow.className = 'smart-media-replacement-inline-error';

		// Get the number of columns in the table
		const columnCount = tableRow.querySelectorAll('td, th').length;

		// Create a cell that spans all columns
		const errorCell = document.createElement('td');
		errorCell.colSpan = columnCount;
		errorCell.style.padding = '8px 12px';
		errorCell.style.backgroundColor = '#fcf0f1';
		errorCell.style.borderLeft = '4px solid #d63638';

		// Create the error message content
		const errorContent = document.createElement('div');
		errorContent.style.display = 'flex';
		errorContent.style.alignItems = 'center';
		errorContent.style.justifyContent = 'space-between';

		const errorText = document.createElement('span');
		errorText.innerHTML =
			'<strong style="color: #d63638;">' +
			__('Error:', 'smart-media-replacement') +
			'</strong> ' +
			message;
		errorContent.appendChild(errorText);

		// Create dismiss button
		const dismissButton = document.createElement('button');
		dismissButton.type = 'button';
		dismissButton.className = 'button-link';
		dismissButton.style.color = '#d63638';
		dismissButton.style.textDecoration = 'none';
		dismissButton.style.cursor = 'pointer';
		dismissButton.textContent = __('Dismiss', 'smart-media-replacement');
		dismissButton.addEventListener('click', function () {
			errorRow.remove();
		});
		errorContent.appendChild(dismissButton);

		errorCell.appendChild(errorContent);
		errorRow.appendChild(errorCell);

		// Insert the error row after the current row
		tableRow.parentNode.insertBefore(errorRow, tableRow.nextSibling);

		// Auto-dismiss after 10 seconds
		setTimeout(function () {
			if (errorRow.parentNode) {
				errorRow.style.transition = 'opacity 0.3s';
				errorRow.style.opacity = '0';
				setTimeout(function () {
					errorRow.remove();
				}, 300);
			}
		}, 10000);

		// Scroll to the error row
		errorRow.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

		return true;
	}

	// Function to show error messages
	function showErrorMessage(message, button) {
		// Try to show inline error if button is in a table row
		if (button && showInlineErrorMessage(button, message)) {
			return;
		}

		// Fall back to top-of-page notice
		const noticeId = 'smart-media-replacement-error-notice';

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
		formData.append('action', 'smart_media_replacement_file');
		formData.append('nonce', window.smartMediaReplacementData.nonce);
		formData.append('attachment_id', attachmentId);
		formData.append('replacement_file', file);

		// Send AJAX request
		fetch(window.smartMediaReplacementData.ajaxUrl, {
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
					showErrorMessage(errorMessage, button);
					if (button) {
						button.disabled = false;
						button.textContent = __('Replace File', 'smart-media-replacement');
					}
				}
			})
			.catch(error => {
				showErrorMessage(
					__('Error replacing file:', 'smart-media-replacement') + ' ' + error.message,
					button
				);
				if (button) {
					button.disabled = false;
					button.textContent = __('Replace File', 'smart-media-replacement');
				}
			});
	}

	// Function to initialize replace buttons
	function initReplaceButtons() {
		const replaceButtons = document.querySelectorAll('.smart-media-replacement-button');

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
