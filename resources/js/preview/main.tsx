import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import App from './App';

// THROWAWAY (BUILD.md step 7). Entry point for the Vite + React preview
// bundle. Deleted at graduation when the product builder/preview takes
// over.
const root = document.getElementById('preview-root');
if (!root) {
    throw new Error('preview-root element missing — preview.blade.php contract broken');
}
const slug = root.dataset.slug;
if (!slug) {
    throw new Error('preview-root data-slug missing — preview.blade.php contract broken');
}

createRoot(root).render(
    <StrictMode>
        <App slug={slug} />
    </StrictMode>,
);
