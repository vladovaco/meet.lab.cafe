<?php
/**
 * Dodatočné zmeny schémy (ALTER), ktoré sa nedajú zapísať idempotentne v schema.sql.
 * Každá položka: [tabuľka, stĺpec, SQL ktoré sa spustí, ak stĺpec chýba]. Spúšťa bin/install.php a setup.php.
 */
return [
    ['users', 'notify_email', 'ALTER TABLE users ADD COLUMN notify_email TINYINT(1) NOT NULL DEFAULT 1 AFTER role'],
];
