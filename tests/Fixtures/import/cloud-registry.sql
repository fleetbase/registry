-- MySQL dump 10.13  Distrib 8.0.36, for Linux (x86_64)
--
-- Host: fleetbase-cloud    Database: fleetbase
-- ------------------------------------------------------
-- Server version	8.0.35

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8mb4 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;

--
-- Table structure for table `companies`
--

DROP TABLE IF EXISTS `companies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `companies` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `public_id` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stripe_connect_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `owner_uuid` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `website_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `companies_public_id_unique` (`public_id`),
  KEY `companies_uuid_index` (`uuid`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `companies` WRITE;
/*!40000 ALTER TABLE `companies` DISABLE KEYS */;
INSERT INTO `companies` VALUES (1,'c0000000-0000-4000-8000-000000000001','company_acme1','acct_1Acme','a0000000-0000-4000-8000-000000000001','Acme Logistics','https://acme.test','Acme\'s logistics arm','acme-logistics','2024-01-01 00:00:00','2024-01-01 00:00:00',NULL),(2,'c0000000-0000-4000-8000-000000000002','company_beta2',NULL,NULL,'Beta Freight',NULL,NULL,NULL,'2024-01-02 00:00:00','2024-01-02 00:00:00',NULL),(3,'c0000000-0000-4000-8000-000000000003','company_gamma',NULL,NULL,'Gamma Couriers',NULL,NULL,'gamma-couriers','2024-01-03 00:00:00','2024-01-03 00:00:00',NULL);
/*!40000 ALTER TABLE `companies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `orders` (not read by the importer)
--

DROP TABLE IF EXISTS `orders`;
CREATE TABLE `orders` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(191) DEFAULT NULL,
  `notes` text,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

LOCK TABLES `orders` WRITE;
INSERT INTO `orders` VALUES (1,'o1','INSERT INTO `registry_extensions` VALUES (\'not a real row\');'),(2,'o2',NULL);
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `public_id` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `company_uuid` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `username` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `timezone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_verified_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

LOCK TABLES `users` WRITE;
INSERT INTO `users` VALUES (1,'a0000000-0000-4000-8000-000000000001','user_alice','c0000000-0000-4000-8000-000000000001','alice','Alice@Acme.test','$2y$10$ryH8ewFSnZo6gek/TqYuLuVvtmBXBYt9zz33gnbM0VGR1LgOulzvm','Alice Admin','Asia/Singapore','active','2024-01-01 00:00:00','2024-01-01 00:00:00','2024-01-01 00:00:00',NULL),(2,'a0000000-0000-4000-8000-000000000002','user_bob','c0000000-0000-4000-8000-000000000001',NULL,NULL,NULL,'Bob NoEmail',NULL,'active',NULL,'2024-01-01 00:00:00','2024-01-01 00:00:00',NULL),(3,'a0000000-0000-4000-8000-000000000003','user_carol','c0000000-0000-4000-8000-000000000002','carol','carol@beta.test','$2y$10$ryH8ewFSnZo6gek/TqYuLuVvtmBXBYt9zz33gnbM0VGR1LgOulzvm','Carol Buyer','UTC','active','2024-01-02 00:00:00','2024-01-02 00:00:00','2024-01-02 00:00:00',NULL);
UNLOCK TABLES;

--
-- Table structure for table `files`
--

DROP TABLE IF EXISTS `files`;
CREATE TABLE `files` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `public_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `company_uuid` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject_uuid` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `disk` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `path` longtext COLLATE utf8mb4_unicode_ci,
  `subject_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bucket` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `original_filename` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `content_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

LOCK TABLES `files` WRITE;
INSERT INTO `files` VALUES (1,'f0000000-0000-4000-8000-000000000001','file_icon1','c0000000-0000-4000-8000-000000000001','e0000000-0000-4000-8000-000000000001','s3','uploads/c1/icons/fleet-insights.png','Fleetbase\\RegistryBridge\\Models\\RegistryExtension','flb-prod','fleet-insights.png','extension_icon','image/png',4,'2024-04-01 00:00:00','2024-04-01 00:00:00',NULL),(2,'f0000000-0000-4000-8000-000000000011','file_b1','c0000000-0000-4000-8000-000000000001','e0000000-0000-4000-8000-000000000001','s3','uploads/c1/extensions/fleet-insights-1.0.0.tar.gz','Fleetbase\\RegistryBridge\\Models\\RegistryExtension','flb-prod','fleet-insights-1.0.0.tar.gz','extension_bundle','application/gzip',21,'2024-04-01 00:00:00','2024-04-01 00:00:00',NULL),(3,'f0000000-0000-4000-8000-000000000012','file_b2','c0000000-0000-4000-8000-000000000001','e0000000-0000-4000-8000-000000000001','s3','uploads/c1/extensions/fleet-insights-1.1.0.tar.gz','Fleetbase\\RegistryBridge\\Models\\RegistryExtension','flb-prod','fleet-insights-1.1.0.tar.gz','extension_bundle','application/gzip',21,'2024-05-01 00:00:00','2024-05-01 00:00:00',NULL),(4,'f0000000-0000-4000-8000-000000000013','file_b3',NULL,'e0000000-0000-4000-8000-000000000002','s3','uploads/developers/route-planner-0.3.0.tar.gz','Fleetbase\\RegistryBridge\\Models\\RegistryExtension','flb-prod','route-planner-0.3.0.tar.gz','extension_bundle','application/gzip',9001,'2024-06-01 00:00:00','2024-06-01 00:00:00',NULL),(5,'f0000000-0000-4000-8000-000000000021','file_shot1','c0000000-0000-4000-8000-000000000001','e0000000-0000-4000-8000-000000000001','s3','uploads/c1/screens/dashboard.png','Fleetbase\\RegistryBridge\\Models\\RegistryExtension','flb-prod','dashboard.png','extension_screenshot','image/png',5,'2024-04-01 00:00:00','2024-04-01 00:00:00',NULL),(6,'f0000000-0000-4000-8000-000000000099','file_other','c0000000-0000-4000-8000-000000000002',NULL,'s3','uploads/c2/avatar.png',NULL,'flb-prod','avatar.png','user_avatar','image/png',3,'2024-04-01 00:00:00','2024-04-01 00:00:00',NULL);
UNLOCK TABLES;

--
-- Table structure for table `categories`
--

DROP TABLE IF EXISTS `categories`;
CREATE TABLE `categories` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `public_id` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `internal_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tags` json DEFAULT NULL,
  `icon` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `for` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

LOCK TABLES `categories` WRITE;
INSERT INTO `categories` VALUES (1,'b0000000-0000-4000-8000-000000000001','category_tele','Telematics','FLBTELEMATICS','Cloud copy of telematics','[\"tracking\"]','satellite-dish','extension_category','telematics','2024-01-01 00:00:00','2024-01-01 00:00:00'),(2,'b0000000-0000-4000-8000-000000000002','category_cust','Custom Tools',NULL,'Tools made by the community','[\"tools\", \"community\"]','toolbox','extension_category','custom-tools','2024-01-01 00:00:00','2024-01-01 00:00:00');
UNLOCK TABLES;

--
-- Table structure for table `registry_developer_accounts`
--

DROP TABLE IF EXISTS `registry_developer_accounts`;
CREATE TABLE `registry_developer_accounts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `username` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `avatar_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `github_username` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `website` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bio` text COLLATE utf8mb4_unicode_ci,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `verification_token` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('active','suspended','pending_verification') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending_verification',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `registry_developer_accounts_uuid_unique` (`uuid`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

LOCK TABLES `registry_developer_accounts` WRITE;
INSERT INTO `registry_developer_accounts` VALUES (1,'d0000000-0000-4000-8000-000000000001','devon','devon@indie.test','$2y$10$ryH8ewFSnZo6gek/TqYuLuVvtmBXBYt9zz33gnbM0VGR1LgOulzvm','Devon Indie',NULL,'devon-gh','https://devon.test','Builds routing tools.\nMostly at night.','2026-02-16 00:00:00',NULL,'active','2026-02-16 00:00:00','2026-02-16 00:00:00',NULL),(2,'d0000000-0000-4000-8000-000000000002','penny','penny@indie.test','$2y$10$ryH8ewFSnZo6gek/TqYuLuVvtmBXBYt9zz33gnbM0VGR1LgOulzvm',NULL,NULL,NULL,NULL,NULL,NULL,'tok123','pending_verification','2026-02-17 00:00:00','2026-02-17 00:00:00',NULL),(3,'d0000000-0000-4000-8000-000000000003','alice-dev','alice@acme.test','$2y$10$ryH8ewFSnZo6gek/TqYuLuVvtmBXBYt9zz33gnbM0VGR1LgOulzvm','Alice (dev)',NULL,NULL,NULL,NULL,'2026-02-18 00:00:00',NULL,'active','2026-02-18 00:00:00','2026-02-18 00:00:00',NULL);
UNLOCK TABLES;

--
-- Table structure for table `registry_users`
--

DROP TABLE IF EXISTS `registry_users`;
CREATE TABLE `registry_users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_type` enum('cloud','developer') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cloud',
  `developer_account_uuid` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `company_uuid` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_uuid` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `public_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `registry_token` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `scope` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `meta` json DEFAULT NULL,
  `revoked` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

LOCK TABLES `registry_users` WRITE;
INSERT INTO `registry_users` VALUES (1,'90000000-0000-4000-8000-000000000001','cloud',NULL,'c0000000-0000-4000-8000-000000000001','a0000000-0000-4000-8000-000000000001','ru_1','plain-token-1','npm-token-1',NULL,NULL,NULL,NULL,NULL,0,'2024-03-19 00:00:00','2024-03-19 00:00:00',NULL),(2,'90000000-0000-4000-8000-000000000002','cloud',NULL,'c0000000-0000-4000-8000-000000000001','a0000000-0000-4000-8000-000000000001','ru_2','plain-token-2',NULL,NULL,NULL,NULL,NULL,NULL,1,'2024-03-20 00:00:00','2024-03-20 00:00:00',NULL),(3,'90000000-0000-4000-8000-000000000003','cloud',NULL,'c0000000-0000-4000-8000-000000000001','a0000000-0000-4000-8000-000000000002','ru_3','plain-token-3',NULL,NULL,NULL,NULL,NULL,NULL,0,'2024-03-21 00:00:00','2024-03-21 00:00:00',NULL),(4,'90000000-0000-4000-8000-000000000004','developer','d0000000-0000-4000-8000-000000000001',NULL,NULL,'ru_4','plain-token-4',NULL,NULL,NULL,NULL,NULL,NULL,0,'2026-02-16 00:00:00','2026-02-16 00:00:00',NULL),(5,'90000000-0000-4000-8000-000000000005','cloud',NULL,'c0000000-0000-4000-8000-000000000002','a0000000-0000-4000-8000-000000000003','ru_5','plain-token-5',NULL,NULL,NULL,NULL,NULL,NULL,0,'2024-07-01 00:00:00','2024-07-01 00:00:00',NULL);
UNLOCK TABLES;

--
-- Table structure for table `registry_extensions`
--

DROP TABLE IF EXISTS `registry_extensions`;
CREATE TABLE `registry_extensions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_uuid` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `publisher_type` enum('cloud','developer') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cloud',
  `publisher_uuid` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by_uuid` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `registry_user_uuid` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `current_bundle_uuid` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `next_bundle_uuid` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `icon_uuid` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `category_uuid` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `public_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stripe_product_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subtitle` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `self_managed` tinyint(1) NOT NULL DEFAULT '0',
  `payment_required` tinyint(1) NOT NULL DEFAULT '0',
  `price` int DEFAULT NULL,
  `sale_price` int DEFAULT NULL,
  `on_sale` tinyint(1) NOT NULL DEFAULT '0',
  `subscription_required` tinyint(1) NOT NULL DEFAULT '0',
  `currency` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'USD',
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fa_icon` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` mediumtext COLLATE utf8mb4_unicode_ci,
  `promotional_text` mediumtext COLLATE utf8mb4_unicode_ci,
  `website_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `repo_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `support_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `privacy_policy_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tos_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `copyright` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `primary_language` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tags` json DEFAULT NULL,
  `languages` json DEFAULT NULL,
  `meta` json DEFAULT NULL,
  `core_extension` tinyint(1) NOT NULL DEFAULT '0',
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `package_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `accepted_at` timestamp NULL DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

LOCK TABLES `registry_extensions` WRITE;
INSERT INTO `registry_extensions` VALUES (1,'e0000000-0000-4000-8000-000000000001','c0000000-0000-4000-8000-000000000001','cloud','c0000000-0000-4000-8000-000000000001','a0000000-0000-4000-8000-000000000001','90000000-0000-4000-8000-000000000001','30000000-0000-4000-8000-000000000002',NULL,'f0000000-0000-4000-8000-000000000001','b0000000-0000-4000-8000-000000000001','extension_fleet','prod_Fleet','Fleet Insights','Analytics for your fleet',0,1,4900,3900,0,0,'usd','fleet-insights','1.1.0','chart-line','Dashboards and \"insights\" for fleet operators.\r\nSecond line.','Now with 50% more charts','https://acme.test/fleet-insights',NULL,'https://acme.test/support','https://acme.test/privacy',NULL,'© 2024 Acme','en','[\"analytics\", \"fleet\"]','[\"en\", \"zh\"]',NULL,0,'published',NULL,'2024-05-01 10:00:00','2024-05-01 09:00:00',NULL,'2024-03-21 00:00:00','2024-05-01 10:00:00',NULL),(2,'e0000000-0000-4000-8000-000000000002',NULL,'developer','d0000000-0000-4000-8000-000000000001',NULL,'90000000-0000-4000-8000-000000000004','30000000-0000-4000-8000-000000000003',NULL,NULL,'b0000000-0000-4000-8000-000000000002','extension_route','prod_Route','Route Planner','Plan routes offline',1,0,NULL,NULL,0,0,'USD','route-planner','0.3.0','route',NULL,NULL,NULL,'https://github.com/devon/route-planner',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'published',NULL,'2026-03-01 00:00:00',NULL,NULL,'2026-02-20 00:00:00','2026-03-01 00:00:00',NULL),(3,'e0000000-0000-4000-8000-000000000003','c0000000-0000-4000-8000-000000000002','cloud','c0000000-0000-4000-8000-000000000002',NULL,NULL,NULL,NULL,NULL,NULL,'extension_draft',NULL,'Draft Thing',NULL,0,0,NULL,NULL,0,0,'USD','draft-thing',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'pending',NULL,NULL,NULL,NULL,'2024-08-01 00:00:00','2024-08-01 00:00:00',NULL),(4,'e0000000-0000-4000-8000-000000000004','c0000000-0000-4000-8000-000000000003','cloud','c0000000-0000-4000-8000-000000000003',NULL,NULL,'30000000-0000-4000-8000-000000000004',NULL,NULL,NULL,'extension_copy',NULL,'Copycat',NULL,0,0,NULL,NULL,0,0,'USD','copycat',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'awaiting_review',NULL,NULL,NULL,NULL,'2024-09-01 00:00:00','2024-09-01 00:00:00',NULL),(5,'e0000000-0000-4000-8000-000000000005','c0000000-0000-4000-8000-000000000001','cloud','c0000000-0000-4000-8000-000000000001',NULL,NULL,NULL,NULL,NULL,NULL,'extension_old',NULL,'Old Widget',NULL,0,0,NULL,NULL,0,0,'USD','old-widget',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,'unpublished','@acme/old-widget-engine',NULL,NULL,NULL,'2024-02-01 00:00:00','2024-02-01 00:00:00','2024-10-01 00:00:00');
UNLOCK TABLES;

--
-- Table structure for table `registry_extension_bundles`
--

DROP TABLE IF EXISTS `registry_extension_bundles`;
CREATE TABLE `registry_extension_bundles` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `public_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `bundle_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_uuid` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by_uuid` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `extension_uuid` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bundle_uuid` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bundle_number` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `version` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `meta` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

LOCK TABLES `registry_extension_bundles` WRITE;
INSERT INTO `registry_extension_bundles` VALUES (1,'30000000-0000-4000-8000-000000000001','bundle_pub_1','EXTBNDLAAAAAAA','c0000000-0000-4000-8000-000000000001','a0000000-0000-4000-8000-000000000001','e0000000-0000-4000-8000-000000000001','f0000000-0000-4000-8000-000000000011','1','1.0.0','published','{\"extension.json\": {\"name\": \"Fleet Insights\", \"version\": \"1.0.0\"}, \"package.json\": {\"name\": \"@acme/fleet-insights-engine\", \"version\": \"1.0.0\"}, \"composer.json\": {\"name\": \"acme/fleet-insights-api\"}}','2024-04-01 00:00:00','2024-04-02 00:00:00',NULL),(2,'30000000-0000-4000-8000-000000000002','bundle_pub_2','EXTBNDLBBBBBBB','c0000000-0000-4000-8000-000000000001','a0000000-0000-4000-8000-000000000001','e0000000-0000-4000-8000-000000000001','f0000000-0000-4000-8000-000000000012','2','1.1.0','published','{\"extension.json\": {\"name\": \"Fleet Insights\", \"version\": \"1.1.0\", \"compat\": {\"console\": \">=0.7.0\", \"api\": \"^1.6\"}}, \"package.json\": {\"name\": \"@acme/fleet-insights-engine\", \"version\": \"1.1.0\"}, \"composer.json\": {\"name\": \"acme/fleet-insights-api\"}}','2024-05-01 00:00:00','2024-05-01 10:00:00',NULL),(3,'30000000-0000-4000-8000-000000000005','bundle_pub_5','EXTBNDLCCCCCCC','c0000000-0000-4000-8000-000000000001',NULL,'e0000000-0000-4000-8000-000000000001',NULL,'0','v1.0.0','rejected',NULL,'2024-03-01 00:00:00','2024-03-01 00:00:00',NULL),(4,'30000000-0000-4000-8000-000000000003','bundle_pub_3','EXTBNDLDDDDDDD',NULL,NULL,'e0000000-0000-4000-8000-000000000002','f0000000-0000-4000-8000-000000000013','1','0.3.0','published','{\"extension.json\": {\"version\": \"0.3.0\"}, \"package.json\": {\"name\": \"@devon/route-planner-engine\"}, \"composer.json\": {\"name\": \"devon/route-planner-api\"}}','2026-02-25 00:00:00','2026-03-01 00:00:00',NULL),(5,'30000000-0000-4000-8000-000000000006','bundle_pub_6','EXTBNDLEEEEEEE',NULL,NULL,'e0000000-0000-4000-8000-000000000002',NULL,'2',NULL,'pending',NULL,'2026-03-05 00:00:00','2026-03-05 00:00:00',NULL),(6,'30000000-0000-4000-8000-000000000004','bundle_pub_4','EXTBNDLFFFFFFF','c0000000-0000-4000-8000-000000000003',NULL,'e0000000-0000-4000-8000-000000000004','f0000000-0000-4000-8000-000000000014','1','1.0.0','pending','{\"package.json\": {\"name\": \"@acme/fleet-insights-engine\", \"version\": \"1.0.0\"}}','2024-09-01 00:00:00','2024-09-01 00:00:00',NULL);
UNLOCK TABLES;

--
-- Table structure for table `registry_extension_purchases`
--

DROP TABLE IF EXISTS `registry_extension_purchases`;
CREATE TABLE `registry_extension_purchases` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `purchaser_uuid` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `purchaser_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `company_uuid` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `extension_uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stripe_checkout_session_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stripe_payment_intent_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_subcription` tinyint(1) NOT NULL DEFAULT '0',
  `locked_price` int DEFAULT NULL,
  `subscription_billing_period` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subscription_model` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `meta` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

LOCK TABLES `registry_extension_purchases` WRITE;
INSERT INTO `registry_extension_purchases` VALUES (1,'50000000-0000-4000-8000-000000000001','c0000000-0000-4000-8000-000000000002','Fleetbase\\Models\\Company','c0000000-0000-4000-8000-000000000002','e0000000-0000-4000-8000-000000000001','cs_test_1','pi_test_1',0,4900,NULL,NULL,NULL,'2024-07-02 00:00:00','2024-07-02 00:00:00',NULL),(2,'50000000-0000-4000-8000-000000000002','c0000000-0000-4000-8000-000000000003','Fleetbase\\Models\\Company','c0000000-0000-4000-8000-000000000003','e0000000-0000-4000-8000-000000000001','cs_test_2','pi_test_2',0,NULL,NULL,NULL,NULL,'2024-07-03 00:00:00','2024-07-10 00:00:00','2024-07-10 00:00:00');
UNLOCK TABLES;

--
-- Table structure for table `registry_extension_installs`
--

DROP TABLE IF EXISTS `registry_extension_installs`;
CREATE TABLE `registry_extension_installs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `extension_uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `meta` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

LOCK TABLES `registry_extension_installs` WRITE;
INSERT INTO `registry_extension_installs` VALUES (1,'60000000-0000-4000-8000-000000000001','c0000000-0000-4000-8000-000000000002','e0000000-0000-4000-8000-000000000001',NULL,'2024-07-02 01:00:00','2024-07-02 01:00:00',NULL),(2,'60000000-0000-4000-8000-000000000002','c0000000-0000-4000-8000-000000000003','e0000000-0000-4000-8000-000000000002',NULL,'2026-03-10 00:00:00','2026-03-10 00:00:00',NULL),(3,'60000000-0000-4000-8000-000000000003','c0000000-0000-4000-8000-000000000003','e0000000-0000-4000-8000-000000000002',NULL,'2026-03-02 00:00:00','2026-03-05 00:00:00','2026-03-05 00:00:00'),(4,'60000000-0000-4000-8000-000000000004','c0000000-0000-4000-8000-000000000001','e0000000-0000-4000-8000-000000000001',NULL,'2024-05-02 00:00:00','2024-05-02 00:00:00',NULL);
UNLOCK TABLES;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;

-- Dump completed on 2026-10-20  3:00:01
