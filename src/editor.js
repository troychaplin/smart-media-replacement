/**
 * Block editor integration for Smart Media Replacement.
 *
 * Hooks into Gutenberg's MediaReplaceFlow component (the dropdown behind every
 * block's "Replace" toolbar button) and adds an "Update Existing File" menu
 * item. Unlike core's options — which all swap to a different attachment —
 * ours updates the file content of the SAME attachment, preserving its URL.
 *
 * Integration mechanics:
 *
 *   - `editor.MediaReplaceFlow` is wrapped via the @wordpress/hooks filter.
 *   - The wrapped component injects an extra `<MenuItem>` via MediaReplaceFlow's
 *     official `children` slot (see media-replace-flow/index.js in Gutenberg,
 *     where children are rendered between native options and "Reset").
 *   - We deliberately do NOT call `onSelect()` on success — that callback is
 *     meant for "this is a different attachment" semantics. Our attachment's
 *     identity (id, url) is unchanged; only the bytes and metadata change.
 *   - After a successful update, we invalidate the attachment in @wordpress/core-data
 *     (so subscribed blocks refetch metadata), refresh the legacy wp.media model,
 *     bust the browser cache on <img> tags inside the editor canvas iframe
 *     (since the URL is stable, the browser would otherwise serve cached bytes),
 *     and surface a success notice via @wordpress/notices.
 */

