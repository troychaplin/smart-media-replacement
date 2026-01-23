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
	function performReplacement(attachmentId, file, button, versionType, comment) {
		const formData = new FormData();
		formData.append('action', 'smart_media_replacement_file');
		formData.append('nonce', window.smartMediaReplacementData.nonce);
		formData.append('attachment_id', attachmentId);
		formData.append('replacement_file', file);
		formData.append('version_type', versionType || 'minor');
		formData.append('comment', comment || '');

		console.log('[SMR] Performing replacement:', {
			attachmentId,
			versionType,
			comment: comment?.substring(0, 50),
		});

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

		const button = this;

		// Show replacement modal with version and comment options
		showReplacementModal(attachmentId, button);
	}

	// Show the replacement modal with version type and comment fields
	function showReplacementModal(attachmentId, button) {
		// Get settings from localized data
		const revisionData = window.smrRevisionData || {};
		const requireComment = revisionData.requireComment || false;
		const defaultVersion = revisionData.defaultVersion || 'minor';
		const maxRevisions = window.smartMediaReplacementData?.maxRevisions || 10;

		// Get revision count from button data attribute
		const revisionCount = button ? parseInt(button.getAttribute('data-revision-count') || '0', 10) : 0;
		const isAtLimit = maxRevisions > 0 && revisionCount >= maxRevisions;

		// Create modal overlay
		const overlay = document.createElement('div');
		overlay.className = 'smr-modal-overlay';
		overlay.style.cssText =
			'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.7);z-index:100000;display:flex;align-items:center;justify-content:center;';

		// Create modal content
		const modal = document.createElement('div');
		modal.className = 'smr-replacement-modal';
		modal.style.cssText =
			'background:#fff;padding:24px;border-radius:4px;max-width:500px;width:90%;box-shadow:0 5px 20px rgba(0,0,0,0.3);';

		// Create warning message if at limit
		const warningHtml = isAtLimit
			? `<div style="background:#fcf0f1;border-left:4px solid #d63638;padding:12px;margin-bottom:16px;">
				<p style="margin:0;color:#1d2327;">
					<strong>${__('Notice:', 'smart-media-replacement')}</strong>
					${__('This item has reached the maximum revisions allowed. Adding an additional revision will cause the oldest revision to be deleted.', 'smart-media-replacement')}
				</p>
			</div>`
			: '';

		modal.innerHTML = `
			<h2 style="margin-top:0;margin-bottom:16px;">${__('Replace File', 'smart-media-replacement')}</h2>

			${warningHtml}

			<div style="margin-bottom:16px;">
				<label style="display:block;margin-bottom:8px;font-weight:600;">${__('Version Type', 'smart-media-replacement')}</label>
				<div style="display:flex;gap:16px;">
					<label style="display:flex;align-items:center;gap:4px;">
						<input type="radio" name="smr_version_type" value="minor" ${defaultVersion === 'minor' ? 'checked' : ''}>
						${__('Minor (1.0 → 1.1)', 'smart-media-replacement')}
					</label>
					<label style="display:flex;align-items:center;gap:4px;">
						<input type="radio" name="smr_version_type" value="major" ${defaultVersion === 'major' ? 'checked' : ''}>
						${__('Major (1.0 → 2.0)', 'smart-media-replacement')}
					</label>
				</div>
			</div>

			<div style="margin-bottom:16px;">
				<label for="smr_comment" style="display:block;margin-bottom:8px;font-weight:600;">
					${__('Comment', 'smart-media-replacement')}
					${requireComment ? '<span style="color:#d63638;">*</span>' : ''}
				</label>
				<textarea id="smr_comment" rows="3" style="width:100%;resize:vertical;" placeholder="${__('Describe the changes…', 'smart-media-replacement')}"></textarea>
				${requireComment ? `<p style="color:#666;font-size:12px;margin-top:4px;">${__('A comment is required.', 'smart-media-replacement')}</p>` : ''}
			</div>

			<div style="margin-bottom:16px;">
				<label style="display:block;margin-bottom:8px;font-weight:600;">${__('Select File', 'smart-media-replacement')}</label>
				<input type="file" id="smr_replacement_file" style="width:100%;">
			</div>

			<div style="display:flex;gap:8px;justify-content:flex-end;">
				<button type="button" class="button smr-cancel-btn">${__('Cancel', 'smart-media-replacement')}</button>
				<button type="button" class="button button-primary smr-upload-btn" disabled>${__('Upload & Replace', 'smart-media-replacement')}</button>
			</div>
		`;

		overlay.appendChild(modal);
		document.body.appendChild(overlay);

		// Get references to elements
		const fileInput = modal.querySelector('#smr_replacement_file');
		const commentInput = modal.querySelector('#smr_comment');
		const uploadBtn = modal.querySelector('.smr-upload-btn');
		const cancelBtn = modal.querySelector('.smr-cancel-btn');

		// Enable upload button when file is selected
		fileInput.addEventListener('change', function () {
			const hasFile = this.files.length > 0;
			const hasComment = !requireComment || commentInput.value.trim().length > 0;
			uploadBtn.disabled = !(hasFile && hasComment);
		});

		// Check comment requirement
		commentInput.addEventListener('input', function () {
			const hasFile = fileInput.files.length > 0;
			const hasComment = !requireComment || this.value.trim().length > 0;
			uploadBtn.disabled = !(hasFile && hasComment);
		});

		// Handle cancel
		cancelBtn.addEventListener('click', function () {
			overlay.remove();
		});

		// Handle overlay click to close
		overlay.addEventListener('click', function (e) {
			if (e.target === overlay) {
				overlay.remove();
			}
		});

		// Handle upload
		uploadBtn.addEventListener('click', function () {
			const file = fileInput.files[0];
			const versionType = modal.querySelector('input[name="smr_version_type"]:checked').value;
			const comment = commentInput.value.trim();

			if (!file) {
				return;
			}

			if (requireComment && !comment) {
				showErrorMessage(
					__('A comment is required when replacing files.', 'smart-media-replacement'),
					button
				);
				return;
			}

			// Show loading state
			uploadBtn.disabled = true;
			uploadBtn.textContent = __('Replacing…', 'smart-media-replacement');

			// Close modal
			overlay.remove();

			// Show loading on original button too
			if (button) {
				button.disabled = true;
				button.textContent = __('Replacing…', 'smart-media-replacement');
			}

			// Perform the replacement
			performReplacement(attachmentId, file, button, versionType, comment);
		});

		// Handle escape key
		document.addEventListener('keydown', function escHandler(e) {
			if (e.key === 'Escape') {
				overlay.remove();
				document.removeEventListener('keydown', escHandler);
			}
		});

		console.log('[SMR] Replacement modal opened for attachment:', attachmentId);
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

	// Initialize revision history functionality
	initRevisionHistory();
});

