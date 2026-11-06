-- Mealboard (Laravel): indexes for speed. Safe to run more than once? No: run it once per database.
-- Apply to the live database when moving to the Laravel version (MySQL 8.0.17 or newer).

-- Notifications are looked up by recipient ("? MEMBER OF (recipients)"). A multi-valued index on the JSON list
-- turns a scan of every notification into an index lookup.
ALTER TABLE `notifications` ADD INDEX `ix_notifications_recipients` ((CAST(`recipients` AS CHAR(15) ARRAY)));
