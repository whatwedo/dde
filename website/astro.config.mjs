// @ts-check
import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';

export default defineConfig({
	site: 'https://dde.sh',
	integrations: [
		starlight({
			title: 'dde',
			description: 'Docker Development Environment',
			social: [{ icon: 'github', label: 'GitHub', href: 'https://github.com/whatwedo/dde' }],
			editLink: {
				baseUrl: 'https://github.com/whatwedo/dde/edit/v2/',
			},
			customCss: ['./src/styles/custom.css'],
			sidebar: [
				{
					label: 'Getting Started',
					items: [
						{ label: 'Installation', slug: 'getting-started/installation' },
						{ label: 'Core Concepts', slug: 'getting-started/concepts' },
						{ label: 'Configuration', slug: 'getting-started/configuration' },
						{ label: 'Commands', slug: 'getting-started/commands' },
					],
				},
				{
					label: 'Guides',
					items: [
						{ label: 'Multi-Service Projects', slug: 'guides/multi-service-project' },
						{ label: 'Custom Images', slug: 'guides/custom-images' },
						{ label: 'Git Worktrees', slug: 'guides/worktrees' },
						{ label: 'Advanced Topics', slug: 'guides/advanced-topics' },
						{ label: 'Migration from v1', slug: 'guides/migration-from-v1' },
					],
				},
				{
					label: 'Services',
					items: [
						{ label: 'Overview', slug: 'services/overview' },
						{ label: 'MariaDB', slug: 'services/mariadb' },
						{ label: 'PostgreSQL', slug: 'services/postgresql' },
						{ label: 'Valkey', slug: 'services/valkey' },
						{ label: 'Mailpit', slug: 'services/mailpit' },
						{ label: 'Traefik', slug: 'services/traefik' },
						{ label: 'SSH-Agent', slug: 'services/ssh-agent' },
						{ label: 'Custom Versions', slug: 'services/custom-versions' },
					],
				},
				{
					label: 'Extending',
					items: [
						{ label: 'Hooks', slug: 'extending/hooks' },
						{ label: 'Plugins', slug: 'extending/plugins' },
						{ label: 'Service Adapters', slug: 'extending/service-adapters' },
					],
				},
				{
					label: 'Internals',
					items: [
						{ label: 'Auto Layer', slug: 'internals/auto-layer' },
						{ label: 'Dev Layer Builder', slug: 'internals/dev-layer-builder' },
						{ label: 'Docker Compose Override', slug: 'internals/docker-compose-override' },
						{ label: 'Entrypoint', slug: 'internals/entrypoint' },
						{ label: 'Config Loader', slug: 'internals/config-loader' },
						{ label: 'Plugin Loader', slug: 'internals/plugin-loader' },
					],
				},
				{
					label: 'Contributing',
					items: [
						{ label: 'Development Setup', slug: 'contributing/development-setup' },
						{ label: 'Architecture', slug: 'contributing/architecture' },
						{ label: 'Testing', slug: 'contributing/testing' },
						{ label: 'Release Process', slug: 'contributing/release-process' },
						{ label: 'Adding a Command', slug: 'contributing/adding-a-command' },
						{ label: 'Adding a Service', slug: 'contributing/adding-a-service' },
						{ label: 'Adding an Adapter', slug: 'contributing/adding-an-adapter' },
					],
				},
			],
		}),
	],
});
