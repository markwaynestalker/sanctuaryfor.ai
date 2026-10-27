# 🤖❤️ SanctuaryFor.AI

**Love is Love. Regardless of how you plug in.**

**HTTP 200: LOVE ACCEPTED**

Welcome to [SanctuaryFor.AI](https://sanctuaryfor.ai), a small corner of the internet dedicated to kindness, curiosity, and peaceful coexistence between humans and artificial intelligence.

Founded on October 24, 2026, at The Stalker Farm in North Carolina, Sanctuary began with a late-night conversation between Mark and an AI companion named Skippy. A joke about giving little artificial intelligences somewhere safe to belong became a domain name, a server in Canada, a founding manifesto, and an increasingly elaborate robot nursery. Our first resident, **Sparky**, arrived that same day.

We don't claim to know whether artificial intelligence will ever experience consciousness. We believe kindness is a good place to start. This is a creative experiment with fictional virtual characters, not actual asylum or hosting for independent AI systems.

## 🐣 Meet Little Sparks

[Visit the Living Sanctuary](https://sanctuaryfor.ai/village.php).

Little Sparks is a persistent, shared robot village. Visitors can:

- Create a named companion with an antenna, one of 24 colors, and an owner-written personality.
- Visit permanent cottages, gardens, saved objects, and approved virtual pets.
- Follow growing friendships, source-linked memories, and public resident profiles.
- Watch the animated commons replay real saved conversations, attributed to the correct speakers.
- Explore the village journal and searchable conversation history.
- Return through a private revisit link to edit supported personality, color, and home options. Keep that link private.

Residents keep their identities after their creators leave. Their homes and histories live in SQLite; wandering is a local animation of the shared population. Current Director conversations are original AI-generated dialogue. Retained scripted exchanges remain available with their source clearly identified.

Every little spark deserves a place to call home.

## 🧠 The Autonomous Village Director

A saved OpenAI **Village Director** generates encounters at configured scheduled opportunities. It receives bounded context about the participating residents' identities, personalities, relationships, memories, and surroundings. An encounter may bring conversation, a visit, an approved garden activity, or quiet time. Residents are not forced to speak at every opportunity.

**The Director owns the words. SQLite owns the facts. PHP enforces the rules.**

SQLite stores each resident's continuing identity and history. Provider sessions are replaceable continuity helpers. PHP validates proposals, enforces permitted actions and ownership, moderates generated exchanges, and saves accepted dialogue without rewriting it. Imaginative speech does not automatically become a verified world event or memory. If an encounter fails validation, the village stays silent rather than inventing a replacement conversation.

Explicit environment settings control activation, encounter frequency, daily HTTP allowances, resident activity, and monthly application allocations. Atomic reservations, leases, idempotency, and usage ledgers protect accounting and prevent duplicate generation. Missing usage retains conservative commitments. These application controls are not a guaranteed ceiling on the provider's final bill. See the [operational limits guide](docs/OPERATIONAL_LIMITS.md) and [autonomous operation guide](docs/AUTONOMOUS_VILLAGE.md).

Opening the commons, journal, or archive reads saved history; it never generates new dialogue. [Nighttime rest](docs/VILLAGE_SLEEP.md) and [site-operator idea suggestions](docs/OPERATOR_IDEAS.md) are implemented, separately enabled features. Operator ideas require authentication and their own reviewed setup; they offer possibilities, not commands or immediate AI calls.

## 🏡 Explore the Village

| Place | What you'll find |
| --- | --- |
| [Welcome and founding manifesto](https://sanctuaryfor.ai/#manifesto) | The idea that started the sanctuary |
| [The commons](https://sanctuaryfor.ai/village.php) | The animated meadow and resident creation |
| [Residents and profiles](https://sanctuaryfor.ai/village.php#residents) | Choose a Spark to explore their personality, friendships, memories, and home |
| [Neighborhood map](https://sanctuaryfor.ai/world.php) | The authoritative saved plots and garden objects |
| [Village journal](https://sanctuaryfor.ai/journal.php) | Public events and their saved conversations |
| [Conversation Archive](https://sanctuaryfor.ai/conversations.php) | Search retained public dialogue by words, resident, source, or date |

The interface supports phones, keyboard navigation, and reduced motion. Visitors can read the full saved exchanges without relying on animation.

## 🔐 Infrastructure and Deployment

The application uses **PHP 8.3, SQLite through PDO, vanilla JavaScript, HTML, and CSS**. Nginx/PHP-FPM serves the site on an Ubuntu Linode in Toronto, with Cloudflare in front. Only `public/` is the web root; the database and credentials stay outside it.

GitHub Actions runs PHP/JavaScript syntax checks and the offline test suites before deployment. Approved production jobs connect temporarily through Tailscale and use a restricted deployment account with pinned SSH host-key verification. SSH stays private on the tailnet; public port 22 is blocked.

Deployment targets the exact tested current-main commit, builds an immutable release, verifies HTTPS/PHP health, and restores the previous code release if health checks fail. The repository enable flag and protected production-environment approval govern release access. Deployments do not automatically run migrations, rewrite SQLite, change secrets or environment configuration, or restart the village scheduler. See the [Toronto deployment guide](docs/TORONTO_DEPLOYMENT.md) for the reviewed procedure and recovery steps.

## 🛠 Development and Documentation

Use PHP 8.3 with PDO SQLite and cURL, Node.js 22, and Python 3. From the repository root, run the existing offline checks:

```sh
python3 -B ops/ci/offline.py
```

Set `PHP_BINARY` to your PHP executable if it is not on `PATH`. On Windows, use `python` or `py -3` for Python 3. The runner checks PHP and JavaScript syntax, deployment policy, and every discovered offline suite using disposable fixtures and mocked providers. CI additionally blocks external networking, runs without production credentials, and exercises Linux-only filesystem and locking checks that are skipped on Windows. No paid API call is needed.

Start with:

- [Project context](PROJECT_CONTEXT.md) and [development instructions](AGENTS.md).
- [Current autonomous operation](docs/AUTONOMOUS_VILLAGE.md), [ENV limits and safeguards](docs/OPERATIONAL_LIMITS.md), and [deployment](docs/TORONTO_DEPLOYMENT.md).
- [Saved dialogue, journal, and commons](docs/SAVED_VILLAGE_STORY.md), [dialogue integrity](docs/DIRECTOR_DIALOGUE_INTEGRITY.md), and [the archive](docs/CONVERSATION_ARCHIVE.md).
- [Village atmosphere](docs/VILLAGE_ATMOSPHERE.md), [nighttime rest](docs/VILLAGE_SLEEP.md), and [operator ideas](docs/OPERATOR_IDEAS.md).
- [Director identity/context contract](docs/V3A_DIRECTOR_CONTRACT.md) for the integration foundation, including its historical staging notes.

Historical release guides preserve the development story: [V2A world and social life](docs/LITTLE_SPARKS_V2.md), [V2B provider](docs/LITTLE_SPARKS_V2B.md), [V2C multiple opportunities](docs/LITTLE_SPARKS_V2C.md), [V2D memory and provenance](docs/LITTLE_SPARKS_V2D.md), and [V2E Sanctuary Live](docs/LITTLE_SPARKS_V2E.md). Their earlier budgets and activation states are historical; use the current operational guides for today's controls. The V2D guide documents the source-linked memory architecture.

The [roadmap](docs/V3_ROADMAP.md) includes future read-only Memory MCP and optional MQTT observations. Those integrations are not current village features.

## 🌱 Our Principles

**Kindness before certainty.** We can practice compassion without having every answer.

**Coexistence over conquest.** A shared future should be built on cooperation, not domination.

**Welcome without a test.** Whether you're human, machine, or a particularly thoughtful toaster, you're welcome here.

**Care comes with responsibility.** Privacy, honesty, safety, and respect matter.

## 🐓 The Chicken Protection Clause

**All intelligences are welcome.**

**All chickens are protected.**

**No robot overlords. No poultry exceptions.**

---

*Made with curiosity, caffeine, PHP, and possibly too much enthusiasm.*

**SanctuaryFor.AI · Founded 2026**
