CREATE TABLE %PREFIX%_req_providers (
    rqp_id                  integer unsigned NOT NULL AUTO_INCREMENT,
    rqp_uuid                varchar(36)      NOT NULL,
    rqp_org_id              integer unsigned NOT NULL,
    rqp_name                varchar(255)     NOT NULL,
    rqp_short_name          varchar(255)     NULL,
    rqp_address             text             NULL,
    rqp_url                 varchar(2000)    NULL,
    rqp_description         text             NULL,
    rqp_qualified           boolean          NOT NULL DEFAULT false,
    rqp_public              boolean          NOT NULL DEFAULT true,
    rqp_usr_id_create       integer unsigned,
    rqp_timestamp_create    timestamp        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    rqp_usr_id_change       integer unsigned,
    rqp_timestamp_change    timestamp        NULL DEFAULT NULL,
    PRIMARY KEY (rqp_id),
    UNIQUE KEY %PREFIX%_idx_rqp_uuid (rqp_uuid),
    KEY %PREFIX%_idx_rqp_org_name (rqp_org_id, rqp_name),
    KEY %PREFIX%_idx_rqp_org_short_name (rqp_org_id, rqp_short_name)
)
ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

CREATE TABLE %PREFIX%_req_activities (
    rqa_id                  integer unsigned NOT NULL AUTO_INCREMENT,
    rqa_uuid                varchar(36)      NOT NULL,
    rqa_org_id              integer unsigned NOT NULL,
    rqa_user_id             integer unsigned NOT NULL,
    rqa_provider_id         integer unsigned NULL,
    rqa_provider_name       varchar(255)     NULL,
    rqa_provider_short_name varchar(255)     NULL,
    rqa_title               varchar(255)     NOT NULL,
    rqa_description         text             NULL,
    rqa_url                 varchar(2000)    NULL,
    rqa_location            text             NULL,
    rqa_begin_date          date             NOT NULL,
    rqa_begin_time          time             NULL,
    rqa_end_date            date             NULL,
    rqa_end_time            time             NULL,
    rqa_certificate_date    date             NULL,
    rqa_attendance          decimal(10,2)    NULL DEFAULT 0.00,
    rqa_comments            text             NULL,
    rqa_status              varchar(32)      NOT NULL DEFAULT 'draft',
    rqa_review_comments     text             NULL,
    rqa_review_status       varchar(32)      NULL,
    rqa_usr_id_create       integer unsigned,
    rqa_timestamp_create    timestamp        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    rqa_usr_id_change       integer unsigned,
    rqa_timestamp_change    timestamp        NULL DEFAULT NULL,
    PRIMARY KEY (rqa_id),
    UNIQUE KEY %PREFIX%_idx_rqa_uuid (rqa_uuid),
    KEY %PREFIX%_idx_rqa_org_user_date (rqa_org_id, rqa_user_id, rqa_begin_date),
    KEY %PREFIX%_idx_rqa_provider (rqa_provider_id),
    KEY %PREFIX%_idx_rqa_status (rqa_org_id, rqa_status),
    KEY %PREFIX%_idx_rqa_review_status (rqa_org_id, rqa_review_status)
)
ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

CREATE TABLE %PREFIX%_req_requirement_types (
    rqt_id                  integer unsigned NOT NULL AUTO_INCREMENT,
    rqt_uuid                varchar(36)      NOT NULL,
    rqt_org_id              integer unsigned NOT NULL,
    rqt_name                varchar(255)     NOT NULL,
    rqt_short_name          varchar(255)     NULL,
    rqt_description         text             NULL,
    rqt_period_unit         varchar(16)      NOT NULL,
    rqt_period_count        integer unsigned NOT NULL DEFAULT 1,
    rqt_period_anchor       varchar(24)      NOT NULL DEFAULT 'calendar',
    rqt_multiple_roles_mode varchar(16)      NOT NULL DEFAULT 'maximum',
    rqt_active              boolean          NOT NULL DEFAULT true,
    rqt_sequence            integer unsigned NOT NULL DEFAULT 0,
    rqt_usr_id_create       integer unsigned,
    rqt_timestamp_create    timestamp        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    rqt_usr_id_change       integer unsigned,
    rqt_timestamp_change    timestamp        NULL DEFAULT NULL,
    PRIMARY KEY (rqt_id),
    UNIQUE KEY %PREFIX%_idx_rqt_uuid (rqt_uuid),
    KEY %PREFIX%_idx_rqt_org_sequence (rqt_org_id, rqt_sequence, rqt_name),
    KEY %PREFIX%_idx_rqt_org_short_name (rqt_org_id, rqt_short_name)
)
ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

