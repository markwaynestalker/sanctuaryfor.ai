<?php
declare(strict_types=1);
namespace LittleSparks;

/** Static diagnostic vocabulary only: never include provider text or exception messages. */
const EDITORIAL_REJECTION_CODES=[
    'response_envelope_invalid','response_output_invalid','response_content_invalid',
    'response_answer_ambiguous','response_json_invalid','draft_snapshot_invalid',
    'draft_fields_invalid','draft_text_invalid','draft_binding_invalid',
    'draft_reference_invalid','draft_source_unknown','draft_notice_sequence',
    'draft_message_unknown','draft_story_shape','draft_claim_invalid',
    'draft_claim_evidence','draft_reference_duplicate','draft_story_evidence',
    'draft_quote_reused','source_snapshot_invalid','source_kind_invalid',
    'source_unavailable','source_changed','notice_unavailable','notice_changed',
    'storage_refused','guard_refused',
];
final class EditorialRejection extends \RuntimeException
{
    public function __construct(public readonly string $reason){
        if(!in_array($reason,EDITORIAL_REJECTION_CODES,true))throw new \LogicException('Invalid editorial diagnostic.');
        parent::__construct('Editorial validation refused.');
    }
}
function editorialRejectionCode(\Throwable $e):string
{
    return $e instanceof EditorialRejection?$e->reason:($e instanceof \PDOException?'storage_refused':'guard_refused');
}
