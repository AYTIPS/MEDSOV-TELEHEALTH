DELETE FROM `globals` WHERE `gl_name` LIKE 'medsov_telehealth_%';
DROP TABLE IF EXISTS `medsov_telehealth_audit`;
DROP TABLE IF EXISTS `medsov_telehealth_participants`;
DROP TABLE IF EXISTS `medsov_telehealth_sessions`;
DELETE FROM `openemr_postcalendar_categories` WHERE `pc_constant_id` = 'medsov_telehealth';
