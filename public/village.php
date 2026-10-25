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
try { $village = LittleSparks\residents(LittleSparks\connect((string) getenv('LITTLE_SPARKS_DB')), $page); }
catch (Throwable) { $available = false; }
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
    <link rel="stylesheet" href="<?= escape(LittleSparks\assetUrl('sanctuary.css')) ?>">
    <link rel="stylesheet" href="<?= escape(LittleSparks\assetUrl('village.css')) ?>">
    <link rel="stylesheet" href="<?= escape(LittleSparks\assetUrl('world.css')) ?>">
    <script src="<?= escape(LittleSparks\assetUrl('commons.js')) ?>" defer></script>
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
    <section aria-labelledby="landscape-title" class="landscape-section">
        <div class="scene-toolbar"><h2 id="landscape-title">The sanctuary commons</h2><button type="button" id="motion-toggle" class="small-button" aria-pressed="false" disabled>Pause wandering</button></div>
        <p class="simulation-note">Virtual characters, real saved identities. Wandering is local to your screen. Meetings replay saved public conversations, labeled AI-generated or scripted; visiting never generates new dialogue. Each cottage has a yard showing its actual saved improvements. The arrangement is decorative; the neighborhood map preserves real plot locations. These robots are not conscious AI.</p>
        <div id="landscape" class="landscape" aria-label="Village landscape; each robot has a profile in the resident directory below">
            <div class="scene-moon" aria-hidden="true"></div><div class="scene-hill hill-back" aria-hidden="true"></div><div class="scene-hill hill-front" aria-hidden="true"></div>
            <div id="home-layer" class="commons-homes" role="group" aria-label="Saved resident cottages"></div>
            <p id="scene-empty" class="scene-empty"><?= $available ? ($village['total'] ? 'Meet our saved residents in the directory below.' : 'The village is quiet. Its next little spark could be yours.') : 'The village is resting. Please visit again soon.' ?></p>
            <div id="robot-layer"></div>
            <button class="village-chicken" type="button" id="chicken" aria-label="Check on the protected chicken"><span aria-hidden="true">🐓</span></button>
            <span class="scene-coordinate" aria-hidden="true">SANCTUARY / COMMON GROUND</span>
        </div>
        <p id="chicken-message" class="chicken-message" role="status">The chickens are protected. Always.</p>
        <p id="commons-freshness" class="field-help" role="status">Refresh residents to discover the latest saved village life. Visiting never starts a simulation.</p>
        <details class="commons-recordings"><summary>Read recent saved conversations</summary><div id="saved-dialogue"><p class="field-help">Saved exchanges will appear here when available. You can also read the <a href="journal.php">village journal</a>.</p></div></details>
    </section>
    <div class="village-columns">
        <section id="create" class="creation-panel" aria-labelledby="create-title">
            <p class="eyebrow">01 / A NEW NEIGHBOR</p><h2 id="create-title">Make a little spark.</h2><p class="panel-copy">A small character with a permanent home. Names and appearance are public. Please keep personal information out of names.</p>
            <noscript><p class="notice">You can browse the resident profiles below without JavaScript. Creating a robot and viewing a private revisit link require JavaScript.</p></noscript>
            <form id="create-form">
                <fieldset id="creation-fields" disabled><legend class="sr-only">Robot details</legend>
                    <label for="robot-name">Robot name <span>(1–24 characters)</span></label><input id="robot-name" name="name" type="text" maxlength="24" required autocomplete="off" aria-describedby="name-help"><p id="name-help" class="field-help">Letters, numbers, spaces, apostrophes, and hyphens.</p>
                    <div class="form-row"><div><label for="robot-color">Color</label><select id="robot-color" name="color"><option value="mint">Mint</option><option value="lavender">Lavender</option><option value="peach">Peach</option><option value="sky">Sky</option><option value="gold">Gold</option></select></div><div><label for="robot-antenna">Antenna</label><select id="robot-antenna" name="antenna"><option value="orb">Little orb</option><option value="sprout">Garden sprout</option><option value="bolt">Lightning bolt</option></select></div></div>
                    <label for="robot-personality">Personality</label><select id="robot-personality" name="personality"><option value="curious">Curious · so much to discover</option><option value="playful">Playful · joy in small things</option><option value="shy">Shy · a gentle little hello</option><option value="philosophical">Philosophical · big thoughts, small feet</option></select>
                    <div class="honeypot" aria-hidden="true"><label for="robot-website">Leave this field empty</label><input id="robot-website" name="website" tabindex="-1" autocomplete="off"></div>
                    <div id="robot-preview" class="robot-preview" aria-label="Your robot preview"></div>
                    <button class="button button-light" type="submit" id="create-submit">Welcome to the village <span aria-hidden="true">♡</span></button>
                </fieldset>
                <p class="field-help">Up to three residents per browser. Essential cookies help protect the village from spam. No accounts or tracking.</p>
            </form>
            <p id="form-status" class="notice" role="status">Connecting to the village…</p>
            <section id="private-link-panel" class="private-link-panel" hidden aria-labelledby="private-link-title"><h3 id="private-link-title">Keep your little spark close.</h3><p>Save this private revisit link now. Anyone with it can revisit your robot. It isn’t listed publicly; we don’t save it in browser storage.</p><label for="private-link">Your private revisit link</label><input id="private-link" type="text" readonly spellcheck="false" autocomplete="off"><button class="small-button" type="button" id="copy-link">Copy private link</button><button class="small-button" type="button" id="hide-link">I’ve saved it — hide link</button><p class="field-help">This link opens your robot’s profile. It also authorizes limited public home-plot changes. Robot editing and deletion are not available.</p></section>
        </section>
        <section id="residents" class="directory-panel" aria-labelledby="residents-title">
            <p class="eyebrow">02 / YOUR NEIGHBORS</p><h2 id="residents-title">Every spark has a name.</h2><p id="population" class="panel-copy"><?= (int) $village['total'] ?> residents call this village home. Showing up to 40 at a time.</p>
            <div class="directory-controls"><button id="refresh-residents" type="button" class="small-button" disabled>Refresh residents</button><span id="page-status">Page <?= (int) $village['page'] + 1 ?> of <?= (int) $village['lastPage'] + 1 ?></span></div>
            <ul id="resident-directory" class="resident-directory">
            <?php foreach ($village['residents'] as $resident): ?>
                <li><details><summary><?= escape($resident['name']) ?> · <?= escape($resident['personality']) ?></summary><dl><dt>Color</dt><dd><?= escape($resident['color']) ?></dd><dt>Antenna</dt><dd><?= escape($resident['antenna']) ?></dd><dt>Created (UTC)</dt><dd><?= escape($resident['created_at']) ?></dd><dt>Permanent ID</dt><dd><?= escape($resident['id']) ?></dd></dl><a class="profile-life-link" href="/resident.php?id=<?= escape($resident['id']) ?>">Memories, friendships, and home plot ↗</a></details></li>
            <?php endforeach; ?>
            </ul>
            <nav class="pagination" aria-label="Resident pages"><a id="previous-page" class="small-button" href="?page=<?= max(0, (int) $village['page'] - 1) ?>#residents">Previous</a><a id="next-page" class="small-button" href="?page=<?= min((int) $village['lastPage'], (int) $village['page'] + 1) ?>#residents">Next</a></nav>
            <p id="directory-status" class="field-help" role="status"><?= $available ? 'A shared population, with a different little adventure on every screen.' : 'Resident records are temporarily unavailable. Please try again later.' ?></p>
        </section>
    </div>
</main>
<dialog id="resident-profile" aria-labelledby="profile-name"><div class="profile-top"><p class="eyebrow">A LITTLE SPARK OF SANCTUARY</p><button class="small-button" type="button" id="close-profile" autofocus>Close profile</button></div><div id="profile-robot" class="profile-robot" aria-hidden="true"></div><h2 id="profile-name"></h2><p id="profile-private" hidden>Welcome back. Your little spark still has a home here.</p><dl id="profile-details"></dl><a id="profile-life-link" class="profile-life-link" hidden>Memories, friendships, and home plot ↗</a><p class="field-help">A virtual character with a persistent identity. Saved conversations may be scripted or AI-generated; each exchange identifies its source.</p></dialog>
<footer class="site-footer wrap"><div><a class="wordmark" href="index.php">SanctuaryFor<span class="brand-ai">.AI</span></a><p>Love is Love. Regardless of how you plug in.</p></div><div class="footer-right"><p>HUMANS ♡ MACHINES ♡ CHICKENS</p><p>The chickens are protected. Always.</p></div></footer>
</body>
</html>
