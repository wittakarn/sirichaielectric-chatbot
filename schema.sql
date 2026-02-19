-- MySQL dump 10.13  Distrib 5.7.39, for osx11.0 (x86_64)
--
-- Host: localhost    Database: chatbotdb
-- ------------------------------------------------------
-- Server version	5.7.39

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `conversations`
--

DROP TABLE IF EXISTS `conversations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `conversations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `conversation_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `platform` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'api' COMMENT 'api or line',
  `user_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'LINE user ID for line platform',
  `max_messages_limit` int(11) NOT NULL DEFAULT '20',
  `is_chatbot_active` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1=chatbot active, 0=paused for human agent',
  `paused_at` timestamp NULL DEFAULT NULL COMMENT 'Timestamp when chatbot was paused',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_activity` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `conversation_id` (`conversation_id`),
  KEY `idx_conversation_id` (`conversation_id`),
  KEY `idx_platform` (`platform`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_last_activity` (`last_activity`),
  KEY `idx_chatbot_active` (`is_chatbot_active`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `conversations`
--

LOCK TABLES `conversations` WRITE;
/*!40000 ALTER TABLE `conversations` DISABLE KEYS */;
/*!40000 ALTER TABLE `conversations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `messages`
--

DROP TABLE IF EXISTS `messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `conversation_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` enum('user','assistant') COLLATE utf8mb4_unicode_ci NOT NULL,
  `content` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `tokens_used` int(11) NOT NULL DEFAULT '0' COMMENT 'Gemini API tokens for this message',
  `sequence_number` int(11) NOT NULL COMMENT 'Message order within conversation',
  `search_criteria` text COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'JSON array of search categories used by AI',
  `is_active` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1=active (used in chat history), 0=inactive (hidden after /reset)',
  PRIMARY KEY (`id`),
  KEY `idx_conversation_id` (`conversation_id`),
  KEY `idx_timestamp` (`timestamp`),
  KEY `idx_sequence` (`conversation_id`,`sequence_number`),
  KEY `idx_is_active` (`conversation_id`,`is_active`),
  CONSTRAINT `fk_conversation` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`conversation_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `messages`
--

LOCK TABLES `messages` WRITE;
/*!40000 ALTER TABLE `messages` DISABLE KEYS */;
/*!40000 ALTER TABLE `messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `authorized_users`
--

DROP TABLE IF EXISTS `authorized_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `authorized_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'LINE user ID or internal user identifier',
  `name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Display name for reference',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Users authorized to generate quotation PDFs';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `authorized_users`
--

LOCK TABLES `authorized_users` WRITE;
/*!40000 ALTER TABLE `authorized_users` DISABLE KEYS */;
INSERT IGNORE INTO `authorized_users` (`user_id`, `name`) VALUES ('Ucaba7f422b7205234c96c2e52531653e', 'X');
INSERT IGNORE INTO `authorized_users` (`user_id`, `name`) VALUES ('Ufaebdbf00c01bdec6d760f49c4c42774', 'Pou');
/*!40000 ALTER TABLE `authorized_users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'chatbotdb'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-01-29  0:22:13