/**
 * Initialize revision history UI functionality.
 */
function initRevisionHistory() {
	// Handle restore button clicks
	document.addEventListener('click', function (e) {
		if (e.target.classList.contains('smr-restore-btn')) {
			handleRestoreClick(e.target);
		}
	});

	// Handle compare button clicks
	document.addEventListener('click', function (e) {
		if (e.target.classList.contains('smr-compare-btn')) {
			handleCompareClick(e.target);
		}
	});

	// Handle modal close
	document.addEventListener('click', function (e) {
		if (e.target.classList.contains('smr-modal-close')) {
			const modal = e.target.closest('.smr-modal');
			if (modal) {
				modal.style.display = 'none';
			}
		}
	});

	// Handle preview button clicks
	document.addEventListener('click', function (e) {
		if (e.target.classList.contains('smr-preview-btn')) {
			handlePreviewClick(e.target);
		}
	});

	// Handle View Revisions button clicks
	document.addEventListener('click', function (e) {
		if (
			e.target.classList.contains('smr-view-revisions-btn') ||
			e.target.closest('.smr-view-revisions-btn')
		) {
			e.preventDefault();
			const button = e.target.classList.contains('smr-view-revisions-btn')
				? e.target
				: e.target.closest('.smr-view-revisions-btn');
			handleViewRevisionsClick(button);
		}
	});

	// Initialize comparison selects
	const compareLeft = document.getElementById('smr-compare-left');
	const compareRight = document.getElementById('smr-compare-right');

	if (compareLeft && compareRight) {
		compareLeft.addEventListener('change', updateComparisonImages);
		compareRight.addEventListener('change', updateComparisonImages);
	}

	console.log('[SMR] Revision history initialized');
}

