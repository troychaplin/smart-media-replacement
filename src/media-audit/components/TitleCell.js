import { __ } from '@wordpress/i18n';

/**
 * File name cell, with the non-destructive row links.
 *
 * Deletion deliberately lives only in the DataViews actions menu: keeping a
 * second delete control here meant two handlers, two confirmations and two
 * places for the "unused files only" rule to drift.
 *
 * @param {Object} props      Component props.
 * @param {Object} props.item Attachment row.
 * @return {Element} The cell.
 */
export default function TitleCell({ item }) {
	return (
		<div className="smr-audit-title-cell">
			<strong>{item.title}</strong>
			<div className="smr-audit-row-actions">
				<span>
					<a href={item.edit_url}>{__('Edit', 'smart-media-replacement')}</a>
				</span>
				<span className="smr-audit-row-actions__sep"> | </span>
				<span>
					<a href={item.file_url} target="_blank" rel="noreferrer">
						{__('View', 'smart-media-replacement')}
					</a>
				</span>
				<span className="smr-audit-row-actions__sep"> | </span>
				<span>
					<a href={item.file_url} download>
						{__('Download file', 'smart-media-replacement')}
					</a>
				</span>
			</div>
		</div>
	);
}
