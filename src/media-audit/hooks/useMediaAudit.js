import { useState, useEffect, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/** REST route backing the audit list. */
const AUDIT_ROUTE = '/smart-media-replacement/v1/audit-media';

/**
 * DataViews filter field -> REST query parameter.
 *
 * Declarative so a new filter is one line here plus one field in App.js, rather
 * than another hand-written lookup. Any field absent from this map is ignored:
 * filtering happens server-side, so a filter the endpoint does not accept must
 * not silently appear to work.
 */
const FILTER_PARAMS = {
	media_type: 'media_type',
	reference_type: 'reference_type',
	usage_filter: 'usage_filter',
	marked: 'marked',
};

export default function useMediaAudit(view, scanVersion) {
	const [items, setItems] = useState([]);
	const [totalItems, setTotalItems] = useState(0);
	const [markedTotal, setMarkedTotal] = useState(0);
	const [isLoading, setIsLoading] = useState(true);
	const abortRef = useRef(null);
	const cacheRef = useRef(new Map());
	const cacheVersionRef = useRef(scanVersion);

	useEffect(() => {
		if (abortRef.current) {
			abortRef.current.abort();
		}

		// A scan/clear/delete/mark bumps scanVersion and makes the index stale,
		// so drop the whole client cache rather than letting old entries linger.
		if (cacheVersionRef.current !== scanVersion) {
			cacheRef.current.clear();
			cacheVersionRef.current = scanVersion;
		}

		const params = new URLSearchParams();
		params.set('page', view.page);
		params.set('per_page', view.perPage);

		if (view.search) {
			params.set('search', view.search);
		}

		if (view.sort?.field) {
			params.set('orderby', view.sort.field);
			params.set('order', view.sort.direction === 'asc' ? 'ASC' : 'DESC');
		}

		// Only the "is" operator is supported: the endpoint takes one scalar per
		// filter, so a multi-value operator would silently match on its first
		// value alone.
		(view.filters || []).forEach(filter => {
			if (filter.operator !== 'is' || !filter.value) {
				return;
			}

			if (filter.field === 'missing_alt') {
				if (filter.value === 'missing') {
					params.set('missing_alt', '1');
				}
				return;
			}

			const param = FILTER_PARAMS[filter.field];
			if (param) {
				params.set(param, filter.value);
			}
		});

		// Serve an identical prior view from cache. scanVersion is part of the
		// key, so a scan/clear/delete/mark (which bumps it) invalidates every entry.
		const cacheKey = `${scanVersion}|${params.toString()}`;
		const cached = cacheRef.current.get(cacheKey);
		if (cached) {
			setItems(cached.items);
			setTotalItems(cached.total);
			setMarkedTotal(cached.markedTotal);
			setIsLoading(false);
			return;
		}

		abortRef.current = new AbortController();
		setIsLoading(true);

		// apiFetch rather than a hand-built URL: on a site with plain permalinks
		// the REST root is `/index.php?rest_route=...`, which already carries a
		// query string, so concatenating `?` + params produced a second `?` and a
		// 404. apiFetch's root-URL middleware rewrites the separator for us, and
		// carries the nonce registered in index.js.
		apiFetch({
			path: `${AUDIT_ROUTE}?${params.toString()}`,
			signal: abortRef.current.signal,
		})
			.then(data => {
				const nextItems = data.items || [];
				const total = data.total || 0;
				const marked = data.marked_total || 0;
				cacheRef.current.set(cacheKey, {
					items: nextItems,
					total,
					markedTotal: marked,
				});
				setItems(nextItems);
				setTotalItems(total);
				setMarkedTotal(marked);
			})
			.catch(err => {
				// An aborted request is the expected outcome of a rapid filter
				// change, not an error worth reporting.
				if (err?.name !== 'AbortError' && err?.code !== 'fetch_error') {
					// eslint-disable-next-line no-console
					console.error('WP Media Audit fetch error:', err);
				}
			})
			.finally(() => setIsLoading(false));
	}, [view, scanVersion]);

	return { items, totalItems, markedTotal, isLoading };
}
