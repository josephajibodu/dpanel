# flitops documentation

The public flitops docs, built with [Mintlify](https://mintlify.com). This file, `FREE_SSL.md` and `REALTIME_ARCHITECTURE.md` are internal and listed in `.mintignore`, so they are never published.

## Run locally

Needs Node.js 20.17 or newer.

```bash
npm i -g mint
cd docs
mint dev
```

The site opens at http://localhost:3000. Use `mint dev --port 3333` for another port.

## Check before publishing

```bash
mint validate       # fails on any build warning or error
mint broken-links   # checks links between pages
```

## Structure

- `docs.json`: site settings and the sidebar navigation. A new page must be added here to appear in the sidebar.
- One `.mdx` file per page, grouped in folders matching the sidebar sections.
- `roadmap.mdx`: features that are planned but not available. Move an item out of it, and into the relevant page, when it ships.

## Writing rules

- Describe what users see in the dashboard, in plain language. Explain any technical term the first time it appears.
- Never document internal endpoints, environment variables of the flitops platform itself, secrets, or how security checks work internally.
- When a feature isn't available yet, link to the roadmap instead of describing it as if it works.
