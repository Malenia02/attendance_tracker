/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `activity_logs` (
  `activity_log_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `activity_type` varchar(100) NOT NULL,
  `description` varchar(500) NOT NULL,
  `entity_type` varchar(100) DEFAULT NULL,
  `entity_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `request_id` char(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`activity_log_id`),
  KEY `idx_activity_user_datetime` (`user_id`,`created_at`),
  KEY `idx_activity_type_datetime` (`activity_type`,`created_at`),
  KEY `idx_activity_entity` (`entity_type`,`entity_id`),
  KEY `idx_activity_request_id` (`request_id`),
  CONSTRAINT `fk_activity_user` FOREIGN KEY (`user_id`) REFERENCES `system_users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `attendance_change_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attendance_change_logs` (
  `attendance_change_log_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `attendance_id` bigint(20) unsigned NOT NULL,
  `changed_by` bigint(20) unsigned DEFAULT NULL,
  `action_type` enum('Created','Updated','Verified','Unverified','Deleted','Restored') NOT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`attendance_change_log_id`),
  KEY `idx_attendance_change_attendance` (`attendance_id`,`created_at`),
  KEY `idx_attendance_change_user` (`changed_by`,`created_at`),
  KEY `idx_attendance_change_action` (`action_type`,`created_at`),
  CONSTRAINT `fk_attendance_change_attendance` FOREIGN KEY (`attendance_id`) REFERENCES `attendance_records` (`attendance_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_attendance_change_user` FOREIGN KEY (`changed_by`) REFERENCES `system_users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `attendance_correction_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attendance_correction_requests` (
  `attendance_correction_request_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `attendance_id` bigint(20) unsigned NOT NULL,
  `personnel_id` bigint(20) unsigned NOT NULL,
  `submitted_by` bigint(20) unsigned DEFAULT NULL,
  `attendance_date` date NOT NULL,
  `missing_field` enum('morning_time_out','afternoon_time_out') NOT NULL,
  `proposed_time` time NOT NULL,
  `reason` varchar(500) NOT NULL,
  `request_status` enum('Pending','Approved','Rejected','Cancelled') NOT NULL DEFAULT 'Pending',
  `pending_key` varchar(191) DEFAULT NULL,
  `reviewed_by` bigint(20) unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_remarks` varchar(500) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`attendance_correction_request_id`),
  UNIQUE KEY `attendance_correction_requests_pending_key_unique` (`pending_key`),
  KEY `attendance_correction_requests_attendance_id_foreign` (`attendance_id`),
  KEY `attendance_correction_requests_reviewed_by_foreign` (`reviewed_by`),
  KEY `idx_attendance_correction_date_status` (`attendance_date`,`request_status`),
  KEY `idx_attendance_correction_personnel_date` (`personnel_id`,`attendance_date`),
  KEY `idx_attendance_correction_submitter` (`submitted_by`,`created_at`),
  CONSTRAINT `attendance_correction_requests_attendance_id_foreign` FOREIGN KEY (`attendance_id`) REFERENCES `attendance_records` (`attendance_id`) ON UPDATE CASCADE,
  CONSTRAINT `attendance_correction_requests_personnel_id_foreign` FOREIGN KEY (`personnel_id`) REFERENCES `personnel` (`personnel_id`) ON UPDATE CASCADE,
  CONSTRAINT `attendance_correction_requests_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `system_users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `attendance_correction_requests_submitted_by_foreign` FOREIGN KEY (`submitted_by`) REFERENCES `system_users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `attendance_qr_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attendance_qr_tokens` (
  `qr_token_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `department_id` int(10) unsigned DEFAULT NULL,
  `token_hash` char(64) NOT NULL,
  `purpose` enum('Attendance','Morning Time In','Morning Time Out','Afternoon Time In','Afternoon Time Out','Overtime Time In','Overtime Time Out') NOT NULL DEFAULT 'Attendance',
  `valid_from` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_count` int(10) unsigned NOT NULL DEFAULT 0,
  `maximum_uses` int(10) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`qr_token_id`),
  UNIQUE KEY `uq_qr_token_hash` (`token_hash`),
  KEY `fk_qr_created_by` (`created_by`),
  KEY `idx_qr_expiration` (`is_active`,`valid_from`,`expires_at`),
  KEY `idx_qr_department_expiration` (`department_id`,`expires_at`),
  CONSTRAINT `fk_qr_created_by` FOREIGN KEY (`created_by`) REFERENCES `system_users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_qr_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `attendance_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attendance_records` (
  `attendance_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `personnel_id` bigint(20) unsigned NOT NULL,
  `schedule_id` int(10) unsigned DEFAULT NULL,
  `attendance_date` date NOT NULL,
  `morning_time_in` datetime DEFAULT NULL,
  `morning_time_out` datetime DEFAULT NULL,
  `afternoon_time_in` datetime DEFAULT NULL,
  `afternoon_time_out` datetime DEFAULT NULL,
  `overtime_time_in` datetime DEFAULT NULL,
  `overtime_time_out` datetime DEFAULT NULL,
  `attendance_status` enum('Present','Half Day','Absent','Leave','Holiday','Rest Day','Official Business','Work From Home','Incomplete') NOT NULL DEFAULT 'Incomplete',
  `total_work_minutes` int(10) unsigned NOT NULL DEFAULT 0,
  `late_minutes` int(10) unsigned NOT NULL DEFAULT 0,
  `undertime_minutes` int(10) unsigned NOT NULL DEFAULT 0,
  `overtime_minutes` int(10) unsigned NOT NULL DEFAULT 0,
  `remarks` varchar(255) DEFAULT NULL,
  `record_source` enum('Web Portal','Manual','Biometric','QR Code','RFID','System') NOT NULL DEFAULT 'Web Portal',
  `is_verified` tinyint(1) NOT NULL DEFAULT 0,
  `verified_by` bigint(20) unsigned DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`attendance_id`),
  UNIQUE KEY `uq_attendance_personnel_date` (`personnel_id`,`attendance_date`),
  KEY `fk_attendance_verified_by` (`verified_by`),
  KEY `fk_attendance_created_by` (`created_by`),
  KEY `idx_attendance_date` (`attendance_date`),
  KEY `idx_attendance_status` (`attendance_status`),
  KEY `idx_attendance_schedule` (`schedule_id`),
  KEY `idx_attendance_date_status` (`attendance_date`,`attendance_status`),
  KEY `idx_attendance_personnel_date_status` (`personnel_id`,`attendance_date`,`attendance_status`),
  KEY `idx_attendance_verification` (`is_verified`,`attendance_date`),
  CONSTRAINT `fk_attendance_created_by` FOREIGN KEY (`created_by`) REFERENCES `system_users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_attendance_personnel` FOREIGN KEY (`personnel_id`) REFERENCES `personnel` (`personnel_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_attendance_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `work_schedules` (`schedule_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_attendance_verified_by` FOREIGN KEY (`verified_by`) REFERENCES `system_users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `departments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `departments` (
  `department_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `department_code` varchar(30) NOT NULL,
  `department_name` varchar(150) NOT NULL,
  `office_location` varchar(255) DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `allowed_radius_meters` int(10) unsigned NOT NULL DEFAULT 100,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`department_id`),
  UNIQUE KEY `uq_department_code` (`department_code`),
  KEY `idx_department_name` (`department_name`),
  KEY `idx_department_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `dtr_certification_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `dtr_certification_versions` (
  `dtr_certification_version_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `dtr_certification_id` bigint(20) unsigned NOT NULL,
  `version_number` smallint(5) unsigned NOT NULL,
  `prepared_by` bigint(20) unsigned DEFAULT NULL,
  `certified_by` bigint(20) unsigned DEFAULT NULL,
  `archived_by` bigint(20) unsigned DEFAULT NULL,
  `prepared_at` datetime DEFAULT NULL,
  `certified_at` datetime NOT NULL,
  `archived_at` datetime NOT NULL,
  `archive_reason` varchar(1000) NOT NULL,
  `certified_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`certified_snapshot`)),
  `certified_hash` char(64) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`dtr_certification_version_id`),
  UNIQUE KEY `uq_dtr_certification_version` (`dtr_certification_id`,`version_number`),
  KEY `fk_dtr_version_prepared_by` (`prepared_by`),
  KEY `fk_dtr_version_certified_by` (`certified_by`),
  KEY `fk_dtr_version_archived_by` (`archived_by`),
  CONSTRAINT `fk_dtr_version_archived_by` FOREIGN KEY (`archived_by`) REFERENCES `system_users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_dtr_version_certification` FOREIGN KEY (`dtr_certification_id`) REFERENCES `dtr_certifications` (`dtr_certification_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_dtr_version_certified_by` FOREIGN KEY (`certified_by`) REFERENCES `system_users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_dtr_version_prepared_by` FOREIGN KEY (`prepared_by`) REFERENCES `system_users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `dtr_certifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `dtr_certifications` (
  `dtr_certification_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `personnel_id` bigint(20) unsigned NOT NULL,
  `dtr_year` smallint(5) unsigned NOT NULL,
  `dtr_month` tinyint(3) unsigned NOT NULL,
  `dtr_period` enum('first_half','second_half','full_month') NOT NULL DEFAULT 'full_month',
  `version_number` smallint(5) unsigned NOT NULL DEFAULT 1,
  `prepared_by` bigint(20) unsigned DEFAULT NULL,
  `certified_by` bigint(20) unsigned DEFAULT NULL,
  `prepared_at` datetime DEFAULT NULL,
  `certified_at` datetime DEFAULT NULL,
  `certification_status` enum('Draft','Submitted','Certified','Returned','Reopened') NOT NULL DEFAULT 'Draft',
  `remarks` varchar(255) DEFAULT NULL,
  `certified_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`certified_snapshot`)),
  `certified_hash` char(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`dtr_certification_id`),
  UNIQUE KEY `uq_dtr_personnel_period` (`personnel_id`,`dtr_year`,`dtr_month`,`dtr_period`),
  KEY `fk_dtr_prepared_by` (`prepared_by`),
  KEY `fk_dtr_certified_by` (`certified_by`),
  KEY `idx_dtr_personnel_fk` (`personnel_id`),
  KEY `idx_dtr_year_month` (`dtr_year`,`dtr_month`),
  KEY `idx_dtr_status` (`certification_status`),
  KEY `idx_dtr_period_status_personnel` (`dtr_year`,`dtr_month`,`dtr_period`,`certification_status`,`personnel_id`),
  CONSTRAINT `fk_dtr_certified_by` FOREIGN KEY (`certified_by`) REFERENCES `system_users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_dtr_personnel` FOREIGN KEY (`personnel_id`) REFERENCES `personnel` (`personnel_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_dtr_prepared_by` FOREIGN KEY (`prepared_by`) REFERENCES `system_users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `dtr_reopen_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `dtr_reopen_requests` (
  `dtr_reopen_request_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `dtr_certification_id` bigint(20) unsigned NOT NULL,
  `requested_by` bigint(20) unsigned DEFAULT NULL,
  `reviewed_by` bigint(20) unsigned DEFAULT NULL,
  `reason` varchar(1000) NOT NULL,
  `affected_dates` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`affected_dates`)),
  `request_status` enum('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  `review_remarks` varchar(1000) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `pending_key` varchar(64) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `request_id` char(36) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`dtr_reopen_request_id`),
  UNIQUE KEY `dtr_reopen_requests_request_id_unique` (`request_id`),
  UNIQUE KEY `dtr_reopen_requests_pending_key_unique` (`pending_key`),
  KEY `fk_dtr_reopen_requested_by` (`requested_by`),
  KEY `fk_dtr_reopen_reviewed_by` (`reviewed_by`),
  KEY `idx_dtr_reopen_history` (`dtr_certification_id`,`request_status`,`created_at`),
  CONSTRAINT `fk_dtr_reopen_certification` FOREIGN KEY (`dtr_certification_id`) REFERENCES `dtr_certifications` (`dtr_certification_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_dtr_reopen_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `system_users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_dtr_reopen_reviewed_by` FOREIGN KEY (`reviewed_by`) REFERENCES `system_users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `dtr_status_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `dtr_status_logs` (
  `dtr_status_log_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `dtr_certification_id` bigint(20) unsigned NOT NULL,
  `changed_by` bigint(20) unsigned DEFAULT NULL,
  `from_status` varchar(20) NOT NULL,
  `to_status` varchar(20) NOT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `request_id` char(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`dtr_status_log_id`),
  KEY `dtr_status_logs_changed_by_foreign` (`changed_by`),
  KEY `idx_dtr_status_history` (`dtr_certification_id`,`created_at`),
  KEY `dtr_status_logs_request_id_index` (`request_id`),
  CONSTRAINT `dtr_status_logs_changed_by_foreign` FOREIGN KEY (`changed_by`) REFERENCES `system_users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `dtr_status_logs_dtr_certification_id_foreign` FOREIGN KEY (`dtr_certification_id`) REFERENCES `dtr_certifications` (`dtr_certification_id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `holidays`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `holidays` (
  `holiday_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `holiday_date` date NOT NULL,
  `holiday_name` varchar(150) NOT NULL,
  `holiday_type` enum('Regular Holiday','Special Non-Working Holiday','Special Working Holiday','Local Holiday','Office Suspension') NOT NULL,
  `scope` enum('National','Regional','Provincial','Municipal','Office') NOT NULL DEFAULT 'National',
  `department_id` int(10) unsigned DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`holiday_id`),
  UNIQUE KEY `uq_holiday_date_name` (`holiday_date`,`holiday_name`),
  KEY `fk_holiday_created_by` (`created_by`),
  KEY `idx_holiday_date` (`holiday_date`),
  KEY `idx_holiday_type` (`holiday_type`),
  KEY `idx_holiday_scope_date` (`scope`,`holiday_date`),
  KEY `idx_holiday_department_date` (`department_id`,`holiday_date`),
  CONSTRAINT `fk_holiday_created_by` FOREIGN KEY (`created_by`) REFERENCES `system_users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_holiday_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `leave_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_records` (
  `leave_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `personnel_id` bigint(20) unsigned NOT NULL,
  `leave_type` enum('Vacation Leave','Sick Leave','Emergency Leave','Maternity Leave','Paternity Leave','Special Leave','Official Business','Other') NOT NULL,
  `date_from` date NOT NULL,
  `date_to` date NOT NULL,
  `total_days` decimal(5,2) NOT NULL DEFAULT 1.00,
  `reason` text DEFAULT NULL,
  `approval_status` enum('Pending','Approved','Rejected','Cancelled') NOT NULL DEFAULT 'Pending',
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`leave_id`),
  KEY `fk_leave_approved_by` (`approved_by`),
  KEY `idx_leave_personnel` (`personnel_id`),
  KEY `idx_leave_dates` (`date_from`,`date_to`),
  KEY `idx_leave_status` (`approval_status`),
  KEY `idx_leave_personnel_status_dates` (`personnel_id`,`approval_status`,`date_from`,`date_to`),
  CONSTRAINT `fk_leave_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `system_users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_leave_personnel` FOREIGN KEY (`personnel_id`) REFERENCES `personnel` (`personnel_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `monthly_attendance_summary_view`;
/*!50001 DROP VIEW IF EXISTS `monthly_attendance_summary_view`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `monthly_attendance_summary_view` AS SELECT
 1 AS `personnel_id`,
  1 AS `attendance_year`,
  1 AS `attendance_month`,
  1 AS `total_records`,
  1 AS `days_present`,
  1 AS `days_absent`,
  1 AS `days_on_leave`,
  1 AS `holidays`,
  1 AS `incomplete_records`,
  1 AS `total_work_minutes`,
  1 AS `total_late_minutes`,
  1 AS `total_undertime_minutes`,
  1 AS `total_overtime_minutes` */;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `personnel`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `personnel` (
  `personnel_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `employee_number` varchar(50) NOT NULL,
  `biometric_number` varchar(50) DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `suffix` varchar(20) DEFAULT NULL,
  `sex` enum('Male','Female','Prefer Not to Say') DEFAULT NULL,
  `personnel_type` enum('GIP','Regular','Contractual','Job Order','Casual','Other') NOT NULL DEFAULT 'GIP',
  `position_title` varchar(150) DEFAULT NULL,
  `department_id` int(10) unsigned DEFAULT NULL,
  `employment_start_date` date DEFAULT NULL,
  `employment_end_date` date DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `contact_number` varchar(30) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `qr_login_code` varchar(100) DEFAULT NULL,
  `status` enum('Active','Inactive','Completed','Terminated') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`personnel_id`),
  UNIQUE KEY `uq_personnel_employee_number` (`employee_number`),
  UNIQUE KEY `uq_personnel_biometric_number` (`biometric_number`),
  UNIQUE KEY `uq_personnel_qr_login_code` (`qr_login_code`),
  KEY `idx_personnel_full_name` (`last_name`,`first_name`,`middle_name`),
  KEY `idx_personnel_type` (`personnel_type`),
  KEY `idx_personnel_department` (`department_id`),
  KEY `idx_personnel_status` (`status`),
  KEY `idx_personnel_type_status` (`personnel_type`,`status`),
  KEY `idx_personnel_employment_dates` (`employment_start_date`,`employment_end_date`),
  CONSTRAINT `fk_personnel_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `personnel_details_view`;
/*!50001 DROP VIEW IF EXISTS `personnel_details_view`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `personnel_details_view` AS SELECT
 1 AS `personnel_id`,
  1 AS `employee_number`,
  1 AS `full_name`,
  1 AS `personnel_type`,
  1 AS `position_title`,
  1 AS `employment_start_date`,
  1 AS `employment_end_date`,
  1 AS `status`,
  1 AS `department_code`,
  1 AS `department_name`,
  1 AS `office_location` */;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `personnel_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `personnel_schedules` (
  `personnel_schedule_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `personnel_id` bigint(20) unsigned NOT NULL,
  `schedule_id` int(10) unsigned NOT NULL,
  `effective_from` date NOT NULL,
  `effective_to` date DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`personnel_schedule_id`),
  UNIQUE KEY `uq_personnel_schedule_effective` (`personnel_id`,`effective_from`),
  KEY `fk_personnel_schedule_created_by` (`created_by`),
  KEY `idx_personnel_schedule_dates` (`personnel_id`,`effective_from`,`effective_to`),
  KEY `idx_personnel_schedule_schedule` (`schedule_id`),
  CONSTRAINT `fk_personnel_schedule_created_by` FOREIGN KEY (`created_by`) REFERENCES `system_users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_personnel_schedule_personnel` FOREIGN KEY (`personnel_id`) REFERENCES `personnel` (`personnel_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_personnel_schedule_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `work_schedules` (`schedule_id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `printable_dtr_view`;
/*!50001 DROP VIEW IF EXISTS `printable_dtr_view`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `printable_dtr_view` AS SELECT
 1 AS `attendance_id`,
  1 AS `personnel_id`,
  1 AS `employee_number`,
  1 AS `full_name`,
  1 AS `personnel_type`,
  1 AS `position_title`,
  1 AS `department_name`,
  1 AS `attendance_date`,
  1 AS `morning_time_in`,
  1 AS `morning_time_out`,
  1 AS `afternoon_time_in`,
  1 AS `afternoon_time_out`,
  1 AS `overtime_time_in`,
  1 AS `overtime_time_out`,
  1 AS `attendance_status`,
  1 AS `total_work_minutes`,
  1 AS `late_minutes`,
  1 AS `undertime_minutes`,
  1 AS `overtime_minutes`,
  1 AS `remarks`,
  1 AS `is_verified` */;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `qr_scan_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `qr_scan_logs` (
  `qr_scan_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `qr_token_id` bigint(20) unsigned DEFAULT NULL,
  `personnel_id` bigint(20) unsigned DEFAULT NULL,
  `attendance_id` bigint(20) unsigned DEFAULT NULL,
  `scan_action` enum('Morning Time In','Morning Time Out','Afternoon Time In','Afternoon Time Out','Overtime Time In','Overtime Time Out','Unknown') NOT NULL DEFAULT 'Unknown',
  `scanned_at` datetime NOT NULL DEFAULT current_timestamp(),
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `location_accuracy_meters` decimal(8,2) DEFAULT NULL,
  `position_recorded_at` datetime DEFAULT NULL,
  `distance_from_office_meters` decimal(10,2) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `device_identifier` varchar(255) DEFAULT NULL,
  `scanned_by` bigint(20) unsigned DEFAULT NULL,
  `scan_status` enum('Accepted','Expired','Invalid','Duplicate','Outside Location','Inactive Personnel','Outside Contract','Wrong Schedule','Rejected') NOT NULL,
  `message` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`qr_scan_id`),
  KEY `idx_qr_scan_personnel_datetime` (`personnel_id`,`scanned_at`),
  KEY `idx_qr_scan_token_status` (`qr_token_id`,`scan_status`),
  KEY `idx_qr_scan_status_datetime` (`scan_status`,`scanned_at`),
  KEY `idx_qr_scan_attendance` (`attendance_id`),
  KEY `idx_qr_scan_operator_datetime` (`scanned_by`,`scanned_at`),
  CONSTRAINT `fk_qr_scan_attendance` FOREIGN KEY (`attendance_id`) REFERENCES `attendance_records` (`attendance_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_qr_scan_personnel` FOREIGN KEY (`personnel_id`) REFERENCES `personnel` (`personnel_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_qr_scan_token` FOREIGN KEY (`qr_token_id`) REFERENCES `attendance_qr_tokens` (`qr_token_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `system_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `system_users` (
  `user_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `personnel_id` bigint(20) unsigned DEFAULT NULL,
  `username` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `user_role` enum('Administrator','HR','Supervisor','Encoder','Personnel') NOT NULL DEFAULT 'Personnel',
  `status` enum('Active','Inactive','Locked') NOT NULL DEFAULT 'Active',
  `failed_login_attempts` smallint(5) unsigned NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uq_user_username` (`username`),
  UNIQUE KEY `uq_user_personnel` (`personnel_id`),
  KEY `idx_user_role` (`user_role`),
  KEY `idx_user_status` (`status`),
  KEY `idx_user_role_status` (`user_role`,`status`),
  CONSTRAINT `fk_user_personnel` FOREIGN KEY (`personnel_id`) REFERENCES `personnel` (`personnel_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `time_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `time_logs` (
  `time_log_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `personnel_id` bigint(20) unsigned NOT NULL,
  `attendance_id` bigint(20) unsigned DEFAULT NULL,
  `qr_scan_id` bigint(20) unsigned DEFAULT NULL,
  `log_datetime` datetime NOT NULL,
  `log_type` enum('Morning In','Morning Out','Afternoon In','Afternoon Out','Overtime In','Overtime Out') NOT NULL,
  `log_source` enum('Web Portal','Manual','Biometric','QR Code','RFID','System') NOT NULL DEFAULT 'Web Portal',
  `ip_address` varchar(45) DEFAULT NULL,
  `device_identifier` varchar(255) DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`time_log_id`),
  KEY `fk_timelog_created_by` (`created_by`),
  KEY `idx_timelog_personnel_datetime` (`personnel_id`,`log_datetime`),
  KEY `idx_timelog_datetime` (`log_datetime`),
  KEY `idx_timelog_attendance` (`attendance_id`),
  KEY `idx_timelog_qr_scan` (`qr_scan_id`),
  KEY `idx_timelog_type_datetime` (`log_type`,`log_datetime`),
  CONSTRAINT `fk_timelog_attendance` FOREIGN KEY (`attendance_id`) REFERENCES `attendance_records` (`attendance_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_timelog_created_by` FOREIGN KEY (`created_by`) REFERENCES `system_users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_timelog_personnel` FOREIGN KEY (`personnel_id`) REFERENCES `personnel` (`personnel_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_timelog_qr_scan` FOREIGN KEY (`qr_scan_id`) REFERENCES `qr_scan_logs` (`qr_scan_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_access_tokens` (
  `token_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `token_hash` char(64) NOT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`token_id`),
  UNIQUE KEY `user_access_tokens_token_hash_unique` (`token_hash`),
  KEY `user_access_tokens_user_id_expires_at_index` (`user_id`,`expires_at`),
  KEY `user_access_tokens_expires_at_index` (`expires_at`),
  CONSTRAINT `user_access_tokens_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `system_users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `work_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `work_schedules` (
  `schedule_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `schedule_name` varchar(100) NOT NULL,
  `morning_start` time DEFAULT '08:00:00',
  `morning_end` time DEFAULT '12:00:00',
  `afternoon_start` time DEFAULT '13:00:00',
  `afternoon_end` time DEFAULT '17:00:00',
  `morning_time_in_start` time DEFAULT '05:00:00',
  `morning_time_in_end` time DEFAULT '11:00:00',
  `morning_time_out_start` time DEFAULT '11:00:00',
  `morning_time_out_end` time DEFAULT '13:00:00',
  `afternoon_time_in_start` time DEFAULT '12:00:00',
  `afternoon_time_in_end` time DEFAULT '16:00:00',
  `afternoon_time_out_start` time DEFAULT '17:00:00',
  `afternoon_time_out_end` time DEFAULT '23:59:59',
  `grace_period_minutes` smallint(5) unsigned NOT NULL DEFAULT 0,
  `required_minutes_per_day` smallint(5) unsigned NOT NULL DEFAULT 480,
  `monday` tinyint(1) NOT NULL DEFAULT 1,
  `tuesday` tinyint(1) NOT NULL DEFAULT 1,
  `wednesday` tinyint(1) NOT NULL DEFAULT 1,
  `thursday` tinyint(1) NOT NULL DEFAULT 1,
  `friday` tinyint(1) NOT NULL DEFAULT 1,
  `saturday` tinyint(1) NOT NULL DEFAULT 0,
  `sunday` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`schedule_id`),
  UNIQUE KEY `uq_schedule_name` (`schedule_name`),
  KEY `idx_schedule_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50001 DROP VIEW IF EXISTS `monthly_attendance_summary_view`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `monthly_attendance_summary_view` AS select `ar`.`personnel_id` AS `personnel_id`,year(`ar`.`attendance_date`) AS `attendance_year`,month(`ar`.`attendance_date`) AS `attendance_month`,count(0) AS `total_records`,sum(`ar`.`attendance_status` = 'Present') AS `days_present`,sum(`ar`.`attendance_status` = 'Absent') AS `days_absent`,sum(`ar`.`attendance_status` = 'Leave') AS `days_on_leave`,sum(`ar`.`attendance_status` = 'Holiday') AS `holidays`,sum(`ar`.`attendance_status` = 'Incomplete') AS `incomplete_records`,sum(`ar`.`total_work_minutes`) AS `total_work_minutes`,sum(`ar`.`late_minutes`) AS `total_late_minutes`,sum(`ar`.`undertime_minutes`) AS `total_undertime_minutes`,sum(`ar`.`overtime_minutes`) AS `total_overtime_minutes` from `attendance_records` `ar` group by `ar`.`personnel_id`,year(`ar`.`attendance_date`),month(`ar`.`attendance_date`) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `personnel_details_view`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `personnel_details_view` AS select `p`.`personnel_id` AS `personnel_id`,`p`.`employee_number` AS `employee_number`,concat_ws(' ',`p`.`first_name`,nullif(`p`.`middle_name`,''),`p`.`last_name`,nullif(`p`.`suffix`,'')) AS `full_name`,`p`.`personnel_type` AS `personnel_type`,`p`.`position_title` AS `position_title`,`p`.`employment_start_date` AS `employment_start_date`,`p`.`employment_end_date` AS `employment_end_date`,`p`.`status` AS `status`,`d`.`department_code` AS `department_code`,`d`.`department_name` AS `department_name`,`d`.`office_location` AS `office_location` from (`personnel` `p` left join `departments` `d` on(`d`.`department_id` = `p`.`department_id`)) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `printable_dtr_view`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `printable_dtr_view` AS select `ar`.`attendance_id` AS `attendance_id`,`ar`.`personnel_id` AS `personnel_id`,`p`.`employee_number` AS `employee_number`,concat_ws(' ',`p`.`first_name`,nullif(`p`.`middle_name`,''),`p`.`last_name`,nullif(`p`.`suffix`,'')) AS `full_name`,`p`.`personnel_type` AS `personnel_type`,`p`.`position_title` AS `position_title`,`d`.`department_name` AS `department_name`,`ar`.`attendance_date` AS `attendance_date`,date_format(`ar`.`morning_time_in`,'%h:%i %p') AS `morning_time_in`,date_format(`ar`.`morning_time_out`,'%h:%i %p') AS `morning_time_out`,date_format(`ar`.`afternoon_time_in`,'%h:%i %p') AS `afternoon_time_in`,date_format(`ar`.`afternoon_time_out`,'%h:%i %p') AS `afternoon_time_out`,date_format(`ar`.`overtime_time_in`,'%h:%i %p') AS `overtime_time_in`,date_format(`ar`.`overtime_time_out`,'%h:%i %p') AS `overtime_time_out`,`ar`.`attendance_status` AS `attendance_status`,`ar`.`total_work_minutes` AS `total_work_minutes`,`ar`.`late_minutes` AS `late_minutes`,`ar`.`undertime_minutes` AS `undertime_minutes`,`ar`.`overtime_minutes` AS `overtime_minutes`,`ar`.`remarks` AS `remarks`,`ar`.`is_verified` AS `is_verified` from ((`attendance_records` `ar` join `personnel` `p` on(`p`.`personnel_id` = `ar`.`personnel_id`)) left join `departments` `d` on(`d`.`department_id` = `p`.`department_id`)) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'2026_07_23_120000_create_user_access_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'0001_01_01_000001_create_cache_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'0001_01_01_000000_create_users_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'0001_01_01_000002_create_jobs_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5,'2026_07_23_130000_add_half_day_attendance_status',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2026_07_25_140000_add_scanned_by_to_qr_scan_logs',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2026_07_25_180000_create_dtr_status_logs_table',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2026_07_25_190000_harden_attendance_security',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2026_07_26_150000_create_attendance_correction_requests_table',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'2026_07_26_170000_create_dtr_reopening_workflow',8);
