<?php
declare(strict_types=1);
namespace LittleSparks;

/**
 * Expressive English dialogue is not evidence of village history. Historical
 * narration belongs only in PHP's verified story opening, outside this field.
 * This conservative lexical guard is defense in depth, not a semantic oracle.
 */
function expressiveDialogue(string $line):string
{
    $line=safeAiDialogue($line);
    // Keep vocabulary freely generated, but avoid homoglyph tricks in history
    // checks. English letters, ordinary punctuation and emoji remain supported.
    if(preg_match('/[^\p{Latin}\p{N}\p{P}\p{S}\p{Z}]/u',$line))throw new AiGuard('invalid_proposal');
    $text=strtolower(str_replace(['’','‘'],"'",$line));
    $history='planted|sowed|sown|seeded|built|constructed|erected|installed|crafted|fashioned|placed|decorated|adopted|visited|watered|grew|grown|harvested|made|created|added|removed|moved|owned|gave|gifted|bought|brought|sent|received|met|encountered|chatted|talked|spoke|saw|watched|witnessed|heard|told|said|promised|remember|remembered|recall|recalled|happened';
    // A question mark, quotation, negation or "I wonder" must not bypass a
    // historical claim. The offered opening is the only history channel.
    if(preg_match('/\b(?:'.$history.'|yesterday|ago|previously|earlier|already|last\s+(?:night|week|month|time)|used to)\b/i',$text)
        ||preg_match('/\b(?:have|has|had|own|owns|is|are|was|were)\b.{0,50}\b(?:planted|built|visited|tree|trees|bush|bushes|flower|flowers|pet|cat|cats|cottage|house|path|lantern)\b/i',$text)
        ||preg_match('/\b(?:my|your|our|their|his|her|[a-z]+\x27s)\s+(?:little\s+|new\s+|old\s+|three\s+|two\s+|one\s+)*(?:tree|trees|bush|garden|flowers|pet|cat|path|lantern)\b/i',$text)
        ||preg_match('/\b(?:plant|build|place|decorate|adopt|visit|water|grow|own|owns)\b.{0,25}\b(?:today|this morning|this evening|now)\b/i',$text)
        ||preg_match('/\b(?:did|was|were|had)\b/i',$text))throw new AiGuard('invalid_proposal');
    // Reject arbitrary labels, not just A/B; interior punctuation stays intact.
    if(preg_match('/\A[\p{L}\p{N}\x{2019}\x27-]{1,24}(?: Jr\.?)?:/u',$line))throw new AiGuard('invalid_proposal');
    return $line;
}
