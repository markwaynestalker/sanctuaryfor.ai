<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php';require __DIR__.'/../app/gazette_editor_publication.php';
try{$j=json_decode(file_get_contents($argv[1]),true,16,JSON_THROW_ON_ERROR);$db=LittleSparks\connect($j['database']);$db->exec('PRAGMA query_only=ON');
    if(($argv[2]??null)==='audit')echo count(LittleSparks\editorialManifests($j['database'],LittleSparks\gazetteDay('2026-10-29',$j['now'])));
    else LittleSparks\publishEditorialDraft($db,$j['database'],$j['attempt'],$j['sha'],$j['expected'],$j['now']);
}catch(Throwable){exit(42);}