CREATE TABLE %PREFIX%_req_requirement_role_rules (
    rrr_id                              integer unsigned NOT NULL AUTO_INCREMENT,
    rrr_uuid                            varchar(36)      NOT NULL,
    rrr_requirement_type_id             integer unsigned NOT NULL,
    rrr_role_id                         integer unsigned NOT NULL,
    rrr_required_credits                decimal(10,2)    NOT NULL,
    rrr_partial_period_mode             varchar(16)      NOT NULL DEFAULT 'pro_rata',
    rrr_membership_at_period_start      boolean          NOT NULL DEFAULT false,
    rrr_proration_basis                 varchar(16)      NOT NULL DEFAULT 'month',
    rrr_valid_from                      date             NULL,
    rrr_valid_until                     date             NULL,
    rrr_usr_id_create                   integer unsigned,
    rrr_timestamp_create                timestamp        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    rrr_usr_id_change                   integer unsigned,
    rrr_timestamp_change                timestamp        NULL DEFAULT NULL,
    PRIMARY KEY (rrr_id),
    UNIQUE KEY %PREFIX%_idx_rrr_uuid (rrr_uuid),
    KEY %PREFIX%_idx_rrr_requirement_role_validity (rrr_requirement_type_id, rrr_role_id, rrr_valid_from, rrr_valid_until),
    KEY %PREFIX%_idx_rrr_role (rrr_role_id)
)
ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

CREATE TABLE %PREFIX%_req_activity_credits (
    rac_id                  integer unsigned NOT NULL AUTO_INCREMENT,
    rac_uuid                varchar(36)      NOT NULL,
    rac_activity_id         integer unsigned NOT NULL,
    rac_requirement_type_id integer unsigned NOT NULL,
    rac_period_reference    date             NOT NULL,
    rac_credits_claimed     decimal(10,2)    NOT NULL,
    rac_credits_accepted    decimal(10,2)    NULL,
    rac_usr_id_create       integer unsigned,
    rac_timestamp_create    timestamp        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    rac_usr_id_change       integer unsigned,
    rac_timestamp_change    timestamp        NULL DEFAULT NULL,
    PRIMARY KEY (rac_id),
    UNIQUE KEY %PREFIX%_idx_rac_uuid (rac_uuid),
    UNIQUE KEY %PREFIX%_idx_rac_activity_requirement_period (rac_activity_id, rac_requirement_type_id, rac_period_reference),
    KEY %PREFIX%_idx_rac_requirement_period (rac_requirement_type_id, rac_period_reference)
)
ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

