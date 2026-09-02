import { useState, useMemo, useCallback } from '@wordpress/element';
import { DataViews } from '@wordpress/dataviews';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { trash, check, closeSmall } from '@wordpress/icons';
import ScanToolbar from './components/ScanToolbar';
import ThumbnailCell from './components/ThumbnailCell';
import TitleCell from './components/TitleCell';
import UsedInCell from './components/UsedInCell';
import MarkedBar from './components/MarkedBar';
import DeleteConfirmModal from './components/DeleteConfirmModal';
import AuditNotices from './components/AuditNotices';
import useMediaAudit from './hooks/useMediaAudit';
import useScanProgress from './hooks/useScanProgress';
import useMarkActions from './hooks/useMarkActions';
import './styles.scss';

const DEFAULT_VIEW = {
	type: 'table',
	search: '',
	filters: [],
	page: 1,
	perPage: 20,
	sort: { field: 'date', direction: 'desc' },
	// `title` and `thumbnail` are deliberately absent: the layouts render the
	// designated title/media fields as their own primary column, so listing them
	// here as well renders each of them twice.
	fields: ['media_type', 'usage', 'file_size', 'alt_text', 'marked_for_deletion', 'date'],
	titleField: 'title',
	mediaField: 'thumbnail',
};

function formatFileSize(bytes) {
	if (!bytes) {
		return '—';
	}
	if (bytes < 1024) {
		return bytes + ' B';
	}
	if (bytes < 1024 * 1024) {
		return Math.round(bytes / 1024) + ' KB';
	}
	return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}