import { addFilter } from '@wordpress/hooks';
import { __, sprintf } from '@wordpress/i18n';
import {
	MenuItem,
	Modal,
	Button,
	RadioControl,
	TextareaControl,
	FormFileUpload,
	Notice,
	Spinner,
} from '@wordpress/components';
import { useState, useEffect, Fragment } from '@wordpress/element';
import { dispatch, useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { store as noticesStore } from '@wordpress/notices';
import { speak } from '@wordpress/a11y';

const FILTER_NAMESPACE = 'smart-media-replacement/update-existing-file';

/**
 * Settings localized from PHP. Available as soon as the script loads.
 *
 * @return {Object} The localized settings object.
 */
function getSettings() {
	return typeof window !== 'undefined' && window.smrEditorData ? window.smrEditorData : {};
}

/**
 * Decide whether revisions are enabled for a given attachment based on the
 * global toggle plus the per-file-type filter. Mirrors the PHP
 * `Helpers::is_revision_enabled_for_attachment()` logic so the editor UI
 * shows the version/comment controls in the same cases as the admin UI.
 *
 * @param {string} mime Attachment MIME type (e.g. "image/png").
 * @return {boolean} Whether the attachment qualifies for revisions.
 */
function isRevisionEnabledForMime(mime) {
	const settings = getSettings();
	if (!settings.enableRevisions) {
		return false;
	}
	const isImage = typeof mime === 'string' && mime.startsWith('image/');
	const fileTypes = settings.fileTypes || 'documents';
	if (fileTypes === 'all') {
		return true;
	}
	if (fileTypes === 'images') {
		return isImage;
	}
	// Default 'documents' — enable for non-images.
	return !isImage;
}

/**
 * Fetch the latest revision for an attachment so the modal can show accurate
 * "vX.Y → vX.Z" labels. Reuses the existing smr_get_revisions endpoint and
 * derives the next-version strings client-side.
 *
 * @param {number} attachmentId The attachment ID.
 * @return {Promise<Object|null>} Resolves to {latest, nextMinor, nextMajor, count}.
 */
async function fetchVersionInfo(attachmentId) {
	const settings = getSettings();
	if (!settings.ajaxUrl || !settings.revisionNonce) {
		return null;
	}

	const formData = new FormData();
	formData.append('action', 'smr_get_revisions');
	formData.append('nonce', settings.revisionNonce);
	formData.append('attachment_id', attachmentId);

	try {
		const response = await fetch(settings.ajaxUrl, {
			method: 'POST',
			body: formData,
			credentials: 'same-origin',
		});
		const json = await response.json();
		if (!json.success) {
			return null;
		}
		const revisions = json.data?.revisions || [];
		const count = json.data?.count || 0;
		if (revisions.length === 0) {
			return { latest: '', nextMinor: '1.0', nextMajor: '1.0', count: 0 };
		}
		const latestStr = revisions[0].version || '';
		const [majorRaw, minorRaw] = latestStr.split('.');
		const major = parseInt(majorRaw || '1', 10);
		const minor = parseInt(minorRaw || '0', 10);
		return {
			latest: latestStr,
			nextMinor: `${major}.${minor + 1}`,
			nextMajor: `${major + 1}.0`,
			count,
		};
	} catch {
		return null;
	}
}

/**
 * Submit a file replacement to the existing AJAX endpoint.
 *
 * @param {Object} args              Submission args.
 * @param {number} args.attachmentId The attachment ID.
 * @param {File}   args.file         The new file to upload.
 * @param {string} args.versionType  'minor' or 'major'.
 * @param {string} args.comment      Replacement note.
 * @return {Promise<Object>} Resolves to {success: bool, error?: string}.
 */
async function submitReplacement({ attachmentId, file, versionType, comment }) {
	const settings = getSettings();
	const formData = new FormData();
	formData.append('action', 'smart_media_replacement_file');
	formData.append('nonce', settings.replaceNonce);
	formData.append('attachment_id', attachmentId);
	formData.append('replacement_file', file);
	formData.append('version_type', versionType || 'minor');
	formData.append('comment', comment || '');

	try {
		const response = await fetch(settings.ajaxUrl, {
			method: 'POST',
			body: formData,
			credentials: 'same-origin',
		});
		const json = await response.json();
		if (json.success) {
			return { success: true };
		}
		return {
			success: false,
			error: json.data || __('Error replacing file.', 'smart-media-replacement'),
		};
	} catch (e) {
		return { success: false, error: e.message };
	}
}

/**
 * After a successful replacement, refresh every surface that may be showing
 * stale data for this attachment:
 *
 *   1. Invalidate the core-data entity record so blocks subscribed via
 *      getEntityRecord refetch the new metadata.
 *   2. Refresh the legacy wp.media model if present (covers the media library
 *      modal grid view, in case the user opened our flow from there).
 *   3. Bust the browser cache on any <img> matching this attachment's URL,
 *      including images inside the editor canvas iframe. Necessary because
 *      our URL is stable by design — without busting, the browser keeps
 *      serving cached bytes.
 *
 * @param {number} attachmentId The attachment ID just replaced.
 * @param {string} mediaUrl     The (stable) URL of the attachment.
 */
function refreshAfterReplacement(attachmentId, mediaUrl) {
	// 1. Core-data invalidation — Gutenberg's official refresh mechanism.
	try {
		dispatch(coreStore).invalidateResolution('getEntityRecord', [
			'postType',
			'attachment',
			attachmentId,
		]);
		// The image block also queries with a context, invalidate that variant too.
		dispatch(coreStore).invalidateResolution('getEntityRecord', [
			'postType',
			'attachment',
			attachmentId,
			{ context: 'view' },
		]);
	} catch {
		// Core store may be unavailable in some contexts; non-fatal.
	}

	// 2. Legacy wp.media model — useful if the user opened the picker from
	// the editor and the wp.media modal grid is still mounted.
	try {
		if (window.wp?.media?.model?.Attachment) {
			const attachment = window.wp.media.model.Attachment.get(attachmentId);
			if (attachment && typeof attachment.fetch === 'function') {
				attachment.fetch();
			}
		}
	} catch {
		// Non-fatal.
	}

	// 3. Browser cache bust on visible <img> matching this URL.
	if (mediaUrl) {
		const baseUrl = mediaUrl.split('?')[0];
		const buster = `?_smr=${Date.now()}`;
		const bust = doc => {
			doc.querySelectorAll(`img[src*="${baseUrl}"]`).forEach(img => {
				img.src = img.src.split('?')[0] + buster;
			});
		};
		bust(document);
		const iframe = document.querySelector('iframe[name="editor-canvas"]');
		if (iframe && iframe.contentDocument) {
			bust(iframe.contentDocument);
		}
	}
}

/**
 * The MenuItem rendered inside MediaReplaceFlow's dropdown. Decides between
 * opening our React modal (when revisions apply) or a plain hidden file input
 * (no revision data to collect — same UX as the admin-side direct picker).
 *
 * @param {Object}   props          Component props from MediaReplaceFlow.
 * @param {number}   props.mediaId  The attachment ID being replaced.
 * @param {string}   props.mediaURL The attachment's (stable) URL.
 * @param {Function} props.onClose  Closes the MediaReplaceFlow dropdown.
 * @return {Element|null} The MenuItem and (conditionally) the modal.
 */
function UpdateExistingFileMenuItem({ mediaId, mediaURL, onClose }) {
	const [isModalOpen, setModalOpen] = useState(false);

	// Look up the attachment's mime so we can decide whether revisions apply
	// to this specific file type. The image block already populates this in
	// core-data so the read is cheap.
	const mime = useSelect(
		select => {
			if (!mediaId) {
				return '';
			}
			const record = select(coreStore).getEntityRecord('postType', 'attachment', mediaId);
			return record?.mime_type || '';
		},
		[mediaId]
	);

	// Don't render the menu item if there's no attachment to operate on
	// (e.g., the block has a URL but no underlying media record).
	if (!mediaId) {
		return null;
	}

	const handleClick = () => {
		const revisionsApply = isRevisionEnabledForMime(mime);
		if (revisionsApply) {
			// Open the modal to collect version type + replacement note.
			setModalOpen(true);
			// Note: we do NOT call onClose() here because the modal needs the
			// dropdown to remain in the DOM tree to keep React state alive.
			// The dropdown auto-closes when focus moves to our modal.
		} else {
			// Direct file picker — no metadata to collect.
			openDirectFilePicker(mediaId, mediaURL, onClose);
		}
	};

	return (
		<Fragment>
			<MenuItem onClick={handleClick}>
				{__('Update existing file', 'smart-media-replacement')}
			</MenuItem>
			{isModalOpen && (
				<ReplaceFileModal
					attachmentId={mediaId}
					mediaURL={mediaURL}
					onClose={() => {
						setModalOpen(false);
						onClose();
					}}
				/>
			)}
		</Fragment>
	);
}

/**
 * Direct file picker for cases where no revision metadata is collected.
 * Mirrors the admin-side openDirectFilePicker() flow.
 *
 * @param {number}   attachmentId The attachment ID to replace.
 * @param {string}   mediaURL     The attachment's stable URL.
 * @param {Function} onClose      Closes the MediaReplaceFlow dropdown.
 */
function openDirectFilePicker(attachmentId, mediaURL, onClose) {
	const input = document.createElement('input');
	input.type = 'file';
	input.style.display = 'none';
	document.body.appendChild(input);
	input.addEventListener('change', async () => {
		if (!input.files || input.files.length === 0) {
			document.body.removeChild(input);
			return;
		}
		const file = input.files[0];
		document.body.removeChild(input);
		onClose();
		const result = await submitReplacement({
			attachmentId,
			file,
			versionType: 'minor',
			comment: '',
		});
		if (result.success) {
			refreshAfterReplacement(attachmentId, mediaURL);
			speak(__('File updated.', 'smart-media-replacement'));
			dispatch(noticesStore).createNotice(
				'success',
				__('File updated.', 'smart-media-replacement'),
				{ type: 'snackbar', isDismissible: true }
			);
		} else {
			dispatch(noticesStore).createNotice('error', result.error, {
				type: 'snackbar',
				isDismissible: true,
			});
		}
	});
	input.click();
}

/**
 * Modal for the revision-enabled case. Collects file, version type, and
 * replacement note before submitting. Pre-fetches the attachment's version
 * history on mount so labels read "Minor (v2.3 → v2.4)" with real values.
 *
 * @param {Object}   props              Component props.
 * @param {number}   props.attachmentId The attachment ID being replaced.
 * @param {string}   props.mediaURL     The attachment's stable URL.
 * @param {Function} props.onClose      Closes the modal.
 * @return {Element} The modal element.
 */
function ReplaceFileModal({ attachmentId, mediaURL, onClose }) {
	const settings = getSettings();
	const requireComment = !!settings.requireComment;
	const defaultVersion = settings.defaultVersion || 'minor';

	const [file, setFile] = useState(null);
	const [versionType, setVersionType] = useState(defaultVersion);
	const [comment, setComment] = useState('');
	const [versionInfo, setVersionInfo] = useState(null);
	const [submitting, setSubmitting] = useState(false);
	const [error, setError] = useState('');

	useEffect(() => {
		let cancelled = false;
		fetchVersionInfo(attachmentId).then(info => {
			if (!cancelled) {
				setVersionInfo(
					info || { latest: '', nextMinor: '1.0', nextMajor: '1.0', count: 0 }
				);
			}
		});
		return () => {
			cancelled = true;
		};
	}, [attachmentId]);

	const handleSubmit = async () => {
		if (!file) {
			return;
		}
		if (requireComment && !comment.trim()) {
			setError(__('A replacement note is required.', 'smart-media-replacement'));
			return;
		}
		setSubmitting(true);
		setError('');
		const result = await submitReplacement({
			attachmentId,
			file,
			versionType,
			comment: comment.trim(),
		});
		if (result.success) {
			refreshAfterReplacement(attachmentId, mediaURL);
			speak(__('File updated.', 'smart-media-replacement'));
			dispatch(noticesStore).createNotice(
				'success',
				__('File updated.', 'smart-media-replacement'),
				{ type: 'snackbar', isDismissible: true }
			);
			onClose();
		} else {
			setError(result.error);
			setSubmitting(false);
		}
	};

	const hasLatest = versionInfo && versionInfo.latest;
	let versionControl = null;

	if (versionInfo === null) {
		versionControl = (
			<p>
				<Spinner />
				{__('Loading version info…', 'smart-media-replacement')}
			</p>
		);
	} else if (!hasLatest) {
		versionControl = (
			<p style={{ color: '#646970' }}>
				{sprintf(
					/* translators: %s: the version string that will be assigned, e.g. "1.0" */
					__('First revision will be saved as v%s.', 'smart-media-replacement'),
					versionInfo.nextMinor
				)}
			</p>
		);
	} else {
		versionControl = (
			<RadioControl
				label={__('Version type', 'smart-media-replacement')}
				selected={versionType}
				options={[
					{
						label: sprintf(
							/* translators: 1: current version, 2: next minor version */
							__('Minor (v%1$s → v%2$s)', 'smart-media-replacement'),
							versionInfo.latest,
							versionInfo.nextMinor
						),
						value: 'minor',
					},
					{
						label: sprintf(
							/* translators: 1: current version, 2: next major version */
							__('Major (v%1$s → v%2$s)', 'smart-media-replacement'),
							versionInfo.latest,
							versionInfo.nextMajor
						),
						value: 'major',
					},
				]}
				onChange={setVersionType}
			/>
		);
	}

	const submitDisabled =
		!file || submitting || versionInfo === null || (requireComment && !comment.trim());

	return (
		<Modal
			title={__('Update existing file', 'smart-media-replacement')}
			onRequestClose={submitting ? undefined : onClose}
			shouldCloseOnClickOutside={!submitting}
			shouldCloseOnEsc={!submitting}
			style={{ maxWidth: '480px' }}
		>
			{error && (
				<Notice status="error" isDismissible={false}>
					{error}
				</Notice>
			)}

			<div style={{ marginBottom: '16px' }}>
				<FormFileUpload
					onChange={event => setFile(event.target.files[0] || null)}
					variant="secondary"
				>
					{file
						? sprintf(
								/* translators: %s: selected filename */
								__('Selected: %s', 'smart-media-replacement'),
								file.name
							)
						: __('Choose file…', 'smart-media-replacement')}
				</FormFileUpload>
			</div>

			<div style={{ marginBottom: '16px' }}>{versionControl}</div>

			<TextareaControl
				__nextHasNoMarginBottom
				label={
					__('Replacement note', 'smart-media-replacement') + (requireComment ? ' *' : '')
				}
				value={comment}
				onChange={setComment}
				help={
					requireComment
						? __('A note is required.', 'smart-media-replacement')
						: __('Optional. Describe what changed.', 'smart-media-replacement')
				}
				rows={3}
			/>

			<div
				style={{
					display: 'flex',
					justifyContent: 'flex-end',
					gap: '8px',
					marginTop: '16px',
				}}
			>
				<Button variant="tertiary" onClick={onClose} disabled={submitting}>
					{__('Cancel', 'smart-media-replacement')}
				</Button>
				<Button
					variant="primary"
					onClick={handleSubmit}
					disabled={submitDisabled}
					isBusy={submitting}
				>
					{submitting
						? __('Updating…', 'smart-media-replacement')
						: __('Update file', 'smart-media-replacement')}
				</Button>
			</div>
		</Modal>
	);
}

/**
 * Filter wrapper that injects our MenuItem into MediaReplaceFlow via the
 * official `children` slot. Preserves any block-supplied children — some
 * blocks pass their own (e.g. URL input forms) so we render those first
 * and append ours.
 *
 * @param {Function} OriginalMediaReplaceFlow The component being filtered.
 * @return {Function} Wrapped component.
 */
function withUpdateExistingFile(OriginalMediaReplaceFlow) {
	return function MediaReplaceFlowWithUpdate(props) {
		const blockChildren = props.children;
		return (
			<OriginalMediaReplaceFlow {...props}>
				{slotProps => (
					<Fragment>
						{typeof blockChildren === 'function'
							? blockChildren(slotProps)
							: blockChildren}
						<UpdateExistingFileMenuItem
							mediaId={props.mediaId}
							mediaURL={props.mediaURL}
							onClose={slotProps.onClose}
						/>
					</Fragment>
				)}
			</OriginalMediaReplaceFlow>
		);
	};
}

addFilter('editor.MediaReplaceFlow', FILTER_NAMESPACE, withUpdateExistingFile);