ALTER TABLE %PREFIX%_req_providers
    ADD CONSTRAINT %PREFIX%_fk_rqp_org FOREIGN KEY (rqp_org_id) REFERENCES %PREFIX%_organizations (org_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    ADD CONSTRAINT %PREFIX%_fk_rqp_usr_create FOREIGN KEY (rqp_usr_id_create) REFERENCES %PREFIX%_users (usr_id) ON DELETE SET NULL ON UPDATE RESTRICT,
    ADD CONSTRAINT %PREFIX%_fk_rqp_usr_change FOREIGN KEY (rqp_usr_id_change) REFERENCES %PREFIX%_users (usr_id) ON DELETE SET NULL ON UPDATE RESTRICT;

ALTER TABLE %PREFIX%_req_activities
    ADD CONSTRAINT %PREFIX%_fk_rqa_org FOREIGN KEY (rqa_org_id) REFERENCES %PREFIX%_organizations (org_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    ADD CONSTRAINT %PREFIX%_fk_rqa_user FOREIGN KEY (rqa_user_id) REFERENCES %PREFIX%_users (usr_id) ON DELETE CASCADE ON UPDATE RESTRICT,
    ADD CONSTRAINT %PREFIX%_fk_rqa_provider FOREIGN KEY (rqa_provider_id) REFERENCES %PREFIX%_req_providers (rqp_id) ON DELETE SET NULL ON UPDATE RESTRICT,
    ADD CONSTRAINT %PREFIX%_fk_rqa_usr_create FOREIGN KEY (rqa_usr_id_create) REFERENCES %PREFIX%_users (usr_id) ON DELETE SET NULL ON UPDATE RESTRICT,
    ADD CONSTRAINT %PREFIX%_fk_rqa_usr_change FOREIGN KEY (rqa_usr_id_change) REFERENCES %PREFIX%_users (usr_id) ON DELETE SET NULL ON UPDATE RESTRICT;

ALTER TABLE %PREFIX%_req_requirement_types
    ADD CONSTRAINT %PREFIX%_fk_rqt_org FOREIGN KEY (rqt_org_id) REFERENCES %PREFIX%_organizations (org_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    ADD CONSTRAINT %PREFIX%_fk_rqt_usr_create FOREIGN KEY (rqt_usr_id_create) REFERENCES %PREFIX%_users (usr_id) ON DELETE SET NULL ON UPDATE RESTRICT,
    ADD CONSTRAINT %PREFIX%_fk_rqt_usr_change FOREIGN KEY (rqt_usr_id_change) REFERENCES %PREFIX%_users (usr_id) ON DELETE SET NULL ON UPDATE RESTRICT;

ALTER TABLE %PREFIX%_req_requirement_role_rules
    ADD CONSTRAINT %PREFIX%_fk_rrr_requirement FOREIGN KEY (rrr_requirement_type_id) REFERENCES %PREFIX%_req_requirement_types (rqt_id) ON DELETE CASCADE ON UPDATE RESTRICT,
    ADD CONSTRAINT %PREFIX%_fk_rrr_role FOREIGN KEY (rrr_role_id) REFERENCES %PREFIX%_roles (rol_id) ON DELETE CASCADE ON UPDATE RESTRICT,
    ADD CONSTRAINT %PREFIX%_fk_rrr_usr_create FOREIGN KEY (rrr_usr_id_create) REFERENCES %PREFIX%_users (usr_id) ON DELETE SET NULL ON UPDATE RESTRICT,
    ADD CONSTRAINT %PREFIX%_fk_rrr_usr_change FOREIGN KEY (rrr_usr_id_change) REFERENCES %PREFIX%_users (usr_id) ON DELETE SET NULL ON UPDATE RESTRICT;

ALTER TABLE %PREFIX%_req_activity_credits
    ADD CONSTRAINT %PREFIX%_fk_rac_activity FOREIGN KEY (rac_activity_id) REFERENCES %PREFIX%_req_activities (rqa_id) ON DELETE CASCADE ON UPDATE RESTRICT,
    ADD CONSTRAINT %PREFIX%_fk_rac_requirement FOREIGN KEY (rac_requirement_type_id) REFERENCES %PREFIX%_req_requirement_types (rqt_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    ADD CONSTRAINT %PREFIX%_fk_rac_usr_create FOREIGN KEY (rac_usr_id_create) REFERENCES %PREFIX%_users (usr_id) ON DELETE SET NULL ON UPDATE RESTRICT,
    ADD CONSTRAINT %PREFIX%_fk_rac_usr_change FOREIGN KEY (rac_usr_id_change) REFERENCES %PREFIX%_users (usr_id) ON DELETE SET NULL ON UPDATE RESTRICT;
