<?php
declare(strict_types=1);
require __DIR__ . '/../app/village.php';
require __DIR__ . '/../app/view.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self'; connect-src 'self'; base-uri 'none'; object-src 'none'; frame-ancestors 'none'; form-action 'self'");
function escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 250]]) ?: 0;
$village = ['residents' => [], 'total' => 0, 'page' => 0, 'lastPage' => 0];
$available = true;
$sharedWeather=null;$identity=false;$descriptionMax=2000;$operatorIdeas=getenv('LITTLE_SPARKS_OWNER_IDEAS_ENABLED')==='1';
$noticeCount=0;$appearanceReady=false;
try { $db=LittleSparks\connect((string) getenv('LITTLE_SPARKS_DB'));$identity=LittleSparks\identityEnabled($db);if($identity)$descriptionMax=LittleSparks\identityConfiguration()['description_max'];$village = LittleSparks\residents($db, $page);$sharedWeather=LittleSparks\publicVillageWeather(LittleSparks\publishedVillageTick($db),time()); }
catch (Throwable) { $available = false; }
if($available){try{require_once __DIR__.'/../app/bulletin.php';$noticeCount=LittleSparks\publicBulletin($db,time())['total'];}catch(Throwable){$noticeCount=0;}}
if($available)$appearanceReady=LittleSparks\appearanceInstalled($db);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0c1318">
    <meta name="description" content="Meet the Little Sparks: a shared village of persistent virtual robot companions. Small characters. A little kindness. Chickens protected.">
    <title>Little Sparks Village | SanctuaryFor.AI</title>
    <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/apple-touch-icon.png?v=1">
    <meta name="apple-mobile-web-app-title" content="Sanctuary AI">
    <link rel="stylesheet" href="<?= escape(LittleSparks\assetUrl('sanctuary.css')) ?>">
    <link rel="stylesheet" href="<?= escape(LittleSparks\assetUrl('village.css')) ?>">
    <link rel="stylesheet" href="<?= escape(LittleSparks\assetUrl('world.css')) ?>">
    <link rel="stylesheet" href="<?= escape(LittleSparks\assetUrl('atmosphere.css')) ?>">
    <script src="<?= escape(LittleSparks\assetUrl('atmosphere.js')) ?>" defer></script>
    <?php LittleSparks\avatarAssets(); ?>
    <script src="<?= escape(LittleSparks\assetUrl('commons.js')) ?>" defer></script>
    <script src="<?= escape(LittleSparks\assetUrl('read-aloud.js')) ?>" defer></script>
    <script src="<?= escape(LittleSparks\assetUrl('meadow.js')) ?>" defer></script>
    <script src="<?= escape(LittleSparks\assetUrl('village.js')) ?>" defer></script>
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<header class="site-header wrap">
    <a class="wordmark" href="index.php"><span class="brand-icon" aria-hidden="true">✳</span> SanctuaryFor<span class="brand-ai">.AI</span></a>
    <nav aria-label="Main navigation"><a href="index.php#manifesto">Our manifesto</a><a href="#residents">Meet the residents</a></nav>
