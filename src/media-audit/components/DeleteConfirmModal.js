import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Confirmation body for the permanent-delete action.
 *
 * Rendered by DataViews inside its own Modal, for both the single-row action
 * and the bulk toolbar — one code path, so the wording and the guard rails
 * cannot drift apart.
 *
 * Note that DataViews supplies only `items` and `closeModal`; `onActionPerformed`
 * is passed by consumers that wrap the actions array, so it is always optional.
 *
 * @param {Object}   props                   Component props.
 * @param {Array}    props.items             Attachments to delete.
 * @param {Function} props.closeModal        Closes the surrounding modal.
 * @param {Function} props.onDelete          Performs the deletion; resolves to { deleted, skipped }.
 * @param {Function} props.onActionPerformed Optional post-action callback.
 * @return {Element} The modal body.
 */
export default function DeleteConfirmModal({ items, closeModal, onDelete, onActionPerformed }) {
	const [isBusy, setIsBusy] = useState(false);

	const message =
		items.length === 1
			? sprintf(
					/* translators: %s: file name */
					__(
						'Permanently delete "%s"? This cannot be undone.',
						'smart-media-replacement'
					),
					items[0].title
				)
			: sprintf(
					/* translators: %d: number of files */
					_n(
						'Permanently delete %d file? This cannot be undone.',
						'Permanently delete %d files? This cannot be undone.',
						items.length,
						'smart-media-replacement'
					),
					items.length
				);

	return (
		<div className="smr-audit-confirm">
			<p>{message}</p>
			{items.length > 1 && (
				<ul className="smr-audit-confirm-list">
					{items.slice(0, 10).map(item => (
						<li key={item.id}>{item.title}</li>
					))}
					{items.length > 10 && (
						<li className="smr-audit-confirm-list__more">
							{sprintf(
								/* translators: %d: number of additional files */
								_n(
									'…and %d more file.',
									'…and %d more files.',
									items.length - 10,
									'smart-media-replacement'
								),
								items.length - 10
							)}
						</li>
					)}
				</ul>
			)}
			<div className="smr-audit-modal-actions">
				<Button
					variant="tertiary"
					onClick={closeModal}
					disabled={isBusy}
					accessibleWhenDisabled
					__next40pxDefaultSize
				>
					{__('Cancel', 'smart-media-replacement')}
				</Button>
				<Button
					variant="primary"
					isDestructive
					isBusy={isBusy}
					disabled={isBusy}
					accessibleWhenDisabled
					__next40pxDefaultSize
					onClick={async () => {
						setIsBusy(true);
						try {
							await onDelete(items);
							onActionPerformed?.(items);
						} finally {
							setIsBusy(false);
							closeModal?.();
						}
					}}
				>
					{__('Delete permanently', 'smart-media-replacement')}
				</Button>
			</div>
		</div>
	);
}