/**
 * Handle View Revisions button click.
 * @param button
 */
function handleViewRevisionsClick(button) {
	const attachmentId = button.getAttribute('data-attachment-id');
	if (!attachmentId) {
		return;
	}

	showRevisionsModal(attachmentId);
}

/**
 * Show the revisions modal with full revision history.
 * @param attachmentId
 */
function showRevisionsModal(attachmentId) {
	const revisionData = window.smrRevisionData || {};
	const ajaxUrl = revisionData.ajaxUrl || window.smartMediaReplacementData?.ajaxUrl;
	const nonce = revisionData.nonce;

	// Create modal overlay
	const overlay = document.createElement('div');
	overlay.className = 'smr-modal-overlay smr-revisions-modal-overlay';
	overlay.style.cssText =
		'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.7);z-index:100000;display:flex;align-items:center;justify-content:center;';

	// Create modal content
	const modal = document.createElement('div');
	modal.className = 'smr-revisions-modal';
	modal.style.cssText =
		'background:#fff;padding:24px;border-radius:4px;max-width:700px;width:90%;max-height:80vh;overflow:auto;box-shadow:0 5px 20px rgba(0,0,0,0.3);';

	modal.innerHTML = `
		<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
			<h2 style="margin:0;">${__('Revision History', 'smart-media-replacement')}</h2>
			<button type="button" class="smr-modal-close" style="background:none;border:none;font-size:24px;cursor:pointer;padding:0;line-height:1;">&times;</button>
		</div>
		<div class="smr-revisions-loading" style="text-align:center;padding:32px;">
			<span class="spinner is-active" style="float:none;"></span>
			<p>${__('Loading revisions...', 'smart-media-replacement')}</p>
		</div>
		<div class="smr-revisions-content" style="display:none;"></div>
	`;

	overlay.appendChild(modal);
	document.body.appendChild(overlay);

	// Close on X button click
	const closeBtn = modal.querySelector('.smr-modal-close');
	if (closeBtn) {
		closeBtn.addEventListener('click', function () {
			overlay.remove();
		});
	}

	// Close on overlay click
	overlay.addEventListener('click', function (e) {
		if (e.target === overlay) {
			overlay.remove();
		}
	});

	// Close on escape key
	const escHandler = function (e) {
		if (e.key === 'Escape') {
			overlay.remove();
			document.removeEventListener('keydown', escHandler);
		}
	};
	document.addEventListener('keydown', escHandler);

	// Fetch revisions via AJAX
	const formData = new FormData();
	formData.append('action', 'smr_get_revisions');
	formData.append('nonce', nonce);
	formData.append('attachment_id', attachmentId);

	fetch(ajaxUrl, {
		method: 'POST',
		body: formData,
		credentials: 'same-origin',
	})
		.then(response => response.json())
		.then(data => {
			const loadingEl = modal.querySelector('.smr-revisions-loading');
			const contentEl = modal.querySelector('.smr-revisions-content');

			if (data.success) {
				loadingEl.style.display = 'none';
				contentEl.style.display = 'block';
				contentEl.innerHTML = renderRevisionsContent(data.data, attachmentId);

				// Add event listeners to buttons in the modal
				contentEl.querySelectorAll('.smr-restore-btn').forEach(btn => {
					btn.addEventListener('click', function () {
						handleRestoreClick(this);
					});
				});
			} else {
				loadingEl.innerHTML = `<p style="color:#d63638;">${data.data || __('Error loading revisions.', 'smart-media-replacement')}</p>`;
			}
		})
		.catch(error => {
			console.error('[SMR] Error fetching revisions:', error);
			const loadingEl = modal.querySelector('.smr-revisions-loading');
			loadingEl.innerHTML = `<p style="color:#d63638;">${__('Error loading revisions.', 'smart-media-replacement')}</p>`;
		});

	console.log('[SMR] Revisions modal opened for attachment:', attachmentId);
}

