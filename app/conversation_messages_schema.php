<?php
declare(strict_types=1);
namespace LittleSparks;
use PDO;
require_once __DIR__.'/conversation_messages.php';
function migrateConversationMessages(PDO $db):void
{
    if((int)$db->query('PRAGMA user_version')->fetchColumn()!==6)throw new \RuntimeException('V6 required.');
    if(multiMessageInstalled($db))return;
    assertIdentityIdle($db);
    $db->exec("CREATE TABLE conversation_message_meta(singleton INTEGER PRIMARY KEY CHECK(singleton=1),revision INTEGER NOT NULL CHECK(revision=1));
        INSERT INTO conversation_message_meta VALUES(1,1);
        CREATE TABLE conversation_attempt_contracts(attempt_id TEXT PRIMARY KEY REFERENCES director_attempts(id) ON DELETE RESTRICT,output_contract TEXT NOT NULL CHECK(output_contract IN ('sanctuary.director.v2','sanctuary.director.bulletin.v2')));
        CREATE TABLE extended_conversations(conversation_id TEXT PRIMARY KEY REFERENCES conversations(id) ON DELETE RESTRICT,message_count INTEGER NOT NULL CHECK(message_count BETWEEN 2 AND 64));
        CREATE TABLE extended_messages(conversation_id TEXT NOT NULL REFERENCES extended_conversations(conversation_id) ON DELETE RESTRICT,
            sequence INTEGER NOT NULL CHECK(sequence BETWEEN 0 AND 63),resident_id TEXT NOT NULL,body TEXT NOT NULL CHECK(length(body) BETWEEN 1 AND 160),
            PRIMARY KEY(conversation_id,sequence),FOREIGN KEY(conversation_id,resident_id) REFERENCES conversation_participants(conversation_id,resident_id) ON DELETE RESTRICT);
        CREATE TABLE extended_director_notes(id TEXT PRIMARY KEY,resident_id TEXT NOT NULL REFERENCES residents(id) ON DELETE RESTRICT,other_resident_id TEXT NOT NULL REFERENCES residents(id) ON DELETE RESTRICT,
            conversation_id TEXT NOT NULL,sequence INTEGER NOT NULL CHECK(sequence BETWEEN 0 AND 63),quote TEXT NOT NULL CHECK(length(quote) BETWEEN 1 AND 160),
            classification TEXT NOT NULL CHECK(classification IN ('preference','interpretation','rumor')),created_at TEXT NOT NULL,
            FOREIGN KEY(conversation_id,sequence) REFERENCES extended_messages(conversation_id,sequence) ON DELETE RESTRICT,UNIQUE(resident_id,conversation_id,sequence,quote));
        CREATE INDEX extended_note_recall ON extended_director_notes(resident_id,other_resident_id,created_at);
        CREATE VIEW all_conversation_messages AS SELECT conversation_id,sequence,resident_id,body FROM messages UNION ALL SELECT m.conversation_id,m.sequence,m.resident_id,m.body FROM extended_messages m WHERE EXISTS(SELECT 1 FROM extended_conversations c WHERE c.conversation_id=m.conversation_id AND c.message_count=(SELECT count(*) FROM extended_messages x WHERE x.conversation_id=c.conversation_id) AND (SELECT min(sequence) FROM extended_messages x WHERE x.conversation_id=c.conversation_id)=0 AND (SELECT max(sequence) FROM extended_messages x WHERE x.conversation_id=c.conversation_id)=c.message_count-1);
        CREATE VIEW all_director_notes AS SELECT * FROM director_notes UNION ALL SELECT * FROM extended_director_notes;
        CREATE TRIGGER extended_no_legacy BEFORE INSERT ON extended_conversations WHEN EXISTS(SELECT 1 FROM messages WHERE conversation_id=NEW.conversation_id) BEGIN SELECT RAISE(ABORT,'Conversation already uses legacy storage'); END;
        CREATE TRIGGER legacy_no_extended BEFORE INSERT ON messages WHEN EXISTS(SELECT 1 FROM extended_conversations WHERE conversation_id=NEW.conversation_id) BEGIN SELECT RAISE(ABORT,'Conversation already uses extended storage'); END;
        CREATE TRIGGER extended_sequence BEFORE INSERT ON extended_messages WHEN NEW.sequence>=(SELECT message_count FROM extended_conversations WHERE conversation_id=NEW.conversation_id) BEGIN SELECT RAISE(ABORT,'Invalid message sequence'); END;
        CREATE TRIGGER extended_contiguous BEFORE INSERT ON extended_messages WHEN NEW.sequence<>(SELECT count(*) FROM extended_messages WHERE conversation_id=NEW.conversation_id) BEGIN SELECT RAISE(ABORT,'Message sequence must be contiguous'); END;
        CREATE TRIGGER extended_two_speakers BEFORE INSERT ON extended_messages WHEN NEW.sequence=1 AND NEW.resident_id=(SELECT resident_id FROM extended_messages WHERE conversation_id=NEW.conversation_id AND sequence=0) BEGIN SELECT RAISE(ABORT,'Two different speakers required'); END;
        CREATE TRIGGER extended_alternation BEFORE INSERT ON extended_messages WHEN NEW.sequence>=2 AND NEW.resident_id<>(SELECT resident_id FROM extended_messages WHERE conversation_id=NEW.conversation_id AND sequence=NEW.sequence%2) BEGIN SELECT RAISE(ABORT,'Invalid speaker order'); END;");
    foreach(['extended_conversations','extended_messages','extended_director_notes','conversation_attempt_contracts'] as $table)foreach(['UPDATE','DELETE'] as $operation)$db->exec("CREATE TRIGGER {$table}_no_{$operation} BEFORE $operation ON $table BEGIN SELECT RAISE(ABORT,'Conversation history is immutable'); END;");
}
