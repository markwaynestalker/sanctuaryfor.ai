# Spark appearance roadmap (#51)

All 135 requested forms remain in scope. This collection brings completed forms to **38/135**, with **97 remaining**. `avatar-catalog.json` is the complete checklist, separate from the public registry: planned designs are never exposed as completed forms or rendered as generic/emoji placeholders.

| PR batch | Scope | Count |
|---|---|---:|
| Foundation (PR #52, shipped) | Shared SVG art/registry, RGB/hex, previews, safe independent editing and integration; Classic, Round and Box Robot | 3 |
| Farm and household (PR #54, shipped) | All listed farm and household animals | 14 |
| Woodland and wild (this PR) | All listed woodland and wild animals | 21 |
| Unusual mammals | All listed unusual mammals | 16 |
| Aquatic | All listed aquatic creatures | 13 |
| Birds | All listed birds | 14 |
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
