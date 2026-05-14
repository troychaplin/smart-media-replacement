/**
 * Media Library Replacement Functionality
 *
 * Handles the file replacement functionality in the WordPress Media Library.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

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

		// Skip the modal entirely when no revision will be created — either
		// the global setting is off or the per-attachment file type filter
		// excludes this attachment. PHP sets data-revisions-enabled on the
		// button to "1" / "0" so the JS can distinguish.
		const revisionData = window.smrRevisionData || {};
		const globalEnableRevisions = revisionData.enableRevisions !== false;
		const attachmentRevisionsEnabled = button.getAttribute('data-revisions-enabled') === '1';
		const showModal = globalEnableRevisions && attachmentRevisionsEnabled;

		if (showModal) {
			showReplacementModal(attachmentId, button);
		} else {
			openDirectFilePicker(attachmentId, button);
		}
	}

	// Direct file-picker flow used when no revision metadata is collected.
	// Restores the pre-revision UX: click → native file picker → replace.
	function openDirectFilePicker(attachmentId, button) {
		const fileInput = document.createElement('input');
		fileInput.type = 'file';
		fileInput.style.display = 'none';
		document.body.appendChild(fileInput);

		fileInput.addEventListener('change', function () {
			if (this.files.length === 0) {
				document.body.removeChild(fileInput);
				return;
			}

			const selectedFile = this.files[0];

			if (button) {
				button.disabled = true;
				button.textContent = __('Replacing…', 'smart-media-replacement');
			}

			performReplacement(attachmentId, selectedFile, button, 'minor', '');
			document.body.removeChild(fileInput);
		});

		fileInput.click();
	}

	// Show the replacement modal with version type and comment fields.
	// Only called when revisions WILL be created — the per-attachment check
	// happens in handleReplaceClick before this is invoked.
	function showReplacementModal(attachmentId, button) {
		const revisionData = window.smrRevisionData || {};
		const requireComment = revisionData.requireComment || false;
		const defaultVersion = revisionData.defaultVersion || 'minor';
		const maxRevisions = window.smartMediaReplacementData?.maxRevisions || 10;

		const revisionCount = button
			? parseInt(button.getAttribute('data-revision-count') || '0', 10)
			: 0;
		const isAtLimit = maxRevisions > 0 && revisionCount >= maxRevisions;

		// Pre-computed version strings from PHP. Empty latestVersion means no
		// prior revisions exist — both major/minor produce v1.0 for the first
		// replacement, so we render an info note instead of a meaningless choice.
		const latestVersion = button ? button.getAttribute('data-latest-version') || '' : '';
		const nextMinor = button ? button.getAttribute('data-next-minor') || '1.0' : '1.0';
		const nextMajor = button ? button.getAttribute('data-next-major') || '1.0' : '1.0';

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

		// Version type UI: hide the choice entirely on the first revision (no
		// meaningful difference between major/minor), otherwise show two radios
		// with labels derived from the attachment's actual latest version.
		let versionTypeHtml;
		if (!latestVersion) {
			versionTypeHtml = `<div style="margin-bottom:16px;">
				<p style="margin:0;color:#646970;">${sprintf(
					/* translators: %s: the version string that will be assigned, e.g. "1.0" */
					__('First revision will be saved as v%s.', 'smart-media-replacement'),
					nextMinor
				)}</p>
				<input type="hidden" name="smr_version_type" value="minor">
			</div>`;
		} else {
			const minorLabel = sprintf(
				/* translators: 1: current version, 2: next minor version */
				__('Minor (v%1$s → v%2$s)', 'smart-media-replacement'),
				latestVersion,
				nextMinor
			);
			const majorLabel = sprintf(
				/* translators: 1: current version, 2: next major version */
				__('Major (v%1$s → v%2$s)', 'smart-media-replacement'),
				latestVersion,
				nextMajor
			);
			versionTypeHtml = `<div style="margin-bottom:16px;">
				<label style="display:block;margin-bottom:8px;font-weight:600;">${__('Version Type', 'smart-media-replacement')}</label>
				<div style="display:flex;gap:16px;">
					<label style="display:flex;align-items:center;gap:4px;">
						<input type="radio" name="smr_version_type" value="minor" ${defaultVersion === 'minor' ? 'checked' : ''}>
						${minorLabel}
					</label>
					<label style="display:flex;align-items:center;gap:4px;">
						<input type="radio" name="smr_version_type" value="major" ${defaultVersion === 'major' ? 'checked' : ''}>
						${majorLabel}
					</label>
				</div>
			</div>`;
		}

		const commentHtml = `<div style="margin-bottom:16px;">
			<label for="smr_comment" style="display:block;margin-bottom:8px;font-weight:600;">
				${__('Replacement note', 'smart-media-replacement')}
				${requireComment ? '<span style="color:#d63638;">*</span>' : ''}
			</label>
			<textarea id="smr_comment" rows="3" style="width:100%;resize:vertical;" placeholder="${__('Describe the changes…', 'smart-media-replacement')}"></textarea>
			${requireComment ? `<p style="color:#666;font-size:12px;margin-top:4px;">${__('A comment is required.', 'smart-media-replacement')}</p>` : ''}
		</div>`;

		modal.innerHTML = `
			<h2 style="margin-top:0;margin-bottom:16px;">${__('Replace File', 'smart-media-replacement')}</h2>

			${warningHtml}

			${versionTypeHtml}

			${commentHtml}

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
			const hasComment =
				!requireComment || (commentInput && commentInput.value.trim().length > 0);
			uploadBtn.disabled = !(hasFile && hasComment);
		});

		// Check comment requirement (only if comment field exists)
		if (commentInput) {
			commentInput.addEventListener('input', function () {
				const hasFile = fileInput.files.length > 0;
				const hasComment = !requireComment || this.value.trim().length > 0;
				uploadBtn.disabled = !(hasFile && hasComment);
			});
		}

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
			if (!file) {
				return;
			}

			// Handles both the radio-button case (uses :checked) and the
			// first-revision hidden-input case (no checked state).
			const versionInput =
				modal.querySelector('input[name="smr_version_type"]:checked') ||
				modal.querySelector('input[name="smr_version_type"]');
			const versionType = versionInput ? versionInput.value : 'minor';
			const comment = commentInput ? commentInput.value.trim() : '';

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
			handleCompareClick();
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
}

