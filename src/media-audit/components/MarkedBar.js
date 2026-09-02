import { useState } from '@wordpress/element';
import { Button, Modal } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Review-queue toolbar for files marked for deletion.
 *
 * This is the home of the review workflow: it reports the size of the queue,
 * jumps the list to it, and offers the two whole-queue operations. It also
 * carries "mark all matching", which cannot be a bulk action because DataViews
 * prunes its selection to the visible page — the server applies the current
 * filters instead.
 *
 * @param {Object}   props                Component props.
 * @param {number}   props.markedTotal    Files currently marked, across all pages.
 * @param {number}   props.matchCount     Files matching the active filters.
 * @param {boolean}  props.isFiltered     Whether any filter or search is active.
 * @param {boolean}  props.isReviewing    Whether the list is already filtered to the queue.
 * @param {boolean}  props.isBusy         Whether a queue operation is running.
 * @param {Function} props.onReview       Toggles the marked filter on the view.
 * @param {Function} props.onMarkMatching Marks every file matching the active filters.
 * @param {Function} props.onClearMarks   Clears the whole queue.
 * @param {Function} props.onDeleteMarked Deletes the whole queue.
 * @return {Element|null} The toolbar, or null when there is nothing to offer.
 */
export default function MarkedBar({
	markedTotal,
	matchCount,
	isFiltered,
	isReviewing,
	isBusy,
	onReview,
	onMarkMatching,
	onClearMarks,
	onDeleteMarked,
}) {
	const [isConfirming, setIsConfirming] = useState(false);

	// Offering "mark all matching" for an unfiltered library would put every
	// file in the library into the delete queue in one click.
	const canMarkMatching = isFiltered && matchCount > 0;

	if (!markedTotal && !canMarkMatching) {
		return null;
	}

	return (
		<div className="smr-audit-marked-bar">
			<div className="smr-audit-marked-bar__row">
				{markedTotal > 0 && (
					<span className="smr-audit-marked-bar__count">
						{sprintf(
							/* translators: %d: number of files */
							_n(
								'%d file marked for deletion',
								'%d files marked for deletion',
								markedTotal,
								'smart-media-replacement'
							),
							markedTotal
						)}
					</span>
				)}

				{markedTotal > 0 && (
					<Button variant="secondary" size="compact" onClick={onReview} disabled={isBusy}>
						{isReviewing
							? __('Show all files', 'smart-media-replacement')
							: __('Review queue', 'smart-media-replacement')}
					</Button>
				)}

				{canMarkMatching && (
					<Button
						variant="secondary"
						size="compact"
						onClick={onMarkMatching}
						disabled={isBusy}
					>
						{sprintf(
							/* translators: %d: number of matching files */
							_n(
								'Mark %d matching file',
								'Mark all %d matching files',
								matchCount,
								'smart-media-replacement'
							),
							matchCount
						)}
					</Button>
				)}

				{markedTotal > 0 && (
					<>
						<Button
							variant="secondary"
							size="compact"
							onClick={onClearMarks}
							disabled={isBusy}
						>
							{__('Clear all marks', 'smart-media-replacement')}
						</Button>
						<Button
							variant="primary"
							size="compact"
							isDestructive
							onClick={() => setIsConfirming(true)}
							disabled={isBusy}
							isBusy={isBusy}
						>
							{__('Delete all marked', 'smart-media-replacement')}
						</Button>
					</>
				)}
			</div>

			{isConfirming && (
				<Modal
					title={__('Delete all marked files', 'smart-media-replacement')}
					onRequestClose={() => setIsConfirming(false)}
					size="medium"
				>
					<p>
						{sprintf(
							/* translators: %d: number of files */
							_n(
								'Permanently delete %d marked file? This cannot be undone.',
								'Permanently delete %d marked files? This cannot be undone.',
								markedTotal,
								'smart-media-replacement'
							),
							markedTotal
						)}
					</p>
					<p className="smr-audit-marked-bar__note">
						{__(
							'Files that are still referenced by a post are skipped and stay in the queue.',
							'smart-media-replacement'
						)}
					</p>
					<div className="smr-audit-modal-actions">
						<Button
							variant="tertiary"
							onClick={() => setIsConfirming(false)}
							__next40pxDefaultSize
						>
							{__('Cancel', 'smart-media-replacement')}
						</Button>
						<Button
							variant="primary"
							isDestructive
							__next40pxDefaultSize
							onClick={() => {
								setIsConfirming(false);
								onDeleteMarked();
							}}
						>
							{__('Delete permanently', 'smart-media-replacement')}
						</Button>
					</div>
				</Modal>
			)}
		</div>
	);
}