export default function App() {
	const [view, setView] = useState(DEFAULT_VIEW);
	const [selection, setSelection] = useState([]);
	const [scanVersion, setScanVersion] = useState(0);
	const [isBusy, setIsBusy] = useState(false);
	const [indexBuilt, setIndexBuilt] = useState(() => window.smrAuditData?.indexBuilt ?? false);

	const { createSuccessNotice, createErrorNotice } = useDispatch(noticesStore);

	const { items, totalItems, markedTotal, isLoading } = useMediaAudit(view, scanVersion);
	const { status, progress, total, startScan, resetToIdle } = useScanProgress({
		onComplete: () => {
			setIndexBuilt(true);
			setScanVersion(v => v + 1);
		},
	});

	const refresh = useCallback(() => setScanVersion(v => v + 1), []);
	const { setMarked, markAllMatching, deleteItems, deleteMarked } = useMarkActions(refresh);

	const notice = useCallback(
		message => createSuccessNotice(message, { type: 'snackbar' }),
		[createSuccessNotice]
	);

	/**
	 * Run a queue operation with the busy flag and a single error notice.
	 *
	 * @param {Function} operation Async operation returning a message to show.
	 */
	const run = useCallback(
		async operation => {
			setIsBusy(true);
			try {
				const message = await operation();
				if (message) {
					notice(message);
				}
			} catch (error) {
				createErrorNotice(
					error?.message ||
						__('The operation could not be completed.', 'smart-media-replacement'),
					{ type: 'snackbar' }
				);
			} finally {
				setIsBusy(false);
			}
		},
		[notice, createErrorNotice]
	);

	const handleClear = useCallback(async () => {
		const { ajaxUrl, nonce } = window.smrAuditData;
		const body = new FormData();
		body.append('action', 'smr_audit_clear_index');
		body.append('nonce', nonce);

		await fetch(ajaxUrl, { method: 'POST', body }).catch(() => {});

		setIndexBuilt(false);
		resetToIdle();
		setScanVersion(v => v + 1);
	}, [resetToIdle]);

	/**
	 * Report the outcome of a delete, including what the server refused.
	 *
	 * @param {Object} result Response with deleted and skipped arrays.
	 * @return {string} Message for the snackbar.
	 */
	const describeDelete = useCallback(result => {
		const deleted = result.deleted?.length || 0;
		const inUse = (result.skipped || []).filter(entry => entry.reason === 'in_use').length;
		const otherwiseSkipped = (result.skipped?.length || 0) - inUse;

		const parts = [
			sprintf(
				/* translators: %d: number of files */
				_n('%d file deleted.', '%d files deleted.', deleted, 'smart-media-replacement'),
				deleted
			),
		];

		if (inUse) {
			parts.push(
				sprintf(
					/* translators: %d: number of files */
					_n(
						'%d file was skipped because it is still in use.',
						'%d files were skipped because they are still in use.',
						inUse,
						'smart-media-replacement'
					),
					inUse
				)
			);
		}

		if (otherwiseSkipped > 0) {
			parts.push(
				sprintf(
					/* translators: %d: number of files */
					_n(
						'%d file could not be deleted.',
						'%d files could not be deleted.',
						otherwiseSkipped,
						'smart-media-replacement'
					),
					otherwiseSkipped
				)
			);
		}

		return parts.join(' ');
	}, []);

	const handleDelete = useCallback(
		items_ =>
			run(async () => {
				const result = await deleteItems(items_);
				setSelection([]);
				return describeDelete(result);
			}),
		[run, deleteItems, describeDelete]
	);

	const handleMark = useCallback(
		(items_, marked) =>
			run(async () => {
				const result = await setMarked(items_, marked);
				setSelection([]);
				return marked
					? sprintf(
							/* translators: %d: number of files */
							_n(
								'%d file marked for deletion.',
								'%d files marked for deletion.',
								result.count,
								'smart-media-replacement'
							),
							result.count
						)
					: sprintf(
							/* translators: %d: number of files */
							_n(
								'%d file unmarked.',
								'%d files unmarked.',
								result.count,
								'smart-media-replacement'
							),
							result.count
						);
			}),
		[run, setMarked]
	);

	const handleMarkMatching = useCallback(
		() =>
			run(async () => {
				const result = await markAllMatching(view, true);
				if (result.capped) {
					return sprintf(
						/* translators: 1: number marked, 2: total matching */
						__(
							'%1$d of %2$d matching files marked. Narrow the filters and repeat to mark the rest.',
							'smart-media-replacement'
						),
						result.count,
						result.total
					);
				}
				return sprintf(
					/* translators: %d: number of files */
					_n(
						'%d file marked for deletion.',
						'%d files marked for deletion.',
						result.count,
						'smart-media-replacement'
					),
					result.count
				);
			}),
		[run, markAllMatching, view]
	);

	const handleClearMarks = useCallback(
		() =>
			run(async () => {
				// Scope to the marked rows rather than the whole library: an
				// unfiltered unmark would walk every attachment to clear a flag
				// that only a handful of them have.
				const result = await markAllMatching(
					{
						...view,
						search: '',
						filters: [{ field: 'marked', operator: 'is', value: 'marked' }],
					},
					false
				);
				return sprintf(
					/* translators: %d: number of files */
					_n(
						'%d mark cleared.',
						'%d marks cleared.',
						result.count,
						'smart-media-replacement'
					),
					result.count
				);
			}),
		[run, markAllMatching, view]
	);

	const handleDeleteMarked = useCallback(
		() =>
			run(async () => {
				const result = await deleteMarked();
				setSelection([]);
				return describeDelete(result);
			}),
		[run, deleteMarked, describeDelete]
	);

	/** Toggle the list between the review queue and everything. */
	const handleReview = useCallback(() => {
		setView(current => {
			const isReviewing = current.filters?.some(f => f.field === 'marked');
			return {
				...current,
				page: 1,
				filters: isReviewing
					? current.filters.filter(f => f.field !== 'marked')
					: [
							...(current.filters || []),
							{ field: 'marked', operator: 'is', value: 'marked' },
						],
			};
		});
	}, []);

	const fields = useMemo(
		() => [
			{
				id: 'thumbnail',
				label: __('Preview', 'smart-media-replacement'),
				type: 'media',
				enableSorting: false,
				enableHiding: false,
				enableGlobalSearch: false,
				filterBy: false,
				render: ({ item }) => <ThumbnailCell item={item} />,
			},
			{
				id: 'title',
				label: __('File Name', 'smart-media-replacement'),
				type: 'text',
				enableSorting: true,
				enableHiding: false,
				enableGlobalSearch: true,
				// Filtering is server-side and the endpoint accepts no title
				// filter, so keep this out of the "Add filter" menu that the
				// text field type would otherwise put it in.
				filterBy: false,
				render: ({ item }) => <TitleCell item={item} />,
			},
			{
				id: 'reference_type',
				label: __('Location', 'smart-media-replacement'),
				enableSorting: false,
				// Filter-only: no render and no matching property on the item,
				// so it must never be toggled on as a column.
				enableHiding: false,
				enableGlobalSearch: false,
				elements: [
					{ value: 'block', label: __('Block', 'smart-media-replacement') },
					{
						value: 'featured_image',
						label: __('Featured Image', 'smart-media-replacement'),
					},
					{ value: 'classic', label: __('Content', 'smart-media-replacement') },
					{ value: 'postmeta', label: __('Post Meta', 'smart-media-replacement') },
				],
				filterBy: { isPrimary: true, operators: ['is'] },
			},
			{
				id: 'media_type',
				label: __('Type', 'smart-media-replacement'),
				enableSorting: false,
				elements: [
					{ value: 'Image', label: __('Image', 'smart-media-replacement') },
					{ value: 'Video', label: __('Video', 'smart-media-replacement') },
					{ value: 'Audio', label: __('Audio', 'smart-media-replacement') },
					{ value: 'Document', label: __('Document', 'smart-media-replacement') },
				],
				filterBy: { isPrimary: true, operators: ['is'] },
			},
			{
				id: 'usage',
				label: __('Used In', 'smart-media-replacement'),
				type: 'integer',
				enableSorting: true,
				enableGlobalSearch: false,
				// The used/unused choice is a separate filter-only field: this
				// column's value is a count, and mixing the two on one field
				// makes getValue disagree with the filter's elements.
				filterBy: false,
				getValue: ({ item }) => item.usage_count,
				render: ({ item }) => <UsedInCell item={item} indexBuilt={indexBuilt} />,
			},
			{
				// Filter-only companion to the "Used In" column.
				id: 'usage_filter',
				label: __('Usage', 'smart-media-replacement'),
				enableSorting: false,
				enableHiding: false,
				enableGlobalSearch: false,
				elements: [
					{ value: 'used', label: __('Used', 'smart-media-replacement') },
					{ value: 'unused', label: __('Unused', 'smart-media-replacement') },
				],
				filterBy: { isPrimary: true, operators: ['is'] },
			},
			{
				id: 'file_size',
				label: __('Size', 'smart-media-replacement'),
				type: 'integer',
				enableSorting: true,
				enableGlobalSearch: false,
				filterBy: false,
				getValue: ({ item }) => item.file_size,
				render: ({ item }) => formatFileSize(item.file_size),
			},
			{
				id: 'alt_text',
				label: __('Alt Text', 'smart-media-replacement'),
				enableSorting: false,
				enableHiding: false,
				enableGlobalSearch: false,
				filterBy: false,
				render: ({ item }) => {
					if (item.media_type !== 'Image' || !item.content_alt_missing) {
						return null;
					}
					return (
						<span className="smr-audit-no-alt">
							{__('No alt', 'smart-media-replacement')}
						</span>
					);
				},
			},
			{
				id: 'marked_for_deletion',
				label: __('Queued', 'smart-media-replacement'),
				enableSorting: false,
				enableGlobalSearch: false,
				filterBy: false,
				render: ({ item }) =>
					item.marked_for_deletion ? (
						<span className="smr-audit-marked-badge">
							{__('Marked', 'smart-media-replacement')}
						</span>
					) : null,
			},
			{
				// Filter-only field: no column, no render. Labels drive the chip
				// order (DataViews sorts primary filters alphabetically), so the
				// four chips read: Location → Marked → Type → Usage → Without Alt.
				id: 'marked',
				label: __('Marked', 'smart-media-replacement'),
				enableSorting: false,
				enableHiding: false,
				enableGlobalSearch: false,
				elements: [
					{ value: 'marked', label: __('Marked', 'smart-media-replacement') },
					{ value: 'unmarked', label: __('Not marked', 'smart-media-replacement') },
				],
				filterBy: { isPrimary: true, operators: ['is'] },
			},
			{
				id: 'missing_alt',
				label: __('Without Alt', 'smart-media-replacement'),
				enableSorting: false,
				enableHiding: false,
				enableGlobalSearch: false,
				elements: [{ value: 'missing', label: __('Missing', 'smart-media-replacement') }],
				filterBy: { isPrimary: true, operators: ['is'] },
			},
			{
				id: 'date',
				label: __('Date', 'smart-media-replacement'),
				type: 'datetime',
				enableSorting: true,
				enableGlobalSearch: false,
				filterBy: false,
				getValue: ({ item }) => item.date,
				render: ({ item }) =>
					new Date(item.date).toLocaleDateString(undefined, {
						year: 'numeric',
						month: 'short',
						day: 'numeric',
					}),
			},
		],
		[indexBuilt]
	);

	const actions = useMemo(
		() => [
			{
				id: 'mark',
				label: __('Mark for deletion', 'smart-media-replacement'),
				icon: check,
				supportsBulk: true,
				isEligible: item => !item.marked_for_deletion,
				// This DataViews version hands a bulk callback every selected
				// item that any bulk action accepts, not just the ones eligible
				// for this action, so re-apply the rule here.
				callback: items_ =>
					handleMark(
						items_.filter(item => !item.marked_for_deletion),
						true
					),
			},
			{
				id: 'unmark',
				label: __('Remove mark', 'smart-media-replacement'),
				icon: closeSmall,
				supportsBulk: true,
				isEligible: item => !!item.marked_for_deletion,
				callback: items_ =>
					handleMark(
						items_.filter(item => item.marked_for_deletion),
						false
					),
			},
			{
				id: 'delete',
				label: __('Delete permanently', 'smart-media-replacement'),
				icon: trash,
				supportsBulk: true,
				isEligible: item => item.usage_count === 0,
				modalHeader: __('Delete permanently', 'smart-media-replacement'),
				modalFocusOnMount: 'firstContentElement',
				RenderModal: ({ items: modalItems, closeModal, onActionPerformed }) => (
					<DeleteConfirmModal
						items={modalItems.filter(item => item.usage_count === 0)}
						closeModal={closeModal}
						onActionPerformed={onActionPerformed}
						onDelete={handleDelete}
					/>
				),
			},
		],
		[handleMark, handleDelete]
	);

	const paginationInfo = useMemo(
		() => ({
			totalItems,
			totalPages: Math.ceil(totalItems / view.perPage),
		}),
		[totalItems, view.perPage]
	);

	const isFiltered = !!(view.search || view.filters?.length);
	const isReviewing = !!view.filters?.some(f => f.field === 'marked');

	const empty = useMemo(() => {
		if (!indexBuilt) {
			return (
				<p>
					{__(
						'The media index has not been built yet. Run a scan to see which files are in use.',
						'smart-media-replacement'
					)}
				</p>
			);
		}
		return (
			<p>
				{isFiltered
					? __('No files match these filters.', 'smart-media-replacement')
					: __('No media found.', 'smart-media-replacement')}
			</p>
		);
	}, [indexBuilt, isFiltered]);

	return (
		<div className="smr-audit-app">
			{/*
			 * Deliberately outside DataViews rather than in its `header` slot:
			 * that slot is a flex-shrink:0 area beside the view-config button,
			 * which the scan progress bar cannot share without being squashed.
			 * Scanning is a page-level operation, not a list control.
			 */}
			<ScanToolbar
				status={status}
				progress={progress}
				total={total}
				onScan={startScan}
				onClear={handleClear}
			/>
			<MarkedBar
				markedTotal={markedTotal}
				matchCount={totalItems}
				isFiltered={isFiltered}
				isReviewing={isReviewing}
				isBusy={isBusy}
				onReview={handleReview}
				onMarkMatching={handleMarkMatching}
				onClearMarks={handleClearMarks}
				onDeleteMarked={handleDeleteMarked}
			/>
			<DataViews
				data={items}
				fields={fields}
				view={view}
				onChangeView={setView}
				getItemId={item => String(item.id)}
				selection={selection}
				onChangeSelection={setSelection}
				paginationInfo={paginationInfo}
				actions={actions}
				defaultLayouts={{ table: {}, grid: {} }}
				isLoading={isLoading}
				searchLabel={__('Search media by file name', 'smart-media-replacement')}
				empty={empty}
				onReset={isFiltered ? () => setView(DEFAULT_VIEW) : false}
			/>
			<AuditNotices />
		</div>
	);
}