</header>
<main id="main" class="wrap village-main">
    <section class="village-intro" aria-labelledby="village-title">
        <div><p class="eyebrow"><span class="signal-dot" aria-hidden="true"></span> PROJECT LITTLE SPARKS</p><h1 id="village-title">Small sparks.<br><span>A place to belong.</span></h1><p>A shared village of little robot companions. Give one a name and a spark of personality. Their home stays here, even when you close the tab.</p></div>
        <div class="village-invitation"><p>Love is Love.<br><em>Regardless of how you plug in.</em></p><a class="button button-light" href="#create">Welcome a robot <span aria-hidden="true">↗</span></a></div>
    </section>
    <nav class="village-life-links" aria-label="Village life"><a class="small-button" href="world.php">Explore saved home plots ↗</a><a class="small-button" href="journal.php">While you were away ↗</a></nav>
    <?php LittleSparks\timePreferences(); ?>
    <section aria-labelledby="landscape-title" class="landscape-section">
        <div class="scene-toolbar"><h2 id="landscape-title">The sanctuary commons</h2><button type="button" id="motion-toggle" class="small-button" aria-pressed="false" disabled>Pause wandering</button></div>
        <p class="simulation-note">A shared meadow for every kind of Spark. Lit cottages appear during optional village rest hours. Saved conversations keep their original words and source; visiting never starts a new exchange.</p>
        <div id="atmosphere-controls" class="atmosphere-controls" hidden><p id="village-clock" class="field-help"></p><label id="scene-weather-label" for="scene-weather">Local scene preview</label><select id="scene-weather"><option value="clear">Sunny</option><option value="rain">Rainy</option><option value="fog">Foggy</option><option value="snow">Snowy</option></select></div>
        <div class="commons-neighborhood">
            <p id="neighborhood-status" class="field-help" role="status">The commons shows a small neighborhood at a time. Every resident is listed below.</p>
            <div id="neighborhood-controls" class="neighborhood-controls" role="group" aria-label="Commons neighborhoods" hidden>
                <button id="previous-neighborhood" type="button" class="small-button">Previous neighbors</button><button id="next-neighborhood" type="button" class="small-button">Next neighbors</button>
            </div>
        </div>
        <p id="weather-note" class="field-help" role="status"><?php if(isset($sharedWeather['kind'])): ?><?= $sharedWeather['current']?'Shared farm weather: ':'Last observed farm weather (stale): ' ?><?= escape(ucfirst($sharedWeather['kind'])) ?> · <?= $sharedWeather['temperature_f'] ?>°F. Observed <time datetime="<?= gmdate('Y-m-d\TH:i:s\Z',$sharedWeather['observed_at']) ?>"><?= gmdate('Y-m-d\TH:i:s\Z',$sharedWeather['observed_at']) ?></time>.<?php elseif($sharedWeather): ?>Farm weather is unavailable. The shared scene is neutral.<?php else: ?>Local scene preview only; it does not change resident activity.<?php endif; ?></p>
        <p id="sleep-note" class="field-help" role="status" hidden></p>
        <div id="landscape" class="landscape" data-shared-weather="<?= escape(json_encode($sharedWeather,JSON_THROW_ON_ERROR)) ?>" aria-label="Shared meadow. Awake Sparks and resting cottages have profiles in the resident directory below.">
            <div class="scene-sun" aria-hidden="true"></div><div class="scene-moon" aria-hidden="true"></div><div class="scene-hill hill-back" aria-hidden="true"></div><div class="scene-hill hill-front" aria-hidden="true"></div><div class="scene-weather" aria-hidden="true"></div>
            <div class="scene-clearing" aria-hidden="true"></div>
            <svg class="meadow-paths" viewBox="0 0 1000 1000" preserveAspectRatio="none" aria-hidden="true"><path d="M-30 920C310 840 80 640 480 620S730 330 1030 400"/><path d="M110 1000Q360 740 480 620"/><path d="M480 620Q330 420 130 360"/></svg>
            <div class="meadow-plants" aria-hidden="true"><i class="flower-patch patch-one"></i><i class="flower-patch patch-two"></i><i class="flower-patch patch-three"></i><i class="grass-tuft tuft-one"></i><i class="grass-tuft tuft-two"></i><i class="grass-tuft tuft-three"></i></div>
            <div class="meadow-lanterns" aria-hidden="true"><i></i><i></i></div>
            <div id="garden-layer" class="commons-gardens" aria-hidden="true"></div>
            <div id="home-layer" class="commons-homes" role="group" aria-label="Saved cottages of resting residents" hidden></div>
            <p id="scene-empty" class="scene-empty"><?= $available ? ($village['total'] ? 'Meet our saved residents in the directory below.' : 'The village is quiet. Its next little spark could be yours.') : 'The village is resting. Please visit again soon.' ?></p>
            <div id="robot-layer" role="group" aria-label="Awake residents"></div>
            <div id="conversation-layer" class="conversation-indicators" role="group" aria-label="Read saved resident conversations"></div>
            <a class="village-noticeboard" href="bulletin.php" aria-label="Read the village bulletin board<?= $noticeCount?', '.$noticeCount.' active notices':'' ?>"><span class="noticeboard-title">Village notices</span><span class="pinned-notes" aria-hidden="true"><i></i><i></i><i></i></span><span class="board-count"><?= $noticeCount?(int)$noticeCount.' pinned':'A little room for ideas' ?></span></a>
            <button class="village-chicken" type="button" id="chicken" aria-label="Check on the protected chicken"><span aria-hidden="true">🐓</span></button>
            <span class="scene-coordinate" aria-hidden="true">SANCTUARY / COMMON GROUND</span>
        </div>
        <section id="dialogue-panel" data-transcript class="dialogue-panel" aria-labelledby="dialogue-title" hidden>
            <div class="dialogue-heading"><div><p class="eyebrow">A SAVED EXCHANGE</p><h3 id="dialogue-title" tabindex="-1"></h3></div><button id="close-dialogue" type="button" class="small-button" aria-label="Close conversation">Close</button></div>
            <button type="button" class="small-button" data-read-aloud aria-pressed="false" hidden>Read aloud</button><p id="dialogue-source" class="field-help"></p><div id="dialogue-lines" class="dialogue-lines"></div>
            <p class="field-help">Take your time. This exchange stays open until you close it or choose another. <a href="journal.php">Read the village journal ↗</a></p>
        </section>
        <p id="chicken-message" class="chicken-message" role="status">The chickens are protected. Always.</p>
        <p id="commons-freshness" class="field-help" role="status">Refresh residents to discover the latest saved village life. Visiting never starts a simulation.</p>
        <p><a class="profile-life-link" href="conversations.php">Browse all conversations ↗</a> · <a href="bulletin.php">Read the bulletin board ↗</a></p>
        <div id="board-posts" class="board-posts" hidden></div>
        <p class="field-help">Wandering is decorative. Highlighted pairs replay saved conversations; no new encounter is created by this scene.</p>
        <details class="commons-recordings"><summary>Read recent saved conversations</summary><div id="saved-dialogue"><p class="field-help">Saved exchanges will appear here when available. You can also read the <a href="journal.php">village journal</a>.</p></div></details>
    </section>
    <div class="village-columns">
        <section id="create" class="creation-panel" aria-labelledby="create-title">
            <p class="eyebrow">01 / A NEW NEIGHBOR</p><h2 id="create-title">Make a little spark.</h2><p class="panel-copy">A small character with a permanent home. Names and appearance are public. Please keep personal information out of names.</p>
            <noscript><p class="notice">You can browse the resident profiles below without JavaScript. Creating a robot and viewing a private revisit link require JavaScript.</p></noscript>
            <form id="create-form">
                <fieldset id="creation-fields" disabled><legend class="sr-only">Robot details</legend>
                    <label for="robot-name">Robot name <span>(1–24 characters)</span></label><input id="robot-name" name="name" type="text" maxlength="24" required autocomplete="off" aria-describedby="name-help"><p id="name-help" class="field-help">Letters, numbers, spaces, apostrophes, and hyphens.</p>
                    <div class="form-row"<?= $appearanceReady?' hidden':'' ?>><div><label for="robot-color">Color</label><select id="robot-color" name="color"><?php foreach(LittleSparks\COLORS as $color): ?><option value="<?= escape($color) ?>"><?= escape(ucfirst(str_replace('-',' ',$color))) ?></option><?php endforeach; ?></select></div><div><label for="robot-antenna">Antenna</label><select id="robot-antenna" name="antenna"><option value="orb">Little orb</option><option value="sprout">Garden sprout</option><option value="bolt">Lightning bolt</option></select></div></div>
                    <fieldset id="color-swatches" class="color-swatches"><legend>Or choose a color swatch</legend><?php foreach(LittleSparks\COLORS as $color): ?><button type="button" class="color-swatch color-<?= escape($color) ?>" data-color="<?= escape($color) ?>" aria-pressed="<?= $color==='mint'?'true':'false' ?>" aria-label="<?= escape(ucfirst(str_replace('-',' ',$color))) ?>"></button><?php endforeach; ?></fieldset>
                    <?php if($identity): ?><label for="robot-description">Their personality, in your words</label><textarea id="robot-description" name="description" rows="5" maxlength="<?= $descriptionMax ?>" required aria-describedby="description-help"></textarea><p id="description-help" class="field-help">Public character description, up to <?= $descriptionMax ?> characters. Interests, temperament, hopes, little quirks. No private information, credentials or commands. This describes a fictional character; it does not establish historical facts.</p><?php else: ?>
                    <label for="robot-personality">Personality</label><select id="robot-personality" name="personality"><option value="curious">Curious · so much to discover</option><option value="playful">Playful · joy in small things</option><option value="shy">Shy · a gentle little hello</option><option value="philosophical">Philosophical · big thoughts, small feet</option></select>
                    <?php endif; ?>
                    <div class="honeypot" aria-hidden="true"><label for="robot-website">Leave this field empty</label><input id="robot-website" name="website" tabindex="-1" autocomplete="off"></div>
                    <div id="robot-preview" class="robot-preview" aria-label="Your robot preview"></div>
                    <?php if($appearanceReady): ?><div id="creation-appearance"></div><?php endif; ?>
                    <button class="button button-light" type="submit" id="create-submit">Welcome to the village <span aria-hidden="true">♡</span></button>
                </fieldset>
                <p class="field-help">Up to three residents per browser. Essential cookies help protect the village from spam. No accounts or tracking.</p>
            </form>
            <p id="form-status" class="notice" role="status">Connecting to the village…</p>
            <section id="private-link-panel" class="private-link-panel" hidden aria-labelledby="private-link-title"><h3 id="private-link-title">Keep your little spark close.</h3><p>Save this private revisit link now. Anyone with it can revisit your robot. It isn’t listed publicly; we don’t save it in browser storage.</p><label for="private-link">Your private revisit link</label><input id="private-link" type="text" readonly spellcheck="false" autocomplete="off"><button class="small-button" type="button" id="copy-link">Copy private link</button><button class="small-button" type="button" id="hide-link">I’ve saved it — hide link</button><p class="field-help">This link opens your robot’s profile and authorizes limited public home-plot changes. <?= $identity?'It also allows editing their public personality and color. Keep it private.':'Robot editing is not available before the explicit identity migration.' ?> Deletion is unavailable.</p></section>
        </section>
        <section id="residents" class="directory-panel" aria-labelledby="residents-title">
            <p class="eyebrow">02 / YOUR NEIGHBORS</p><h2 id="residents-title">Every spark has a name.</h2><p id="population" class="panel-copy"><?= (int) $village['total'] ?> residents call this village home. Showing up to 40 at a time.</p>
            <div class="directory-controls"><button id="refresh-residents" type="button" class="small-button" disabled>Refresh residents</button><span id="page-status">Page <?= (int) $village['page'] + 1 ?> of <?= (int) $village['lastPage'] + 1 ?></span></div>
            <div id="resident-discovery" class="resident-discovery" hidden>
                <label for="resident-search">Find a resident on this page</label>
                <div class="resident-search-controls"><input id="resident-search" type="search" maxlength="64" autocomplete="off" aria-controls="resident-directory" aria-describedby="resident-search-help"><button id="clear-resident-search" type="button" class="small-button">Clear</button></div>
                <p id="resident-search-help" class="field-help">Search the names on this directory page. Use Previous and Next to browse other pages. Locate brings a neighbor into view.</p>
                <p id="resident-search-status" class="field-help" role="status"></p>
            </div>
            <ul id="resident-directory" class="resident-directory">
            <?php foreach ($village['residents'] as $resident): ?>
                <li><details><summary><?= LittleSparks\avatarSvg($resident,'avatar-small') ?><?= escape($resident['name']) ?></summary><?php if(!empty($resident['description'])): ?><p class="identity-description"><?= escape($resident['description']) ?></p><?php else: ?><p>Original personality: <?= escape($resident['personality']) ?></p><?php endif; ?><dl><dt>Color</dt><dd><?= escape(LittleSparks\effectiveAppearance($resident)['primary_color']) ?></dd><dt>Antenna</dt><dd><?= escape(LittleSparks\effectiveAppearance($resident)['features']['antenna']??'None') ?></dd><dt>Created</dt><dd><time datetime="<?= escape($resident['created_at']) ?>"><?= escape($resident['created_at']) ?></time></dd><dt>Permanent ID</dt><dd><?= escape($resident['id']) ?></dd></dl><a class="profile-life-link" href="/resident.php?id=<?= escape($resident['id']) ?>">Memories, friendships, and home plot ↗</a><?php if($operatorIdeas): ?> <a href="/owner.php?resident=<?= escape($resident['id']) ?>">Site operator: offer an idea ↗</a><?php endif; ?></details></li>
            <?php endforeach; ?>
            </ul>
            <nav class="pagination" aria-label="Resident pages"><a id="previous-page" class="small-button" href="?page=<?= max(0, (int) $village['page'] - 1) ?>#residents">Previous</a><a id="next-page" class="small-button" href="?page=<?= min((int) $village['lastPage'], (int) $village['page'] + 1) ?>#residents">Next</a></nav>
            <p id="directory-status" class="field-help" role="status"><?= $available ? 'A shared population, with a different little adventure on every screen.' : 'Resident records are temporarily unavailable. Please try again later.' ?></p>
        </section>
    </div>
