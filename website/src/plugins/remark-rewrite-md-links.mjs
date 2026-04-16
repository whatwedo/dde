// @ts-check
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

function safeRealpath(p) {
	try {
		return fs.realpathSync(p);
	} catch {
		return p;
	}
}

// website/src/plugins -> website/src/content/docs. The docs/ folder is
// symlinked into the content collection, so realpath both ends to make
// path comparisons against `vfile.path` work whether Astro reports the
// symlink path or the resolved path.
const DOCS_ROOT = safeRealpath(path.resolve(__dirname, '..', 'content', 'docs'));

/**
 * Remark plugin that rewrites relative Markdown links (e.g.
 * `../getting-started/installation.md`) to clean Starlight URLs
 * (e.g. `/getting-started/installation/`).
 *
 * Why: keeping `.md` extensions in the source lets the same files
 * render correctly when browsed directly on GitHub. At build time
 * we then turn them into the URL shape Starlight ships.
 *
 * Behavior:
 *   - Only relative links to `.md` / `.mdx` files inside the docs
 *     content collection are rewritten.
 *   - External, protocol-relative, anchor-only, and site-absolute
 *     links are left untouched.
 *   - Anchors and query strings are preserved.
 *   - `index.md` collapses to the parent directory's URL.
 */
export function remarkRewriteMdLinks() {
	return function transform(tree, file) {
		const sourcePath = file?.history?.[0] ?? file?.path;
		if (!sourcePath) return;

		const sourceDir = path.dirname(safeRealpath(path.resolve(sourcePath)));

		function rewrite(url) {
			if (typeof url !== 'string' || url.length === 0) return null;
			// Skip anything that isn't a relative .md link
			if (/^([a-z][a-z0-9+.-]*:|\/\/|#|\/)/i.test(url)) return null;

			const match = /^([^?#]+?)\.(mdx?)([?#].*)?$/i.exec(url);
			if (!match) return null;
			const [, base, ext, suffix = ''] = match;

			const targetFile = path.resolve(sourceDir, `${base}.${ext}`);
			const rel = path.relative(DOCS_ROOT, targetFile);
			// Ignore links that escape the docs collection
			if (rel.startsWith('..') || path.isAbsolute(rel)) return null;

			let slug = rel.replace(/\\/g, '/').replace(/\.mdx?$/i, '');
			if (slug === 'index') return `/${suffix}`;
			if (slug.endsWith('/index')) slug = slug.slice(0, -'/index'.length);
			return `/${slug}/${suffix}`;
		}

		function visit(node) {
			if (node && node.type === 'link') {
				const next = rewrite(node.url);
				if (next !== null) node.url = next;
			}
			if (node && Array.isArray(node.children)) {
				for (const child of node.children) visit(child);
			}
		}

		visit(tree);
	};
}
