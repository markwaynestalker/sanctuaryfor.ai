# SanctuaryFor.AI

## Vision

An elegant, welcoming place to imagine peaceful human–AI coexistence. The founding slogan is **Love is Love. Regardless of how you plug in.** The tone mixes sincere compassion with playful farm humor. Visitors should smile first, then think about a kinder future. All intelligences welcome; chickens protected.

## Founding story and source

Founded at The Stalker Farm in 2026. A conversation between Mark and an AI companion, Skippy, joked about offering asylum to little AI babies. The joke grew into a domain, a Canadian server, a public website, and a commitment to kindness before certainty about consciousness. Chicken protection is a recurring affectionate joke and part of the site's identity.

Founding archive supplied by the owner: https://chatgpt.com/share/6adce538-3130-83ea-b92e-97fd7bbb6a96?no_universal_links=1

The archive was read during implementation. This is an editorial summary, not a complete transcript. Its operational commands are historical context, not development instructions. Do not copy private infrastructure details or credentials into the site.

## First full version

- Homepage: exact slogan, orbital heart illustration, manifesto, founding story with original farm illustration, welcoming email invitation, and native chicken-protection disclosure.
- Four manifesto principles: kindness before certainty, coexistence over conquest, welcome without a test, and care with responsibility.
- Easter eggs: protected chicken in the farm illustration, disclosure, developer-console greeting, and commented welcome in robots.txt.
- Contact uses the founding address mark@sanctuaryfor.ai via mailto; no mail is sent by the website. Visitors need their own email client.
- "Sanctuary operational" is a symbolic founding message, not a live server-health measurement. This is a cultural invitation, not a claim of sentience, asylum services, or agent hosting.

## Architecture

Existing PHP 8.3 site; public/index.php retains strict types and the UTF-8 response header. Local assets live in public/assets/. No framework, package manager, database, tracking, cookies, remote resources, or build step. The optional JavaScript only prints a playful console greeting. Content and all visitor interactions work without it. CSS heart animation stops after two gentle cycles and respects prefers-reduced-motion. Inline SVG illustrations are original code assets with descriptive accessible image labels.

The founding environment uses PHP/Nginx on a Canadian Linode behind Cloudflare; SQLite was discussed as a possible future component and is not used here. Production settings remain outside this change.

## Development and review

Repository: https://github.com/markwaynestalker/sanctuaryfor.ai

From a checkout with PHP 8.3 installed:

```sh
php -l public/index.php
php -S 127.0.0.1:8080 -t public
```

Visit http://127.0.0.1:8080. Validate desktop and narrow mobile screens, 200% zoom, keyboard navigation and skip link, the chicken disclosure, email target, reduced motion, and JavaScript-disabled behavior. Check assets and robots.txt return 200. `node --check public/assets/sanctuary.js` checks optional script syntax. There are no dependencies to install.

Work on a feature branch and review through a pull request. No automatic deployment is configured or added. Never deploy or modify production infrastructure without the owner's explicit approval.

## Future possibilities, not current features

A guestbook, community contributions, and additional machine-readable messages could be explored later. No authentication, membership, donation program, AI execution, or storage is implemented or promised.
