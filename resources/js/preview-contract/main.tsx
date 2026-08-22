import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import App from './App';

const root = document.getElementById('contract-preview-root');
if (!root) throw new Error('contract-preview-root element missing');
const slug = root.dataset.slug;
if (!slug) throw new Error('contract-preview-root data-slug missing');

createRoot(root).render(
    <StrictMode>
        <App slug={slug} />
    </StrictMode>,
);
