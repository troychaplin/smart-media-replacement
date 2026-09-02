import { useCallback, useMemo } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const BASE = '/smart-media-replacement/v1/audit-media';

/**
 * Translate the active DataViews filters into the query shape the mark
 * endpoint expects.
 *
 * Kept separate from the read path's param builder because this one produces a
 * JSON body rather than a query string, but it reads the same filter fields so
 * "mark all matching" always covers exactly the rows the list is showing.
 *
 * @param {Object} view Current DataViews view.
 * @return {Object} Filter payload for the mark endpoint.
 */
function filtersFromView(view) {
	const valueFor = field =>
		view.filters?.find(f => f.field === field && f.operator === 'is')?.value;

	return {
		search: view.search || '',
		media_type: valueFor('media_type') || '',
		reference_type: valueFor('reference_type') || '',
		usage_filter: valueFor('usage_filter') || '',
		missing_alt: valueFor('missing_alt') === 'missing',
		marked_filter: valueFor('marked') || '',
	};
}

/**
 * Mark, unmark and delete calls for the audit screen.
 *
 * Deletion is walked in batches because the server caps how many attachments it
 * will remove per request; the loop keeps going until the queue is empty or the
 * server stops making progress.
 *
 * @param {Function} onChanged Called after any successful write, to refresh the list.
 * @return {Object} The mark, unmark, deleteItems and deleteMarked callbacks.
 */
export default function useMarkActions(onChanged) {
	const setMarked = useCallback(
		async (items, marked) => {
			const response = await apiFetch({
				path: `${BASE}/mark`,
				method: 'POST',
				data: {
					ids: items.map(item => item.id),
					marked,
				},
			});
			onChanged();
			return response;
		},
		[onChanged]
	);

	const markAllMatching = useCallback(
		async (view, marked) => {
			const response = await apiFetch({
				path: `${BASE}/mark`,
				method: 'POST',
				data: {
					all_matching: true,
					marked,
					...filtersFromView(view),
				},
			});
			onChanged();
			return response;
		},
		[onChanged]
	);

	const deleteItems = useCallback(
		async items => {
			const response = await apiFetch({
				path: BASE,
				method: 'DELETE',
				data: { ids: items.map(item => item.id) },
			});
			onChanged();
			return response;
		},
		[onChanged]
	);

	const deleteMarked = useCallback(async () => {
		const deleted = [];
		const skippedById = new Map();

		// The server deletes at most one batch per request, so keep asking until
		// the queue stops shrinking. Two stop conditions, both needed:
		//
		// - `marked_total` hits zero — the queue is drained, and stopping on it
		//   avoids a redundant final request that finds nothing left to do.
		// - a pass deletes nothing — the remainder is all skippable (in use, say),
		//   which would otherwise loop forever, since a skipped file stays marked
		//   and comes back in the next batch.
		for (;;) {
			const response = await apiFetch({
				path: BASE,
				method: 'DELETE',
				data: { marked: true },
			});

			deleted.push(...(response.deleted || []));

			// Skipped files are re-reported on every pass, so key them by ID.
			(response.skipped || []).forEach(entry => skippedById.set(entry.id, entry));

			if (!response.deleted?.length || !response.marked_total) {
				break;
			}
		}

		onChanged();
		return { deleted, skipped: [...skippedById.values()] };
	}, [onChanged]);

	return useMemo(
		() => ({ setMarked, markAllMatching, deleteItems, deleteMarked }),
		[setMarked, markAllMatching, deleteItems, deleteMarked]
	);
}
