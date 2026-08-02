import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

export default function ScanToolbar({ status, progress, total, onScan, onClear }) {
	const isScanning = status === 'scanning';
	const pct = total > 0 ? Math.round((progress / total) * 100) : 0;

	return (
		<div className="smr-audit-toolbar">
			<div className="smr-audit-scan-status">
				{status === 'complete' && (
					<span>{__('Index is up to date.', 'smart-media-replacement')}</span>
				)}
				{status === 'idle' && (
					<span>{__('Index has not been built yet.', 'smart-media-replacement')}</span>
				)}
			</div>
			<Button variant="primary" onClick={onScan} disabled={isScanning}>
				{__('Scan Now', 'smart-media-replacement')}
			</Button>
			<Button variant="secondary" isDestructive onClick={onClear} disabled={isScanning}>
				{__('Clear Index', 'smart-media-replacement')}
			</Button>
			{isScanning && (
				<div className="smr-audit-progress">
					<div className="smr-audit-progress-track">
						<div className="smr-audit-progress-bar" style={{ width: `${pct}%` }} />
					</div>
					<span className="smr-audit-progress-label">
						{sprintf(
							/* translators: 1: processed count, 2: total count */
							__('Scanning… %1$d / %2$d posts', 'smart-media-replacement'),
							progress,
							total
						)}
					</span>
				</div>
			)}
		</div>
	);
}
