# Spark appearance roadmap (#51)

All 135 requested forms remain in scope. This collection brings completed forms to **81/135**, with **54 remaining**. `avatar-catalog.json` is the complete checklist, separate from the public registry: planned designs are never exposed as completed forms or rendered as generic/emoji placeholders.

| PR batch | Scope | Count |
|---|---|---:|
| Foundation (PR #52, shipped) | Shared SVG art/registry, RGB/hex, previews, safe independent editing and integration; Classic, Round and Box Robot | 3 |
| Farm and household (PR #54, shipped) | All listed farm and household animals | 14 |
| Woodland and wild (PR #55, shipped) | All listed woodland and wild animals | 21 |
| Unusual mammals (PR #56, shipped) | All listed unusual mammals | 16 |
| Aquatic (PR #57, shipped) | All listed aquatic creatures | 13 |
| Birds (this PR) | All listed birds | 14 |
| Reptiles and small creatures | All listed reptiles, amphibians, insects and small creatures | 17 |
| Mythical | All listed mythical/fantasy creatures | 13 |
| Remaining forms | Four remaining robots, plants, celestial/elements and objects | 24 |

These are review-sized starting groups, not a requirement to combine artwork that needs separate review. Every accepted form needs a distinct recognizable SVG symbol, appropriate features, a registry entry and thumbnail, black/white/saturated color and small-size checks, mobile/keyboard tests, and visual review. Adding a form must not change identity, personality, ownership, saved conversations, memories, bulletin records, Director context/session selection, accounting or scheduling. No outside art service, paid generation, external asset library or user SVG is allowed. Do not close #51 when only the foundation lands.

## Farm and household collection

Nine farm forms: Goat, Donkey, Pig, Sheep, Cow, Horse, Chicken, Duck and Goose. Five household forms: Dog, Cat, Hamster, Mouse and Parrot. These are authored SVG designs with distinct silhouettes and recognizable ears, muzzles, horns/beard, wool, beaks/necks, cheeks, wings and tails. All 24 existing presets and valid RGB colors work; normal details remain readable in light/dark colors, and forced-color presentation uses the existing system-color overrides. The sprite/registry are shared by every appearance display. The protected commons chicken remains a separate existing object.

Animals have no robot antenna feature. The selector hides that control and restores the unsaved robot antenna choice when toggling back in the same picker, without putting inactive robot features into animal records. Existing saved identity, base antenna, personality and history remain untouched. A malformed animal appearance with antenna properties is rejected by PHP and safely falls back to the original classic appearance in both renderers. No form assigns a job, interest, preference or personality.

This batch needs no migration, ENV edit, new permission, Director configuration, scheduler change or paid test. It is a code-only collection on the already-installed appearance foundation; usual exact-SHA CI/production approval still applies. Do not deploy automatically during development. The artifact review covers actual large/meadow-sized shared rendering plus black, white and saturated variants; bounds stay within the existing 48x70 footprint. The off-line registry, PHP/DOM feature validation, persistence and history-preservation tests are required alongside the full suite. Physical iPhone verification remains an owner check.

## Woodland and wild collection

Eight woodland forms: Rabbit, Fox, Raccoon, Squirrel, Deer, Bear, Hedgehog and Owl. Thirteen wild forms: Panda, Red Panda, Wolf, Leopard, Tiger, Lion, Monkey, Gorilla, Orangutan, Wild Boar, Moose, Bison and Panther. Each has authored local SVG artwork: long ears, cheek ruffs, masks/ringed tails, acorns, branching or palmate antlers, woolly/spiny silhouettes, big eyes, spots/stripes, manes, long arms, tusks and sleek tails distinguish the forms. The existing 17 designs remain unchanged.

The registry now exposes 38 completed forms. All new animals have empty feature sets and use the existing picker, category/search, color presets/RGB, safe feature validation and private appearance revision/audit. No runtime PHP, scene geometry, migration, ENV, Director context, session, scheduler or accounting changes are required. Choosing an animal never assigns personality, interests or a job.

Run the full offline suite, including picker-event/thumbnail/search tests for both collections, all 35 animal forms across presets/RGB, invalid-feature fallback and private save/history-preservation round trips. The exhaustive fixture explicitly increases its own disposable edit allowance; production defaults are unchanged and the fixture still verifies exhaustion. Visually check every form at large/meadow size in black/white/saturated colors and verify desktop/390px/320px controls, reduced motion and links. Physical iPhone/Safari verification remains an owner check. Code-only release after normal review; no automatic production action. Keep #51 open for the remaining 97 forms.

## Unusual mammal collection

Sixteen forms: Beaver, Llama, Alpaca, Kangaroo, Koala, Sloth, Skunk, Badger, Bat, Elephant, Giraffe, Zebra, Rhinoceros, Hippopotamus, Capybara and Platypus. Distinct authored SVGs use paddle tails/teeth, long ears/necks, wool, a pouch, fluffy ears/nose, hanging arms/claws, a raised striped tail, face stripes, scalloped wings, a trunk, spots, stripes, horns and broad muzzles/bills. All 38 previously shipped artworks are unchanged.

The shared picker/renderer already supplies category/search, real thumbnails, RGB/24 presets, animal feature restrictions, remembered robot antennas and safe private edits. No runtime PHP/JavaScript, scene geometry, database migration, ENV, Director/session, scheduler or accounting changes are needed. Appearance never assigns a personality or occupation; the protected commons chicken remains separate.

Check every new form at large/meadow sizes in black, white and saturated colors, SVG bounds, desktop/390px/320px keyboard/category/search/color/antenna behavior, private saves and public profiles. Run all offline suites: coverage now includes all 51 animals across presets/RGB, the unusual collection's picker events and unchanged history during transformations. The exhaustive disposable fixture uses its own explicit edit allowance and still verifies exhaustion; application defaults stay unchanged. Physical iPhone/Safari verification remains an owner check. Code-only release after normal review; no automatic production action. Keep #51 open for the remaining 81 designs.

## Aquatic collection

Thirteen forms: Otter, Seal, Dolphin, Shark, Whale, Jellyfish, Crab, Lobster, Pufferfish, Fish, Axolotl, Starfish and Octopus. Distinct authored SVGs use whiskers/paws, flippers, beaks/dorsal fins/flukes, a broad whale belly/spout, tentacles, pincers/legs, segmented tails, spines, fins/scales, feathery gills, five arms and eight curling arms with suckers. Dark tentacles and gills have contrasting outlines; the existing 54 forms and antenna art remain unchanged.

These are cosmetic Spark appearances, not new water areas or swimming physics. All share the existing 48x70 footprint, empty animal feature sets, 24 presets/RGB, category/search, native keyboard controls and private edits. No personality inference, runtime PHP/JavaScript, geometry, migration, ENV, Director/session, scheduler or accounting changes are needed. The protected commons chicken remains separate.

Check every form at large/meadow sizes in black, white and saturated colors, SVG bounds, desktop/390px/320px controls, reduced motion, private saves and public profiles. Offline coverage now includes all 64 animal forms and aquatic picker events/search/thumbnails. The exhaustive disposable fixture sets both its daily and hourly edit allowances explicitly, tests daily exhaustion, idempotency and hourly exhaustion, then verifies the unchanged 24/60 application defaults. Run all offline suites. Physical iPhone/Safari verification remains an owner check. Code-only release after normal review; no automatic production action. The #51 roadmap retains 68 unbuilt forms even if the umbrella issue has been closed.

## Bird collection

Fourteen forms: Peacock, Flamingo, Eagle, Turkey, Dove, Crow, Swan, Dodo, Hummingbird, Pelican, Toucan, Puffin, Ostrich and Penguin. Authored SVGs use eye-spotted fans, an S-neck/folded leg/dark-tipped bill, feathered wings/talons, striped fans/wattle, lifted rounded wings, a wedge tail, a graceful neck, a heavy bill, hovering wings, a bill pouch, an oversized beak, cheek masks, long legs and an oval belly. The flamingo honors any chosen color; pink is a preview choice, not a stored color override. All 67 previous forms and three antenna symbols remain unchanged, including the separate farm birds and woodland owl.

Birds use empty animal feature sets and the shared 48x70 footprint, renderer/picker, RGB/24 presets, category/search, native keyboard controls and private appearance edits. They add no flight physics, extra animation loop, inferred personalities, Director context or new behavior. The protected commons chicken remains separate.

Check every form at large/meadow sizes in black, white and saturated colors, bounds including stroke margin, desktop/390px/320px controls, reduced motion, private saves and public profiles. Offline coverage includes all 78 animal forms plus bird membership, thumbnails, selection and case-insensitive Flamingo search. The existing exhaustive disposable fixture configures both edit allowances, verifies exhaustion/replay and restores/checks unchanged application defaults. Run all offline suites. Physical iPhone/Safari verification remains an owner check. No runtime PHP/JavaScript, migration, ENV, Director/session, scheduler or accounting changes; no automatic production action. The #51 roadmap retains 54 unbuilt forms even if the umbrella issue is closed.