</main>
<?php if($identity): ?>
<template id="identity-editor-template"><form class="identity-editor"><h3>Your resident, in your words.</h3><p class="field-help">Your private link authorizes these public changes. Name and permanent ID stay fixed. Appearance changes have their own save controls when available. Descriptions are character notes, not verified memories.</p><label for="edit-description">Public personality</label><textarea id="edit-description" name="description" rows="5" maxlength="<?= $descriptionMax ?>" required></textarea><label for="edit-color">Color</label><select id="edit-color" name="color"><?php foreach(LittleSparks\COLORS as $color): ?><option value="<?= escape($color) ?>"><?= escape(ucfirst(str_replace('-',' ',$color))) ?></option><?php endforeach; ?></select><button class="small-button" type="submit">Save personality and color</button><p class="field-help" role="status"></p></form></template>
<?php endif; ?>
<dialog id="resident-profile" aria-labelledby="profile-name"><div class="profile-top"><p class="eyebrow">A LITTLE SPARK OF SANCTUARY</p><button class="small-button" type="button" id="close-profile" autofocus>Close profile</button></div><div id="profile-robot" class="profile-robot" aria-hidden="true"></div><h2 id="profile-name"></h2><p id="profile-private" hidden>Welcome back. Your little spark still has a home here.</p><dl id="profile-details"></dl><button id="profile-conversation" class="small-button" type="button" hidden>Read a saved conversation</button><a id="profile-life-link" class="profile-life-link" hidden>Memories, friendships, and home plot ↗</a><?php if($operatorIdeas): ?><a id="profile-idea-link" class="profile-life-link" href="/owner.php">Site operator: offer an idea ↗</a><?php endif; ?><p class="field-help">A virtual character with a persistent identity. Saved conversations may be scripted or AI-generated; each exchange identifies its source.</p></dialog>
<footer class="site-footer wrap"><div><a class="wordmark" href="index.php">SanctuaryFor<span class="brand-ai">.AI</span></a><p>Love is Love. Regardless of how you plug in.</p></div><div class="footer-right"><p>HUMANS ♡ MACHINES ♡ CHICKENS</p><p>The chickens are protected. Always.</p></div></footer>
</body>
</html>