/**
 * Render the revisions content HTML.
 * @param data
 * @param attachmentId
 */
function renderRevisionsContent(data, attachmentId) {
	const revisions = data.revisions || [];
	const totalStorage = data.total_storage || '0 B';
	const count = data.count || 0;
	const revisionData = window.smrRevisionData || {};
	const downloadNonce = revisionData.downloadNonce;
	const ajaxUrl = revisionData.ajaxUrl || window.smartMediaReplacementData?.ajaxUrl;

	if (revisions.length === 0) {
		return `
			<div style="text-align:center;padding:32px;color:#666;">
				<p>${__('No revisions yet. Revisions are created when you replace the file.', 'smart-media-replacement')}</p>
			</div>
		`;
	}

	let html = `
		<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;padding-bottom:16px;border-bottom:1px solid #ddd;">
			<div>
				<strong>${__('Total Revisions:', 'smart-media-replacement')}</strong> ${count}
				&nbsp;&nbsp;|&nbsp;&nbsp;
				<strong>${__('Storage Used:', 'smart-media-replacement')}</strong> ${totalStorage}
			</div>
			<a href="${ajaxUrl}?action=smr_download_all_revisions&attachment_id=${attachmentId}&nonce=${downloadNonce}" class="button">
				${__('Download All', 'smart-media-replacement')}
			</a>
		</div>
		<div class="smr-revisions-list">
	`;

	revisions.forEach((revision, index) => {
		const isLatest = index === 0;
		html += `
			<div class="smr-revision-item" style="display:flex;justify-content:space-between;align-items:flex-start;padding:12px;margin-bottom:8px;background:${isLatest ? '#f0f6fc' : '#f9f9f9'};border-radius:4px;border:1px solid ${isLatest ? '#2271b1' : '#ddd'};">
				<div style="flex:1;">
					<div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
						<strong style="font-size:14px;">v${revision.version}</strong>
						${isLatest ? `<span style="background:#2271b1;color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;">${__('Latest', 'smart-media-replacement')}</span>` : ''}
					</div>
					<div style="color:#666;font-size:12px;">
						${revision.created_at} &bull; ${revision.user_name} &bull; ${revision.file_size}
					</div>
					${revision.comment ? `<div style="margin-top:4px;font-style:italic;color:#555;">"${revision.comment}"</div>` : ''}
				</div>
				<div style="display:flex;gap:8px;">
					<a href="${ajaxUrl}?action=smr_download_revision&revision_id=${revision.id}&nonce=${downloadNonce}" class="button button-small">
						${__('Download', 'smart-media-replacement')}
					</a>
					<button type="button" class="button button-small smr-restore-btn" data-revision-id="${revision.id}" data-version="${revision.version}">
						${__('Restore', 'smart-media-replacement')}
					</button>
				</div>
			</div>
		`;
	});

	html += '</div>';
	return html;
}

/**
 * Handle restore button click.
 * @param button
 */
