-- Mealboard: departments are now free text managed in Settings (department_rules holds the list).
-- Widen the columns so names like "Finances & Comptabilité" fit. Run once per database.
ALTER TABLE `user` MODIFY `Department` VARCHAR(100) NULL;
ALTER TABLE `department_rules` MODIFY `department` VARCHAR(100) NOT NULL DEFAULT '';
