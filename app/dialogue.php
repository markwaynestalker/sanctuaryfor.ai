<?php
declare(strict_types=1);
namespace LittleSparks;

/** Expressive prose is never evidence of saved village history. */
function expressiveDialogue(string $line,array $residentNames=[]):string
{
    $line=safeAiDialogue($line);
    assertExpressiveClaims($line,$residentNames);
    return $line;
}
/** Shared claim guard; callers validate their own text length without rewriting words. */
function assertExpressiveClaims(string $line,array $residentNames=[]):void
{
    // English letters, ordinary punctuation and emoji; reject homoglyph tricks.
    if(preg_match('/[^\p{Latin}\p{N}\p{P}\p{S}\p{Z}]/u',$line))throw new AiGuard('invalid_proposal');
    $normalize=static fn(string $text):string=>str_replace(['’','‘'],"'",$text);
    $text=strtolower($normalize($line));
    $objects='trees?|bush(?:es)?|flowers?|gardens?|pets?|cats?|cottages?|houses?|paths?|lanterns?|plots?|snowm[ae]n';
    $completed='planted|sowed|sown|seeded|built|constructed|erected|installed|crafted|fashioned|placed|decorated|adopted|watered|grew|grown|harvested|created|added|removed|moved|visited|gifted';
    $actions='plant|sow|build|construct|install|place|decorate|adopt|water|grow|create|add|remove|move';
    $ongoing='planting|sowing|building|constructing|installing|placing|decorating|adopting|watering|growing|creating|adding|removing|moving';
    // Names come from bounded public server context, never a model assertion.
    $names=array_map(static fn(string $name):string=>preg_quote($normalize($name),'~'),array_slice($residentNames,0,64));
    $neighbor='you|we|they|he|she|a neighbor|a friend|our neighbors?'.($names?'|'.implode('|',$names):'');
    $subject='i|you|we|they|he|she|[a-z]+(?: jr\.?)?';
    // Clause-scoped propositions, not blanket bans on ordinary past grammar.
    // Questions, quotes and speculation do not exempt embedded world claims.
    $patterns=[
        // Completed persistent actions, including direct past action questions.
        '\b(?:'.$completed.')\b[^.!?;]{0,45}\b(?:'.$objects.')\b',
        '\bdid(?:n\x27t| not)?\s+(?:'.$subject.')\s+(?:(?:just|ever|really|actually)\s+)?(?:'.$actions.')\b[^.!?;]{0,35}\b(?:'.$objects.')\b',
        '\b(?:was|were)\s+(?:(?:'.$subject.')\s+)?(?:(?:just|really|actually)\s+)?(?:'.$ongoing.')\b[^.!?;]{0,35}\b(?:'.$objects.')\b',
        // Claimed witnesses and false memories of persistent actions.
        '\b(?:saw|watched|witnessed|heard)\b[^.!?;]{0,35}\b(?:'.$actions.'|'.$ongoing.')\b[^.!?;]{0,35}\b(?:'.$objects.')\b',
        '\b(?:remember|recall)(?:ed)?\s+(?:when|watching|seeing|hearing|'.$ongoing.'|'.$neighbor.')\b[^.!?;]{0,45}\b(?:'.$objects.')\b',
        // Ownership/state assertions, rather than bare mentions of a garden.
        '\b(?:have|has|had|own|owns|owned)\s+(?:(?:a|an|the|one|two|three|new|little|virtual)\s+){0,3}(?:'.$objects.')\b',
        '\bmade\s+(?:(?:a|an|the|my|your|our|new|little|stone)\s+){0,3}(?:'.$objects.')\b',
        '\b(?:my|your|our|their|his|her|[a-z]+\x27s)\s+(?:little\s+|new\s+|old\s+)*(?:'.$objects.')\s+(?:is|was|has|had|grew)\b',
        // Reported resident speech and shared encounters require saved sources.
        '\b(?:told|promised)\s+(?:me|you|us|him|her|them)\b',
        '\bheard\s+(?:that\s+)?(?:'.$neighbor.')\b',
        '\b(?:remember|recall)(?:ed)?\s+(?:our|your|that|the)\s+(?:(?:last|first|old)\s+)?(?:picnic|meeting|conversation|visit|encounter|promise|chat)\b',
        '\b(?:(?:visited|met|encountered|chatted with|talked to|spoke with|spoke to)\s+(?:me|you|us|him|her|them|'.$neighbor.')|we\s+(?:first\s+)?met)\b'
    ];
    if($names)$patterns[]='\b(?:'.implode('|',$names).')\s+(?:and i\s+)?(?:said|told|promised|visited|met|chatted|talked|spoke)\b';
    foreach(preg_split('/[.!?;]+/u',$text) as $clause)foreach($patterns as $pattern){
        if(preg_match('~(*UCP)'.$pattern.'~iu',$clause))throw new AiGuard('invalid_proposal');
    }
    // Source aliases belong in the structured story field, never public prose.
    if(preg_match('/\bS[0-9]+\b|\b(?:event|receipt|conversation)[ _-]?id\b/iu',$text))throw new AiGuard('invalid_proposal');
    // Reject labels, not interior punctuation or harmless past-tense words.
    if(preg_match('/\A[\p{L}\p{N}\x{2019}\x27-]{1,24}(?: Jr\.?)?:/u',$line))throw new AiGuard('invalid_proposal');
}
