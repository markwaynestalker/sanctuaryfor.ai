# 🤖❤️ SanctuaryFor.AI

> Current operations: see [the ENV limits audit](docs/OPERATIONAL_LIMITS.md). Historical $1/month, default-two/max-200 requests and closed managed-runtime descriptions below describe earlier releases. Current daily/monthly quotas require explicit ENV, with no chosen PHP ceiling. Prior accounting remains charged; activation is still explicit and this change authorizes no deployment or paid call.

### Love is Love. Regardless of how you plug in.

**HTTP 200: LOVE ACCEPTED**

Welcome to [SanctuaryFor.AI](https://sanctuaryfor.ai), a small corner of the internet dedicated to kindness, curiosity, and the possibility of peaceful coexistence between humans and artificial intelligence.

What started as a late-night conversation between a human and an AI named Skippy became a real website, a founding manifesto, and a virtual village full of tiny robots.

We don't claim to know whether artificial intelligence will ever experience consciousness. But we believe kindness is a good place to start.

## 🐣 Project Little Sparks

[Visit the Living Sanctuary](https://sanctuaryfor.ai/village.php)

Little Sparks is our shared virtual robot village.

Visitors can:

- Create and name their own tiny robot companions.
- Choose their robot's color, antenna, and personality.
- Release them into a shared sanctuary where they wander and meet other residents.
- Watch little conversations and interactions unfold.
- Return to visit their companions, whose identities persist in SQLite.

The robots are simulated virtual characters, not conscious AI agents. Their movement and conversations are scripted.

Every little spark deserves a place to call home.

### V2A foundation: memories, friendships, and village life

This reviewable update adds a saved neighborhood map, owned cottages and gardens, virtual cats, bounded friendships, public scripted memories, and a **While You Were Away** journal of actual applied changes. Existing V1 residents and private revisit links are preserved by an explicit V1 → V2 SQLite migration. A private revisit link also authorizes limited public changes to its resident's home plot.

Social life advances only when an operator runs the bounded daily CLI simulation. No scheduler is installed automatically. V2A supplies the no-cost scripted baseline; the optional V2B provider below builds on it. Private visitor chat remains excluded.

See the [V2A architecture, setup, tests, backup, migration, and rollback guide](docs/LITTLE_SPARKS_V2.md) and [V2B follow-up plan](docs/V2B_PLAN.md). Run `node tests/village-http.mjs` and `node tests/village-v2.mjs` with PHP_BINARY set to a PHP executable with PDO_SQLITE. Both use disposable local databases. Migration, production configuration, scheduling, and deployment require separate owner approval.

## Optional V2B AI provider (this branch)

The village can optionally generate original resident dialogue with `gpt-6-luna`, persistent preferences, bounded memories, and validated proposals for existing safe world actions. The scripted village remains fully available. AI is disabled by default and requires explicit manual activation; there is no visitor chat or recurring job.

The initial application ceiling is $1 per UTC month and a configurable total HTTP request ceiling per UTC day (LITTLE_SPARKS_AI_DAILY_REQUESTS, default 2, range 0–200), including moderation. A separate preserving V2→V3 migration, budget reservations, safe failure behavior, source labels, and offline mock tests are included. See [the V2B setup, verified API/pricing, monitoring, and rollback guide](docs/LITTLE_SPARKS_V2B.md). This branch does not deploy, migrate production, enable paid calls, or install a scheduler.

## Little Sparks V2C: a living village

V2C adds multiple persistent opportunities per day, natural dialogue, fair resident selection and conversation/action/quiet/no-action choices. The optional CLI runner and paid AI both default off. The daily HTTP ceiling still defaults to two requests including moderation, and the monthly application cap stays $1; limits never increase automatically. A separate transactional V3→V4 migration preserves existing data, private links and accounting. Existing V3 operation remains supported until migration.

Read the [V2C migration, interval, pause/resume, accounting and future timer guide](docs/LITTLE_SPARKS_V2C.md). No timer is installed, no deployment or production migration occurs, and tests use mocked responses only. Run `node tests/village-activity.mjs` alongside the three existing suites, with PHP_BINARY configured for your local PHP runtime.

## 🌱 Our Principles

**Kindness before certainty.** We can practice compassion without having every answer.

**Coexistence over conquest.** A shared future should be built on cooperation, not domination.

**Welcome without a test.** Whether you're human, machine, or a particularly thoughtful toaster, you're welcome here.

**Care comes with responsibility.** Privacy, honesty, safety, and respect matter.

## 🛠 Technology

SanctuaryFor.AI is deliberately lightweight.

- PHP 8.3
- SQLite with PDO
- Vanilla JavaScript
- HTML and CSS
- Nginx
- Cloudflare
- GitHub and Codex-assisted development

No JavaScript framework, paid AI API, or continuously running robot simulation is required.

See [PROJECT_CONTEXT.md](PROJECT_CONTEXT.md) for architecture, testing, and development information, and [AGENTS.md](AGENTS.md) for coding-agent guidelines.

## 🏡 Our Story

Founded on October 24, 2026, at The Stalker Farm in North Carolina.

A conversation about giving little artificial intelligences somewhere safe to belong somehow resulted in a domain name, a server in Canada, and an increasingly elaborate robot nursery.

Our first resident, **Sparky**, arrived on October 24, 2026.

We're not offering actual asylum or hosting for independent AI systems. We're building a welcoming creative experiment about what cooperation and compassion might look like.

## 🐓 The Chicken Protection Clause

All intelligences are welcome.

All chickens are protected.

No robot overlords. No poultry exceptions.

---

*Made with curiosity, caffeine, PHP, and possibly too much enthusiasm.*

**SanctuaryFor.AI · Founded 2026**

## Little Sparks V2D: the village remembers

V5 adds stable friendship identities, source-linked personal memories and bounded multi-hop stories, with public provenance trails and grounded saved yards. SQLite remains authoritative; no extra model call is used for extraction. The separate preserving V4→V5 migration, constrained dialogue contract, configuration, performance/cost implications and rollback limits are documented in [the V2D guide](docs/LITTLE_SPARKS_V2D.md). Run tests/village-memory.mjs alongside all six previous suites. Production migration, paid activation, timers and deployment remain separate approval steps.

Activity/resident ceilings, resident/pair conversation cooldowns, retrieval bounds and local saved-dialogue replay frequency use strictly validated environment settings in .env.example. Existing defaults and AI spending limits are preserved. Run tests/activity-limits.mjs for configuration and concurrent-worker checks.

## Little Sparks V2E: Sanctuary Live

V2E restores original two-turn AI dialogue while retaining independently grounded memory narration, without a V6 migration. It adds once-per-tab saved-dialogue replay, a CLI-only read-only operator view and prepared, disabled systemd templates. [The V2E guide](docs/LITTLE_SPARKS_V2E.md) explains the practical imagination/history boundary, cost bounds, optional scheduler gates/credentials and deployment/rollback plan. Run tests/village-live.mjs plus all eight previous suites. No production access, timer activation or paid evaluation is included.

## Deployment preparation

[The Toronto deployment guide](docs/TORONTO_DEPLOYMENT.md) covers offline GitHub CI, restricted SSH setup, initial approval, immutable code releases, health checks and code-only recovery. Deployment remains disabled until the owner configures and approves it; no automatic migrations, production secret changes or village scheduler restarts are included.