function handleRestoreClick(button) {
	const revisionData = window.smrRevisionData || {};
	const strings = revisionData.strings || {};

	if (!confirm(strings.confirmRestore || 'Are you sure you want to restore this revision?')) {
		return;
	}

	const revisionId = button.getAttribute('data-revision-id');
	const version = button.getAttribute('data-version');

	button.disabled = true;
	button.textContent = strings.restoring || 'Restoring...';

	const formData = new FormData();
	formData.append('action', 'smr_restore_revision');
	formData.append('nonce', revisionData.nonce);
	formData.append('revision_id', revisionId);
	formData.append('comment', 'Restored from v' + version);

	fetch(revisionData.ajaxUrl || window.smartMediaReplacementData.ajaxUrl, {
		method: 'POST',
		body: formData,
		credentials: 'same-origin',
	})
		.then(response => response.json())
		.then(data => {
			if (data.success) {
				// Show success and reload
				alert(strings.restoreSuccess || 'Revision restored successfully. Refreshing...');
				window.location.reload();
			} else {
				alert(data.data || 'Error restoring revision.');
				button.disabled = false;
				button.textContent = 'Restore';
			}
		})
		.catch(error => {
			console.error('[SMR] Restore error:', error);
			alert('Error restoring revision: ' + error.message);
			button.disabled = false;
			button.textContent = 'Restore';
		});
}

/**
 * Handle compare button click.
 * @param button
 */
function handleCompareClick(button) {
	const modal = document.getElementById('smr-comparison-modal');
	if (modal) {
		modal.style.display = 'flex';
		updateComparisonImages();
		console.log('[SMR] Comparison modal opened');
	}
}

/**
 * Handle preview button click.
 * @param button
 */
function handlePreviewClick(button) {
	const revisionData = window.smrRevisionData || {};
	const filePath = button.getAttribute('data-file-path');

	if (!filePath) {
		return;
	}

	// Get base URL for uploads
	const uploadUrl = window.smrUploadUrl || '/wp-content/uploads/';
	const imageUrl = uploadUrl + filePath;

	// Create a simple preview modal
	const overlay = document.createElement('div');
	overlay.style.cssText =
		'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.9);z-index:100000;display:flex;align-items:center;justify-content:center;cursor:pointer;';

	const img = document.createElement('img');
	img.src = imageUrl;
	img.style.cssText = 'max-width:90%;max-height:90%;object-fit:contain;';

	overlay.appendChild(img);
	document.body.appendChild(overlay);

	overlay.addEventListener('click', function () {
		overlay.remove();
	});

	console.log('[SMR] Preview opened:', imageUrl);
}

/**
 * Update comparison images based on selected versions.
 */
function updateComparisonImages() {
	const leftSelect = document.getElementById('smr-compare-left');
	const rightSelect = document.getElementById('smr-compare-right');

	if (!leftSelect || !rightSelect) {
		return;
	}

	const leftOption = leftSelect.options[leftSelect.selectedIndex];
	const rightOption = rightSelect.options[rightSelect.selectedIndex];

	const leftPath = leftOption.getAttribute('data-file-path');
	const rightPath = rightOption.getAttribute('data-file-path');

	const leftImg = document.querySelector('.smr-comparison-left img');
	const rightImg = document.querySelector('.smr-comparison-right img');
	const leftLabel = document.querySelector('.smr-comparison-left .smr-comparison-label');
	const rightLabel = document.querySelector('.smr-comparison-right .smr-comparison-label');

	if (leftImg && leftPath) {
		// Check if it's a full URL (current) or relative path (revision)
		leftImg.src = leftPath.startsWith('http')
			? leftPath
			: (window.smrUploadUrl || '/wp-content/uploads/') + leftPath;
	}

	if (rightImg && rightPath) {
		rightImg.src = rightPath.startsWith('http')
			? rightPath
			: (window.smrUploadUrl || '/wp-content/uploads/') + rightPath;
	}

	if (leftLabel) {
		leftLabel.textContent = leftOption.textContent.trim();
	}

	if (rightLabel) {
		rightLabel.textContent = rightOption.textContent.trim();
	}

	console.log('[SMR] Comparison images updated:', { left: leftPath, right: rightPath });
}
