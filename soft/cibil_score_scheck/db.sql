CREATE DATABASE IF NOT EXISTS `cibil_db`;
USE `cibil_db`;

CREATE TABLE IF NOT EXISTS `credit_reports` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `orderid` VARCHAR(100) NOT NULL,
    `name` VARCHAR(150) NOT NULL,
    `mobile` VARCHAR(15) NOT NULL,
    `fetch_by` VARCHAR(20) NOT NULL,
    `number` VARCHAR(50) NOT NULL,
    `credit_score` INT DEFAULT NULL,
    `api_status` VARCHAR(50) NULL,
    `response_json` LONGTEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
