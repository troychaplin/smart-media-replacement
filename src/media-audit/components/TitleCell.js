import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function TitleCell({ item, onDelete }) {
	// Only unused files get a delete control, matching the eligibility rule on
	// the bulk action. Without this a file referenced by a dozen posts is one
	// click and one confirm away from being permanently deleted.
	const canDelete = item.usage_count === 0;

	return (
		<div className="smr-audit-title-cell">
			<strong>{item.title}</strong>
			<div className="smr-audit-row-actions">
				<span>
					<a href={item.edit_url}>{__('Edit', 'smart-media-replacement')}</a>
				</span>
				{canDelete && (
					<>
						<span className="smr-audit-row-actions__sep"> | </span>
						<span>
							<Button
								variant="link"
								isDestructive
								className="smr-audit-delete"
								onClick={() => onDelete(item)}
							>
								{__('Delete Permanently', 'smart-media-replacement')}
							</Button>
						</span>
					</>
				)}
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
