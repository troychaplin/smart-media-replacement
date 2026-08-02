export default function ThumbnailCell({ item }) {
	if (item.thumbnail_url) {
		return (
			<img
				src={item.thumbnail_url}
				alt=""
				width={60}
				height={60}
				className="smr-audit-thumb"
			/>
		);
	}
	return (
		<span
			className="dashicons dashicons-media-default smr-audit-thumb-icon"
			aria-hidden="true"
		/>
	);
}
