# Spark appearance roadmap (#51)

All 135 requested forms remain in scope. This collection brings completed forms to **17/135**, with **118 remaining**. `avatar-catalog.json` is the complete checklist, separate from the public registry: planned designs are never exposed as completed forms or rendered as generic/emoji placeholders.

| PR batch | Scope | Count |
|---|---|---:|
| Foundation (PR #52, shipped) | Shared SVG art/registry, RGB/hex, previews, safe independent editing and integration; Classic, Round and Box Robot | 3 |
| Farm and household (this PR) | All listed farm and household animals | 14 |
| Woodland and wild | All listed woodland and wild animals | 21 |
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
