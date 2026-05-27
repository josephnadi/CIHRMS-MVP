import DOMPurify from 'dompurify';

const DEFAULT_CONFIG = {
    ALLOWED_TAGS: [
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'p', 'br', 'hr',
        'strong', 'em', 'b', 'i', 'u', 's', 'code', 'pre',
        'ul', 'ol', 'li',
        'blockquote',
        'a',
        'span', 'div',
    ],
    ALLOWED_ATTR: ['href', 'title', 'target', 'rel', 'class'],
    FORBID_ATTR: ['style', 'onerror', 'onload', 'onclick', 'onmouseover', 'onfocus'],
    ALLOW_DATA_ATTR: false,
    SAFE_FOR_TEMPLATES: true,
};

export function useSafeHtml(overrides = {}) {
    const config = { ...DEFAULT_CONFIG, ...overrides };
    return (html) => DOMPurify.sanitize(html ?? '', config);
}
