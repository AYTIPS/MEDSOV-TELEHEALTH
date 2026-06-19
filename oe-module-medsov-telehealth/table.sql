#IfNotTable medsov_telehealth_sessions
CREATE TABLE `medsov_telehealth_sessions` (
    `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `pc_eid` INT(11) UNSIGNED DEFAULT NULL,
    `pid` BIGINT(20) DEFAULT NULL,
    `encounter` BIGINT(20) DEFAULT NULL,
    `provider_id` BIGINT(20) DEFAULT NULL,
    `meeting_room` VARCHAR(190) NOT NULL,
    `status` VARCHAR(32) NOT NULL DEFAULT 'created',
    `patient_waiting_at` DATETIME DEFAULT NULL,
    `provider_joined_at` DATETIME DEFAULT NULL,
    `admitted_at` DATETIME DEFAULT NULL,
    `ended_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uuid` (`uuid`),
    UNIQUE KEY `meeting_room` (`meeting_room`),
    KEY `pc_eid` (`pc_eid`),
    KEY `pid` (`pid`),
    KEY `provider_id` (`provider_id`),
    KEY `status` (`status`)
) ENGINE=InnoDB;
#EndIf

#IfNotTable medsov_telehealth_participants
CREATE TABLE `medsov_telehealth_participants` (
    `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `session_id` BIGINT(20) NOT NULL,
    `participant_type` VARCHAR(32) NOT NULL,
    `participant_id` BIGINT(20) NOT NULL,
    `display_name` VARCHAR(190) DEFAULT NULL,
    `joined_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `left_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `session_participant` (`session_id`, `participant_type`, `participant_id`),
    KEY `session_id` (`session_id`),
    KEY `participant_type` (`participant_type`),
    KEY `left_at` (`left_at`),
    KEY `last_seen_at` (`last_seen_at`)
) ENGINE=InnoDB;
#EndIf

#IfNotTable medsov_telehealth_audit
CREATE TABLE `medsov_telehealth_audit` (
    `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `session_id` BIGINT(20) DEFAULT NULL,
    `event_type` VARCHAR(64) NOT NULL,
    `actor_type` VARCHAR(32) NOT NULL,
    `actor_id` BIGINT(20) DEFAULT NULL,
    `ip_address` VARCHAR(64) DEFAULT NULL,
    `user_agent` VARCHAR(255) DEFAULT NULL,
    `metadata_json` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `session_id` (`session_id`),
    KEY `event_type` (`event_type`),
    KEY `actor_type` (`actor_type`)
) ENGINE=InnoDB;
#EndIf

#IfNotRow openemr_postcalendar_categories pc_constant_id medsov_telehealth
INSERT INTO `openemr_postcalendar_categories` (
    `pc_constant_id`, `pc_catname`, `pc_catcolor`, `pc_catdesc`,
    `pc_recurrtype`, `pc_enddate`, `pc_recurrspec`, `pc_recurrfreq`, `pc_duration`,
    `pc_end_date_flag`, `pc_end_date_type`, `pc_end_date_freq`, `pc_end_all_day`,
    `pc_dailylimit`, `pc_cattype`, `pc_active`, `pc_seq`, `aco_spec`
)
VALUES (
    'medsov_telehealth', 'Medsov Telehealth', '#63c5da',
    'Medsov Telehealth appointment', '0', NULL,
    'a:5:{s:17:"event_repeat_freq";s:1:"0";s:22:"event_repeat_freq_type";s:1:"0";s:19:"event_repeat_on_num";s:1:"1";s:19:"event_repeat_on_day";s:1:"0";s:20:"event_repeat_on_freq";s:1:"0";}',
    '0', '1800', '0', NULL, '0', '0', '0', 0, '1', '10', 'encounters|notes'
);
#EndIf