/**
 * Handle View Revisions button click.
 *
 * @param {HTMLElement} button The button element that was clicked.
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
 *
 * @param {string} attachmentId The attachment ID.
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
			<p>${__('Loading revisions…', 'smart-media-replacement')}</p>
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
				// Restore button clicks are handled by the document-level
				// delegated listener registered in initRevisionHistory; no
				// per-button listener needed here. Adding one here would
				// cause every click to fire handleRestoreClick twice.
			} else {
				loadingEl.innerHTML = `<p style="color:#d63638;">${data.data || __('Error loading revisions.', 'smart-media-replacement')}</p>`;
			}
		})
		.catch(() => {
			const loadingEl = modal.querySelector('.smr-revisions-loading');
			loadingEl.innerHTML = `<p style="color:#d63638;">${__('Error loading revisions.', 'smart-media-replacement')}</p>`;
		});
}

/**
 * Render the revisions content HTML.
 *
 * @param {Object} data         The revision data from AJAX.
 * @param {string} attachmentId The attachment ID.
 * @return {string} HTML content for the revisions list.
 */
function renderRevisionsContent(data, attachmentId) {
	const revisions = data.revisions || [];
	const currentFile = data.current_file || null;
	const totalStorage = data.total_storage || '0 B';
	const count = data.count || 0;
	const revisionData = window.smrRevisionData || {};
	const downloadNonce = revisionData.downloadNonce;
	const ajaxUrl = revisionData.ajaxUrl || window.smartMediaReplacementData?.ajaxUrl;

	let html = '';

	// Replacement notes are stored on the retired snapshot in the DB, but
	// they describe the NEW file that took over in that event. So at display
	// time we shift each comment forward by one row — the note attached to
	// revisions[i] (the version that was retired) is shown on the version
	// that replaced it. The most recent comment moves all the way up to the
	// Current file row; the oldest revision ends up with no note because no
	// replacement event introduced it (it was the original upload).
	const currentFileNote = revisions.length > 0 ? revisions[0].comment : '';

	// Current live file always appears at the top — it represents what is
	// actually on disk right now, distinct from the snapshots below which
	// are historical only.
	if (currentFile) {
		html += renderCurrentFileEntry(currentFile, currentFileNote);
	}

	if (revisions.length === 0) {
		html += `
			<p style="text-align:center;padding:24px;color:#666;">
				${__('No replacement history yet. Each time you replace this file, the previous version is preserved here.', 'smart-media-replacement')}
			</p>
		`;
		return html;
	}

	// Replacement history section — divider with stats and Download All
	html += `
		<div style="display:flex;justify-content:space-between;align-items:center;margin:24px 0 12px;padding-bottom:8px;border-bottom:1px solid #ddd;">
			<h3 style="margin:0;font-size:14px;">${__('Replacement history', 'smart-media-replacement')}</h3>
			<div style="display:flex;align-items:center;gap:12px;">
				<span style="color:#646970;font-size:12px;">${count} • ${totalStorage}</span>
				<a href="${ajaxUrl}?action=smr_download_all_revisions&attachment_id=${attachmentId}&nonce=${downloadNonce}" class="button button-small">
					${__('Download all', 'smart-media-replacement')}
				</a>
			</div>
		</div>
		<div class="smr-revisions-list">
	`;

	revisions.forEach((revision, index) => {
		// Display the note from the next-OLDER revision — that comment was
		// made when this version was introduced. The oldest revision is the
		// original upload, so it has no introducing event and no note.
		const displayedNote = index < revisions.length - 1 ? revisions[index + 1].comment : '';

		html += `
			<div class="smr-revision-item" style="display:flex;justify-content:space-between;align-items:flex-start;padding:12px;margin-bottom:8px;background:#f9f9f9;border-radius:4px;border:1px solid #ddd;">
				<div style="flex:1;">
					<div style="margin-bottom:4px;">
						<strong style="font-size:14px;">v${revision.version}</strong>
					</div>
					<div style="color:#666;font-size:12px;">
						${revision.created_at} &bull; ${revision.user_name} &bull; ${revision.file_size}
					</div>
					${
						displayedNote
							? `<div style="margin-top:4px;color:#555;"><strong>${__('Replacement note:', 'smart-media-replacement')}</strong> <em>${displayedNote}</em></div>`
							: ''
					}
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
 * Render the "Current file" entry shown at the top of the revisions modal.
 * Represents the live attachment file (not stored in the revisions table)
 * so users can see at a glance what is currently active vs. what is history.
 *
 * @param {Object} currentFile File info from the AJAX response.
 * @param {string} note        Replacement note describing how this file
 *                             became current (the most recent event's note).
 *                             Empty string when no replacements have happened.
 * @return {string} HTML for the current-file panel.
 */
function renderCurrentFileEntry(currentFile, note) {
	const downloadAttr = currentFile.filename ? ` download="${currentFile.filename}"` : '';
	return `
		<div class="smr-current-file" style="display:flex;justify-content:space-between;align-items:flex-start;padding:12px;margin-bottom:8px;background:#f0f6fc;border-radius:4px;border:1px solid #2271b1;">
			<div style="flex:1;">
				<div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
					<strong style="font-size:14px;">${__('Current file', 'smart-media-replacement')}</strong>
					<span style="background:#2271b1;color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;">${__('Live', 'smart-media-replacement')}</span>
				</div>
				<div style="color:#666;font-size:12px;">
					${currentFile.filename} &bull; ${currentFile.file_size}
				</div>
				<div style="color:#666;font-size:12px;margin-top:2px;">
					${sprintf(
						/* translators: %s: timestamp when the current file took its place */
						__('Active since %s', 'smart-media-replacement'),
						currentFile.active_since
					)}
				</div>
				${
					note
						? `<div style="margin-top:4px;color:#555;font-size:13px;"><strong>${__('Replacement note:', 'smart-media-replacement')}</strong> <em>${note}</em></div>`
						: ''
				}
			</div>
			<div style="display:flex;gap:8px;">
				<a href="${currentFile.url}"${downloadAttr} class="button button-small" target="_blank" rel="noopener noreferrer">
					${__('Download', 'smart-media-replacement')}
				</a>
			</div>
		</div>
	`;
}

/**
 * Handle restore button click.
 *
 * @param {HTMLElement} button The restore button element.
 */
function handleRestoreClick(button) {
	const revisionData = window.smrRevisionData || {};
	const strings = revisionData.strings || {};

	if (
		// eslint-disable-next-line no-alert
		!window.confirm(strings.confirmRestore || 'Are you sure you want to restore this revision?')
	) {
		return;
	}

	const revisionId = button.getAttribute('data-revision-id');
	const version = button.getAttribute('data-version');

	button.disabled = true;
	button.textContent = strings.restoring || __('Restoring…', 'smart-media-replacement');

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
				// Show an inline success notice in the modal so the user
				// sees confirmation, then reload — the reload is needed so
				// thumbnails, the media library row, and the file preview
				// pick up the restored file content.
				showRestoreNotice(
					'success',
					sprintf(
						/* translators: %s: the version that was restored, e.g. "1.2" */
						__('Revision v%s restored. Refreshing…', 'smart-media-replacement'),
						version
					)
				);
				setTimeout(() => window.location.reload(), 1200);
			} else {
				showRestoreNotice(
					'error',
					data.data || __('Error restoring revision.', 'smart-media-replacement')
				);
				button.disabled = false;
				button.textContent = __('Restore', 'smart-media-replacement');
			}
		})
		.catch(error => {
			showRestoreNotice(
				'error',
				__('Error restoring revision:', 'smart-media-replacement') + ' ' + error.message
			);
			button.disabled = false;
			button.textContent = __('Restore', 'smart-media-replacement');
		});
}

/**
 * Insert a success or error notice at the top of the open revisions modal.
 * Replaces any prior notice so repeated attempts don't stack.
 *
 * @param {string} type    Either "success" or "error".
 * @param {string} message Message text — may contain inline HTML.
 */
function showRestoreNotice(type, message) {
	const modal = document.querySelector('.smr-revisions-modal');
	if (!modal) {
		return;
	}

	const existing = modal.querySelector('.smr-notice');
	if (existing) {
		existing.remove();
	}

	const isSuccess = type === 'success';
	const notice = document.createElement('div');
	notice.className = 'smr-notice';
	notice.style.cssText =
		'padding:12px 16px;margin-bottom:16px;color:#1d2327;border-left:4px solid ' +
		(isSuccess ? '#00a32a' : '#d63638') +
		';background:' +
		(isSuccess ? '#edfaef' : '#fcf0f1') +
		';';

	const prefix = isSuccess
		? '<strong style="color:#00a32a;">&#10003;</strong> '
		: '<strong style="color:#d63638;">' +
			__('Error:', 'smart-media-replacement') +
			'</strong> ';
	notice.innerHTML = prefix + message;

	// Place under the modal's header row so the title stays visible.
	const header = modal.firstElementChild;
	if (header) {
		header.insertAdjacentElement('afterend', notice);
	} else {
		modal.insertBefore(notice, modal.firstChild);
	}
}

/**
 * Handle compare button click.
 */
function handleCompareClick() {
	const modal = document.getElementById('smr-comparison-modal');
	if (modal) {
		modal.style.display = 'flex';
		updateComparisonImages();
	}
}

/**
 * Handle preview button click.
 *
 * @param {HTMLElement} button The preview button element.
 */
function handlePreviewClick(button) {
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
}
