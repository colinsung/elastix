ALTER TABLE ring_group add column `rg_pickup` enum ('yes','no') default 'no';
ALTER TABLE ring_group modify column `rg_strategy` varchar(50) NOT NULL;

ALTER TABLE sip add column `outofcall_message_context` varchar(100) DEFAULT NULL;
ALTER TABLE sip add column `fullcontact` varchar(100) DEFAULT NULL;
ALTER TABLE sip_settings add column `outofcall_message_context` varchar(100) DEFAULT 'im-sip';


ALTER TABLE extension MODIFY COLUMN `device` varchar(100) NOT NULL;
ALTER TABLE extension MODIFY COLUMN `context` varchar(100) DEFAULT NULL;
ALTER TABLE extension MODIFY COLUMN `exten` varchar(100) DEFAULT NULL;
ALTER TABLE extension MODIFY COLUMN `voicemail` varchar(100) DEFAULT 'novm';
ALTER TABLE extension ADD COLUMN `enable_chat` enum ('yes','no') default 'no';
ALTER TABLE extension ADD COLUMN `elxweb_device` varchar(100) DEFAULT NULL;
ALTER TABLE extension ADD COLUMN `clid_name` varchar(100) DEFAULT NULL;
ALTER TABLE extension ADD COLUMN `clid_number` varchar(100) DEFAULT NULL;

ALTER TABLE fax ADD COLUMN `area_code` varchar(100) DEFAULT NULL;
ALTER TABLE fax ADD COLUMN `country_code` varchar(100) DEFAULT NULL;
ALTER TABLE fax ADD COLUMN `dev_id` varchar(100) DEFAULT NULL;
ALTER TABLE fax ADD COLUMN `port` varchar(100) DEFAULT NULL;
ALTER TABLE fax ADD COLUMN `fax_content` TEXT DEFAULT NULL;
ALTER TABLE fax ADD COLUMN `fax_subject` TEXT DEFAULT NULL;
ALTER TABLE fax CHANGE COLUMN `callerid_name` `clid_name` varchar(100) DEFAULT NULL;
ALTER TABLE fax CHANGE COLUMN `callerid_number` `clid_number` varchar(100) DEFAULT NULL;

CREATE TABLE IF NOT EXISTS `im` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `display_name` varchar(100) NOT NULL,
      `alias` varchar(100) DEFAULT NULL,
      `device` varchar(100) NOT NULL,
      `id_exten` int(20) DEFAULT NULL, 
      `organization_domain` varchar(100) NOT NULL,
      PRIMARY KEY (`id`),
      FOREIGN KEY (device) REFERENCES sip(name),
      FOREIGN KEY (id_exten) REFERENCES extension(id) ON DELETE CASCADE,
      FOREIGN KEY (organization_domain) REFERENCES organization(domain) ON DELETE CASCADE,
      INDEX organization_domain (organization_domain)
)ENGINE = INNODB;

CREATE TABLE IF NOT EXISTS http_ast (
    id int(11) NOT NULL AUTO_INCREMENT,
    property_name varchar(250),
    property_val varchar(250),
    PRIMARY KEY (id),
    UNIQUE KEY property_name (property_name)
) ENGINE = INNODB;
insert into http_ast (property_name,property_val) values ('enabled','yes');
insert into http_ast (property_name,property_val) values ('bindport','8088');
insert into http_ast (property_name,property_val) values ('bindaddr','0.0.0.0');
insert into http_ast (property_name,property_val) values ('prefix','asterisk');
insert into http_ast (property_name,property_val) values ('tlsenable','no');
insert into http_ast (property_name,property_val) values ('tlsbindaddr','0.0.0.0');
insert into http_ast (property_name,property_val) values ('tlsbindport','8089');

CREATE TABLE IF NOT EXISTS elx_chat_config (
    id int(11) NOT NULL AUTO_INCREMENT,
    property_name varchar(250),
    property_val varchar(250),
    PRIMARY KEY (id),
    UNIQUE KEY property_name (property_name)
) ENGINE = INNODB;
insert into elx_chat_config (property_name,property_val) values ('type_connection','ws');
insert into elx_chat_config (property_name,property_val) values ('register','yes');
insert into elx_chat_config (property_name,property_val) values ('no_answer_timeout','60');
insert into elx_chat_config (property_name,property_val) values ('register_expires','600');
insert into elx_chat_config (property_name,property_val) values ('trace_sip','no');
insert into elx_chat_config (property_name,property_val) values ('use_preloaded_route','no');
insert into elx_chat_config (property_name,property_val) values ('connection_recovery_min_interval','2');
insert into elx_chat_config (property_name,property_val) values ('connection_recovery_max_interval','2');
insert into elx_chat_config (property_name,property_val) values ('hack_via_tcp','no');
insert into elx_chat_config (property_name,property_val) values ('hack_ip_in_contact','no');