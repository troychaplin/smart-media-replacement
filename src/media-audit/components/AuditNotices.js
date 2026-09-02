import { SnackbarList } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Snackbar notices for the audit screen.
 *
 * The screen previously reported nothing at all: a failed delete rejected
 * silently and left stale rows on screen. Bulk operations report partial
 * results, so they need somewhere to say what actually happened.
 *
 * @return {Element} The snackbar list.
 */
export default function AuditNotices() {
	const notices = useSelect(
		select =>
			select(noticesStore)
				.getNotices()
				.filter(notice => notice.type === 'snackbar'),
		[]
	);
	const { removeNotice } = useDispatch(noticesStore);

	return (
		<SnackbarList className="smr-audit-snackbars" notices={notices} onRemove={removeNotice} />
	);
}
