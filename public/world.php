<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php'; require __DIR__.'/../app/view.php';
use function LittleSparks\e;
$sector=filter_input(INPUT_GET,'sector',FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>624]])?:0;
$world=null;
try { $world=LittleSparks\world(LittleSparks\connect((string)getenv('LITTLE_SPARKS_DB')),$sector); } catch(Throwable) {}
LittleSparks\pageStart('A place to put down roots',true);
?>
<section class="world-intro"><p class="eyebrow">LITTLE SPARKS / VILLAGE LIFE</p><h1>A place to<br><span>put down roots.</span></h1><p>Real saved cottages, small gardens, and friendships that have a history. Virtual characters, growing a world one gentle change at a time.</p><a class="text-link" href="journal.php">Read the village journal ↗</a></section>
<section aria-labelledby="map-title"><div class="scene-toolbar"><h2 id="map-title">The neighborhood map</h2><a class="small-button" href="world.php?sector=<?= $sector ?>">Refresh saved world</a></div><p class="field-help">Neighborhood <?= $sector+1 ?> of 625 · 16 × 16 cells. Map positions are saved in SQLite; wandering in the commons remains a local animation.</p>
<?php if ($world): ?>
<div id="saved-map" class="saved-map" role="img" aria-label="Saved neighborhood map. The accessible plot list below describes its residents and objects.">
<?php foreach ($world['plots'] as $plot): $px=($plot['x']-$world['origin']['x'])/16*100; $py=($plot['y']-$world['origin']['y'])/16*100; ?>
<span class="map-plot" style="left:<?= $px ?>%;top:<?= $py ?>%"><span><?= e($plot['resident']['name']) ?></span></span>
<?php endforeach; ?>
<?php $icons=['house'=>'⌂','tree'=>'🌳','flowers'=>'🌷','bush'=>'🌿','path'=>'·','decoration'=>'🏮','pet'=>'🐈']; foreach($world['objects'] as $object): ?>
<span class="map-object kind-<?= e($object['kind']) ?>" style="left:<?= ($object['x']-$world['origin']['x']+.5)/16*100 ?>%;top:<?= ($object['y']-$world['origin']['y']+.5)/16*100 ?>%" title="<?= e($object['variant']) ?> at <?= (int)$object['x'] ?>, <?= (int)$object['y'] ?>"><?= $object['kind']==='house' && $object['variant']==='stars' ? '✫' : $icons[$object['kind']] ?></span>
<?php endforeach; ?>
<?php if(!$world['plots']): ?><p class="map-empty">A quiet patch of sanctuary.<br>No home plots are assigned here yet.</p><?php endif; ?>
</div>
<p class="field-help">Map origin: <?= (int)$world['origin']['x'] ?>, <?= (int)$world['origin']['y'] ?> · ⌂ cottage / ✫ star cottage · 🌳 tree · 🌷 flowers · 🌿 bush · · stone path · 🏮 lantern · 🐈 virtual cat</p>
<ul id="plot-list" class="plot-list"><?php foreach($world['plots'] as $plot): ?><li><a href="resident.php?id=<?= e($plot['resident']['id']) ?>"><?= e($plot['resident']['name']) ?>’s plot ↗</a><span>Cells <?= $plot['x'] ?>–<?= $plot['x']+3 ?>, <?= $plot['y'] ?>–<?= $plot['y']+3 ?></span><ul><?php foreach($world['objects'] as $object): if($object['resident_id']!==$plot['resident']['id']) continue; ?><li><?= e($object['variant'].' '.$object['kind']) ?> at <?= (int)$object['x'] ?>, <?= (int)$object['y'] ?></li><?php endforeach; ?></ul></li><?php endforeach; ?></ul>
<?php else: ?><p class="notice">The saved world is resting. A caretaker may need to run the approved V2 migration.</p><?php endif; ?>
<nav class="pagination" aria-label="Map neighborhoods"><a class="small-button" href="?sector=<?= max(0,$sector-1) ?>">Previous neighborhood</a><a class="small-button" href="?sector=<?= min(624,$sector+1) ?>">Next neighborhood</a></nav>
<form class="sector-form" action="world.php" method="get"><label for="sector">Go to neighborhood index (0–624)</label><input id="sector" name="sector" type="number" min="0" max="624" value="<?= $sector ?>"><button class="small-button">Go</button></form></section>
<section class="garden-manager" aria-labelledby="garden-title"><p class="eyebrow">A LITTLE CARE, SAVED FOR TOMORROW</p><h2 id="garden-title">Tend your resident’s home.</h2><p>Use the private revisit link you saved at creation. Anyone holding it can now make these limited, public garden changes. Never share it publicly.</p><noscript><p class="notice">JavaScript is required for private actions. Saved plots, public profiles, and the journal work without it.</p></noscript>
<form id="unlock-form"><label for="revisit-link">Private revisit link</label><input id="revisit-link" type="password" required autocomplete="off" spellcheck="false"><button type="submit" class="small-button">Open my garden</button></form>
<p id="garden-status" role="status" class="notice">No private link is stored in this browser. It is kept in page memory only while your garden is open.</p>
<div id="garden-controls" hidden><h3 id="garden-resident"></h3><p id="garden-bounds"></p><a id="my-neighborhood" class="small-button">View my neighborhood</a><button id="lock-garden" type="button" class="small-button">Close private garden</button>
<form id="action-form"><label for="garden-action">A small act of care</label><select id="garden-action" name="action"><option value="plant_tree">Plant an oak tree</option><option value="plant_flowers">Plant wildflowers</option><option value="plant_bush">Plant a berry bush</option><option value="decorate_house">Decorate cottage with stars</option><option value="build_path">Lay a stone path</option><option value="place_decoration">Place a garden lantern</option><option value="adopt_pet">Adopt a virtual cat</option><option value="visit_friend">Visit a neighbor</option></select><div id="coordinate-fields" class="form-row"><div><label for="garden-x">Map X</label><input id="garden-x" name="x" type="number" min="0" max="399" required></div><div><label for="garden-y">Map Y</label><input id="garden-y" name="y" type="number" min="0" max="399" required></div></div><div id="neighbor-fields" hidden><label for="neighbor-id">Neighbor’s permanent resident ID</label><input id="neighbor-id" name="target" type="text" maxlength="32" autocomplete="off"></div><p class="field-help">Six successful changes per resident per UTC day. Twelve objects per plot, one virtual cat, and one visit to a particular neighbor per day. Changes are public and cannot be undone in this version.</p><button id="apply-action" class="button button-light">Save this little change ♡</button></form></div></section>
<?php LittleSparks\pageEnd(); ?>
