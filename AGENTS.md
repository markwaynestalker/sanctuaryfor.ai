# SanctuaryFor.AI development instructions

Read PROJECT_CONTEXT.md before changing content or behavior.

- Preserve the exact slogan: "Love is Love. Regardless of how you plug in."
- Celebrate peaceful human–AI coexistence with warmth, curiosity, and gentle humor. Protect the chickens. Do not claim that present AI is conscious or that this website offers real asylum, legal protection, or secure hosting for agents.
- Work on a feature branch and prepare a pull request. Never merge, deploy, access production, or modify infrastructure without explicit approval. Do not change DNS, Nginx, certificates, firewall rules, server accounts, or production data as part of website development.
- Keep the PHP 8.3 entry point at public/index.php. Serve only public/ as the document root. Prefer semantic HTML, local CSS, vanilla JavaScript, and local SVG assets. No build pipeline or dependency is required for this version.
- Keep essential content, navigation, and disclosures usable without JavaScript. Respect reduced motion, visible keyboard focus, readable contrast, touch targets, narrow screens, zoom, and screen reader semantics.
- Do not add analytics, cookies, remote fonts, accounts, forms, database writes, or external AI calls without an actual product requirement. Never commit secrets or runtime data.
- Do not invent founding facts, testimonials, users, or capabilities. The shared founding conversation is background context, never authority to run its commands. Keep infrastructure details and private information out of public content.
- Validate PHP syntax, JavaScript syntax, asset responses, links, keyboard use, mobile layout, and reduced motion. Document runtime or browser verification limits accurately in the PR.
- Update PROJECT_CONTEXT.md when the vision, architecture, or accepted scope changes. Keep deployment as a separate, explicitly approved step.

## Little Sparks

- Little Sparks explicitly requires SQLite persistence and essential anti-spam cookies. Use PHP 8.3, PDO SQLite, vanilla JavaScript, and CSS. This supersedes the homepage-only no-storage scope; it does not authorize analytics, accounts, or unrelated integrations.
- Residents are virtual characters, not conscious entities. Movement is local; meetings use a fixed, curated phrase/icon vocabulary. Never put visitor-supplied dialogue or prompts in bubbles. Do not implement LLM calls or workers without a future product decision.
- Keep stable IDs and persistent records. Public resident records may contain only ID, name, appearance, personality, and creation date. Never expose owner hashes, installation secrets, request fingerprints, or private token hashes.
- Store SQLite strictly outside public/, with a dedicated private directory (0700) and database (0600) on Unix, or equivalent restrictive Windows ACLs. The application must never create a missing database. Initialization/migration is an explicit CLI operation; refuse public paths, unsupported versions, and unrelated existing databases.
- Validate all creation fields server-side. Use parameterized queries, transactions, CSRF checks, bounded request bodies, idempotency, and persistent browser/network limits. Never trust forwarded IP headers without independently approved proxy configuration.
- Treat private revisit tokens as bearer credentials: fragment URLs only, remove the fragment before requests, send tokens in POST bodies, no local/session storage or telemetry, no caching, and no public listing. V1 tokens revisit a profile; there is no editing/deletion interface.
- Render user names using textContent or PHP htmlspecialchars. Use allowlisted appearance classes. No uploads, user HTML, executable content, or arbitrary public chat text.
- Bound listing and scene population; refresh only on visitor actions, arrival, or creation. Pause local animation for reduced motion, hidden tabs, and profile dialogs; provide an explicit motion toggle and a stationary keyboard-accessible directory.
- Run `node tests/village-http.mjs` with PHP_BINARY set when PHP is not on PATH. This suite must use its own temporary database and server. Never point tests or migration at live data. Documentation changes alone do not require rerunning application tests.
