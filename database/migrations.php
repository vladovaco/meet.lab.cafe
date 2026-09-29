<?php
/**
 * Dodatočné zmeny schémy (ALTER), ktoré sa nedajú zapísať idempotentne v schema.sql.
 * Každá položka: [tabuľka, stĺpec, SQL ktoré sa spustí, ak stĺpec chýba]. Spúšťa bin/install.php a setup.php.
 */
return [
    ['meetings', 'recording_type', "ALTER TABLE meetings ADD COLUMN recording_type VARCHAR(20) NOT NULL DEFAULT 'meeting' COMMENT 'call | meeting | presentation | consultation' AFTER source"],
    ['users', 'notify_email', 'ALTER TABLE users ADD COLUMN notify_email TINYINT(1) NOT NULL DEFAULT 1 AFTER role'],
];
