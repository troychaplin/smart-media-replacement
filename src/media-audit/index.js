import { createRoot } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import App from './App';

apiFetch.use(apiFetch.createNonceMiddleware(window.smrAuditData.restNonce));

const container = document.getElementById('smr-audit-root');
if (container) {
	createRoot(container).render(<App />);
}
