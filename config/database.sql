-- Network Monitoring Database Schema
-- Created: October 20, 2012
-- MySQL 5.5 Compatible
-- Designed for network device monitoring and historical data storage

-- Database creation
CREATE DATABASE IF NOT EXISTS network_monitoring 
CHARACTER SET utf8 COLLATE utf8_general_ci;

USE network_monitoring;

-- Devices table - stores network device information
CREATE TABLE devices (
    device_id INT AUTO_INCREMENT PRIMARY KEY,
    device_name VARCHAR(255) NOT NULL,
    ip_address VARCHAR(15) NOT NULL UNIQUE,
    device_type ENUM('router', 'switch', 'server', 'firewall', 'other') DEFAULT 'other',
    snmp_community VARCHAR(50) DEFAULT 'public',
    location VARCHAR(255),
    description TEXT,
    status ENUM('active', 'inactive', 'maintenance') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ip_address (ip_address),
    INDEX idx_status (status),
    INDEX idx_device_type (device_type)
) ENGINE=InnoDB;

-- Device interfaces table
CREATE TABLE device_interfaces (
    interface_id INT AUTO_INCREMENT PRIMARY KEY,
    device_id INT NOT NULL,
    interface_index INT NOT NULL,
    interface_name VARCHAR(255) NOT NULL,
    interface_type INT,
    interface_mtu INT,
    interface_speed BIGINT,
    admin_status ENUM('up', 'down', 'testing') DEFAULT 'up',
    oper_status ENUM('up', 'down', 'testing', 'unknown', 'dormant', 'notPresent', 'lowerLayerDown') DEFAULT 'unknown',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (device_id) REFERENCES devices(device_id) ON DELETE CASCADE,
    UNIQUE KEY unique_device_interface (device_id, interface_index),
    INDEX idx_device_id (device_id),
    INDEX idx_oper_status (oper_status)
) ENGINE=InnoDB;

-- Monitoring data table - stores historical SNMP polling results
CREATE TABLE monitoring_data (
    data_id INT AUTO_INCREMENT PRIMARY KEY,
    device_id INT NOT NULL,
    interface_id INT NULL,
    metric_name VARCHAR(100) NOT NULL,
    metric_value VARCHAR(255),
    numeric_value DECIMAL(20,2) NULL,  -- For numeric calculations
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (device_id) REFERENCES devices(device_id) ON DELETE CASCADE,
    FOREIGN KEY (interface_id) REFERENCES device_interfaces(interface_id) ON DELETE CASCADE,
    INDEX idx_device_timestamp (device_id, timestamp),
    INDEX idx_metric_name (metric_name),
    INDEX idx_timestamp (timestamp)
) ENGINE=InnoDB;

-- Alerts table - stores network alerts and notifications
CREATE TABLE alerts (
    alert_id INT AUTO_INCREMENT PRIMARY KEY,
    device_id INT NOT NULL,
    interface_id INT NULL,
    alert_type ENUM('device_down', 'interface_down', 'high_utilization', 'threshold_exceeded', 'snmp_timeout') NOT NULL,
    severity ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    message TEXT NOT NULL,
    status ENUM('open', 'acknowledged', 'resolved') DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    acknowledged_at TIMESTAMP NULL,
    resolved_at TIMESTAMP NULL,
    FOREIGN KEY (device_id) REFERENCES devices(device_id) ON DELETE CASCADE,
    FOREIGN KEY (interface_id) REFERENCES device_interfaces(interface_id) ON DELETE CASCADE,
    INDEX idx_device_id (device_id),
    INDEX idx_status (status),
    INDEX idx_severity (severity),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB;

-- System configuration table
CREATE TABLE system_config (
    config_id INT AUTO_INCREMENT PRIMARY KEY,
    config_key VARCHAR(100) NOT NULL UNIQUE,
    config_value TEXT,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Polling schedule table
CREATE TABLE polling_schedule (
    schedule_id INT AUTO_INCREMENT PRIMARY KEY,
    device_id INT NOT NULL,
    poll_interval INT DEFAULT 300,  -- seconds
    last_poll TIMESTAMP NULL,
    next_poll TIMESTAMP NULL,
    enabled BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (device_id) REFERENCES devices(device_id) ON DELETE CASCADE,
    INDEX idx_next_poll (next_poll),
    INDEX idx_enabled (enabled)
) ENGINE=InnoDB;

-- Insert default configuration values (2012 standards)
INSERT INTO system_config (config_key, config_value, description) VALUES
('default_snmp_community', 'public', 'Default SNMP community string for new devices'),
('default_poll_interval', '300', 'Default polling interval in seconds'),
('alert_retention_days', '30', 'Number of days to keep resolved alerts'),
('data_retention_days', '90', 'Number of days to keep monitoring data'),
('nagios_integration', '1', 'Enable Nagios integration'),
('email_alerts', '1', 'Enable email alert notifications'),
('dashboard_refresh', '30', 'Dashboard auto-refresh interval in seconds');

-- Insert sample devices for testing (2012 environment)
INSERT INTO devices (device_name, ip_address, device_type, location, description) VALUES
('Core Router', '192.168.1.1', 'router', 'Server Room A', 'Main gateway router'),
('Distribution Switch', '192.168.1.10', 'switch', 'Server Room A', 'Primary distribution switch'),
('File Server', '192.168.1.100', 'server', 'Server Room A', 'Main file server'),
('Backup Server', '192.168.1.101', 'server', 'Server Room A', 'Backup and archive server');

-- Create initial polling schedules for sample devices
INSERT INTO polling_schedule (device_id, poll_interval, next_poll, enabled)
SELECT device_id, 300, NOW(), TRUE FROM devices;

-- Views for common queries (MySQL 5.5 compatible)
CREATE VIEW device_status AS
SELECT 
    d.device_id,
    d.device_name,
    d.ip_address,
    d.device_type,
    d.status,
    COUNT(DISTINCT di.interface_id) as interface_count,
    COUNT(DISTINCT CASE WHEN a.status = 'open' THEN a.alert_id END) as open_alerts,
    MAX(md.timestamp) as last_data_received
FROM devices d
LEFT JOIN device_interfaces di ON d.device_id = di.device_id
LEFT JOIN alerts a ON d.device_id = a.device_id
LEFT JOIN monitoring_data md ON d.device_id = md.device_id
GROUP BY d.device_id, d.device_name, d.ip_address, d.device_type, d.status;

-- Create user for application (2012 security practices)
-- Note: In production, use a stronger password
CREATE USER 'netmon'@'localhost' IDENTIFIED BY 'netmon123';
GRANT SELECT, INSERT, UPDATE, DELETE ON network_monitoring.* TO 'netmon'@'localhost';
FLUSH PRIVILEGES;