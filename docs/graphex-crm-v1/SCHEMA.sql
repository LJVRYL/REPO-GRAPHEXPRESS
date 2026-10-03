-- Graphex CRM v1 · schema only, no customer data
-- Replace wp_ with the destination WordPress prefix. Deploy through GE_CRM::install.
CREATE TABLE `wp_ge_crm_records` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` varchar(80) NOT NULL,
  `kind` varchar(20) NOT NULL,
  `title` varchar(200) NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'new',
  `owner_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `customer_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `lead_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `opportunity_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `quote_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `order_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `stage` varchar(60) NOT NULL DEFAULT '',
  `due_date` varchar(10) NOT NULL DEFAULT '',
  `email` varchar(190) NOT NULL DEFAULT '',
  `phone` varchar(40) NOT NULL DEFAULT '',
  `cuit` varchar(20) NOT NULL DEFAULT '',
  `dedupe_key` varchar(190) DEFAULT NULL,
  `payload` longtext NOT NULL,
  `revision` bigint(20) unsigned NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `source_event` (`organization_id`,`dedupe_key`),
  KEY `domain_status` (`organization_id`,`kind`,`status`),
  KEY `customer` (`organization_id`,`customer_id`),
  KEY `quote_link` (`organization_id`,`quote_id`),
  KEY `due` (`organization_id`,`kind`,`due_date`),
  KEY `contact` (`organization_id`,`email`)
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE `wp_ge_crm_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` varchar(80) NOT NULL,
  `record_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `customer_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `event_type` varchar(60) NOT NULL,
  `actor_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `payload` longtext NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `timeline` (`organization_id`,`record_id`,`id`),
  KEY `customer` (`organization_id`,`customer_id`,`id`)
) ENGINE=InnoDB AUTO_INCREMENT=48 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
