/*==============================================================*/
/* Requirements plugin schema.                                  */
/*                                                              */
/* Written for MySQL and MariaDB and translated for PostgreSQL  */
/* by Database::preparePgSqlQuery(), which drops "unsigned",     */
/* turns AUTO_INCREMENT into serial and cuts the MySQL table     */
/* options after the closing bracket. Two things that            */
/* translation cannot do, and that this file therefore avoids -  */
/* inline KEY / UNIQUE KEY definitions, because PostgreSQL has   */
/* no such clause inside CREATE TABLE, so every index is a       */
/* CREATE INDEX of its own the way db.sql declares the core      */
/* ones - and anything after the closing bracket other than      */
/* those table options, because that is what is cut away.        */
/*                                                              */
/* No semicolon may appear in a comment: the installer splits    */
/* the file on a semicolon at the end of a line, so one here     */
/* would cut a comment in half and feed the remainder to the     */
/* database as if it were a statement.                           */
/*==============================================================*/

CREATE TABLE %PREFIX%_req_providers
(
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
    PRIMARY KEY (rqp_id)
)
ENGINE = InnoDB
DEFAULT CHARSET = utf8mb4
COLLATE = utf8mb4_unicode_ci;

CREATE UNIQUE INDEX %PREFIX%_idx_rqp_uuid ON %PREFIX%_req_providers (rqp_uuid);
CREATE INDEX %PREFIX%_idx_rqp_org_name ON %PREFIX%_req_providers (rqp_org_id, rqp_name);
CREATE INDEX %PREFIX%_idx_rqp_org_short_name ON %PREFIX%_req_providers (rqp_org_id, rqp_short_name);

CREATE TABLE %PREFIX%_req_activities
(
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
    rqa_source_key          varchar(190)     NULL,
    rqa_usr_id_create       integer unsigned,
    rqa_timestamp_create    timestamp        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    rqa_usr_id_change       integer unsigned,
    rqa_timestamp_change    timestamp        NULL DEFAULT NULL,
    PRIMARY KEY (rqa_id)
)
ENGINE = InnoDB
DEFAULT CHARSET = utf8mb4
COLLATE = utf8mb4_unicode_ci;

CREATE UNIQUE INDEX %PREFIX%_idx_rqa_uuid ON %PREFIX%_req_activities (rqa_uuid);
CREATE INDEX %PREFIX%_idx_rqa_org_user_date ON %PREFIX%_req_activities (rqa_org_id, rqa_user_id, rqa_begin_date);
CREATE INDEX %PREFIX%_idx_rqa_provider ON %PREFIX%_req_activities (rqa_provider_id);
CREATE INDEX %PREFIX%_idx_rqa_status ON %PREFIX%_req_activities (rqa_org_id, rqa_status);
CREATE INDEX %PREFIX%_idx_rqa_review_status ON %PREFIX%_req_activities (rqa_org_id, rqa_review_status);
/* Not unique on purpose. The import's "create" duplicate policy allows a second row with the
   same source key, so the index speeds the lookup up rather than deciding the answer. */
CREATE INDEX %PREFIX%_idx_rqa_source_key ON %PREFIX%_req_activities (rqa_org_id, rqa_source_key);

CREATE TABLE %PREFIX%_req_requirement_types
(
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
    PRIMARY KEY (rqt_id)
)
ENGINE = InnoDB
DEFAULT CHARSET = utf8mb4
COLLATE = utf8mb4_unicode_ci;

CREATE UNIQUE INDEX %PREFIX%_idx_rqt_uuid ON %PREFIX%_req_requirement_types (rqt_uuid);
CREATE INDEX %PREFIX%_idx_rqt_org_sequence ON %PREFIX%_req_requirement_types (rqt_org_id, rqt_sequence, rqt_name);
CREATE INDEX %PREFIX%_idx_rqt_org_short_name ON %PREFIX%_req_requirement_types (rqt_org_id, rqt_short_name);

CREATE TABLE %PREFIX%_req_requirement_role_rules
(
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
    PRIMARY KEY (rrr_id)
)
ENGINE = InnoDB
DEFAULT CHARSET = utf8mb4
COLLATE = utf8mb4_unicode_ci;

CREATE UNIQUE INDEX %PREFIX%_idx_rrr_uuid ON %PREFIX%_req_requirement_role_rules (rrr_uuid);
CREATE INDEX %PREFIX%_idx_rrr_req_role_validity ON %PREFIX%_req_requirement_role_rules (rrr_requirement_type_id, rrr_role_id, rrr_valid_from, rrr_valid_until);
CREATE INDEX %PREFIX%_idx_rrr_role ON %PREFIX%_req_requirement_role_rules (rrr_role_id);

CREATE TABLE %PREFIX%_req_activity_credits
(
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
    PRIMARY KEY (rac_id)
)
ENGINE = InnoDB
DEFAULT CHARSET = utf8mb4
COLLATE = utf8mb4_unicode_ci;

CREATE UNIQUE INDEX %PREFIX%_idx_rac_uuid ON %PREFIX%_req_activity_credits (rac_uuid);
/* One contribution per activity, requirement and period - the rule the credits editor relies on. */
CREATE UNIQUE INDEX %PREFIX%_idx_rac_act_req_period ON %PREFIX%_req_activity_credits (rac_activity_id, rac_requirement_type_id, rac_period_reference);
CREATE INDEX %PREFIX%_idx_rac_requirement_period ON %PREFIX%_req_activity_credits (rac_requirement_type_id, rac_period_reference);

CREATE TABLE %PREFIX%_req_activity_files
(
    raf_id                  integer unsigned NOT NULL AUTO_INCREMENT,
    raf_uuid                varchar(36)      NOT NULL,
    raf_activity_id         integer unsigned NOT NULL,
    raf_name                varchar(255)     NOT NULL,
    raf_mime_type           varchar(128)     NOT NULL,
    raf_size                integer unsigned NOT NULL,
    raf_usr_id_create       integer unsigned,
    raf_timestamp_create    timestamp        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (raf_id)
)
ENGINE = InnoDB
DEFAULT CHARSET = utf8mb4
COLLATE = utf8mb4_unicode_ci;

CREATE UNIQUE INDEX %PREFIX%_idx_raf_uuid ON %PREFIX%_req_activity_files (raf_uuid);
CREATE INDEX %PREFIX%_idx_raf_activity ON %PREFIX%_req_activity_files (raf_activity_id);

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

ALTER TABLE %PREFIX%_req_activity_files
    ADD CONSTRAINT %PREFIX%_fk_raf_activity FOREIGN KEY (raf_activity_id) REFERENCES %PREFIX%_req_activities (rqa_id) ON DELETE CASCADE ON UPDATE RESTRICT,
    ADD CONSTRAINT %PREFIX%_fk_raf_usr_create FOREIGN KEY (raf_usr_id_create) REFERENCES %PREFIX%_users (usr_id) ON DELETE SET NULL ON UPDATE RESTRICT;
