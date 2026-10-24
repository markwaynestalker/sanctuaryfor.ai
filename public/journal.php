<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php'; require __DIR__.'/../app/view.php';
use function LittleSparks\e;
$before=filter_input(INPUT_GET,'before',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
$journal=null;
try { $journal=LittleSparks\journal(LittleSparks\connect((string)getenv('LITTLE_SPARKS_DB')),$before); } catch(Throwable) {}
LittleSparks\pageStart('While you were away');
?>
<section class="world-intro"><p class="eyebrow">THE VILLAGE JOURNAL</p><h1>While you<br><span>were away.</span></h1><p>Small changes with a real saved history. Every entry below comes from an applied world action or a completed village simulation. No invented off-screen adventures.</p></section>
<?php if($journal): ?><ol class="journal-list"><?php foreach($journal['events'] as $entry): ?><li><span class="journal-spark" aria-hidden="true">✧</span><div><p><a href="resident.php?id=<?= e($entry['resident_id']) ?>"><?= e($entry['name']) ?></a> <?= e($entry['description']) ?><?= $entry['target_name'] ? ' with '.e($entry['target_name']) : '' ?>.</p><time datetime="<?= e($entry['created_at']) ?>"><?= e(str_replace(['T','Z'],[' · ',' UTC'],$entry['created_at'])) ?></time></div></li><?php endforeach; ?></ol><?php if(!$journal['events']): ?><p class="notice">No changes have been recorded here yet. A quiet day is welcome, too.</p><?php endif; ?><nav class="pagination" aria-label="Journal pages"><a class="small-button" href="journal.php">Latest entries</a><?php if($journal['nextBefore']): ?><a class="small-button" href="?before=<?= $journal['nextBefore'] ?>">Older entries</a><?php endif; ?></nav><?php else: ?><p class="notice">The journal is resting. Please try again later.</p><?php endif; ?>
<p class="field-help">This is a public world journal. Private records are excluded. Visitor chat is not part of V2A. Daily activities appear only when a caretaker runs the simulation; no timer has been installed.</p>
<?php LittleSparks\pageEnd(); ?>
