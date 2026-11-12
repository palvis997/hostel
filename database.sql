-- Smart Campus Accommodation and Facility Management System
-- SPI 2301 | Kirinyaga University | Import this file with phpMyAdmin.
-- It drops and recreates every table, so importing it again resets the sample data.
CREATE DATABASE IF NOT EXISTS smart_campus CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE smart_campus;

DROP TABLE IF EXISTS maintenance_tickets;
DROP TABLE IF EXISTS applications;
DROP TABLE IF EXISTS bookings;
DROP TABLE IF EXISTS rooms;
DROP TABLE IF EXISTS hostels;
DROP TABLE IF EXISTS users;

CREATE TABLE users (
  user_id INT AUTO_INCREMENT PRIMARY KEY,
  reg_no VARCHAR(20) NOT NULL UNIQUE,
  full_name VARCHAR(100) NOT NULL,
  email VARCHAR(120) NOT NULL UNIQUE,
  phone CHAR(10) NOT NULL,
  gender ENUM('Male','Female') NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('student','admin') NOT NULL DEFAULT 'student',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE hostels (
  hostel_id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE,
  gender ENUM('Male','Female','Mixed') NOT NULL,
  location VARCHAR(120) NOT NULL,
  fee_per_semester DECIMAL(10,2) NOT NULL CHECK (fee_per_semester >= 0),
  image VARCHAR(120) NULL
) ENGINE=InnoDB;

CREATE TABLE rooms (
  room_id INT AUTO_INCREMENT PRIMARY KEY,
  hostel_id INT NOT NULL,
  room_number VARCHAR(10) NOT NULL,
  capacity TINYINT UNSIGNED NOT NULL CHECK (capacity BETWEEN 1 AND 8),
  status ENUM('Available','Maintenance') NOT NULL DEFAULT 'Available',
  amenities VARCHAR(255) NOT NULL DEFAULT '',
  UNIQUE KEY uq_room (hostel_id, room_number),
  CONSTRAINT fk_room_hostel FOREIGN KEY (hostel_id) REFERENCES hostels(hostel_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Room applications: one row per student application for a bed (formerly the bookings table).
CREATE TABLE applications (
  application_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  room_id INT NOT NULL,
  semester VARCHAR(30) NOT NULL,
  status ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  amount_due DECIMAL(10,2) NOT NULL,
  amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0,
  payment_method ENUM('M-Pesa Express / Paybill','Bank Transfer','KCB Agent Deposit') NULL,
  payment_reference VARCHAR(20) NULL,
  payment_status ENUM('Unverified','Verified','Rejected') NOT NULL DEFAULT 'Unverified',
  rejection_reason VARCHAR(255) NULL,
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_at TIMESTAMP NULL DEFAULT NULL,
  KEY idx_applications_room_status (room_id, status),
  CONSTRAINT fk_applications_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  CONSTRAINT fk_applications_room FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE maintenance_tickets (
  ticket_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  room_id INT NULL,
  category ENUM('Plumbing','Electrical','Furniture') NOT NULL,
  description VARCHAR(500) NOT NULL,
  status ENUM('Pending','In Progress','Resolved') NOT NULL DEFAULT 'Pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_tickets_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  CONSTRAINT fk_tickets_room FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- SEED DATA
-- Warden: warden@kyu.ac.ke / Admin@123. Every student account: Student@123.
INSERT INTO users (user_id, reg_no, full_name, email, phone, gender, password_hash, role) VALUES
(1,'WARDEN001','Hostel Warden','warden@kyu.ac.ke','0700000001','Male','$2y$12$QIIfyCcJF9AQsm.ayJdBduUtqp1kF9P3StAbWPrHke23BRAoKWYGy','admin'),
(2,'CT100/G/10001/24','Peter Kamau','peter@student.kyu.ac.ke','0711111111','Male','$2y$12$LvZWFoFhghVnoEeFZ3g4WezeBHkl1rl442KGRsooeh8eWPV/659tG','student'),
(3,'CT100/G/10002/24','John Mwangi','john@student.kyu.ac.ke','0722222222','Male','$2y$12$LvZWFoFhghVnoEeFZ3g4WezeBHkl1rl442KGRsooeh8eWPV/659tG','student'),
(4,'CT100/G/10003/24','Mary Wanjiku','mary@student.kyu.ac.ke','0733333333','Female','$2y$12$LvZWFoFhghVnoEeFZ3g4WezeBHkl1rl442KGRsooeh8eWPV/659tG','student'),
(5,'CT100/G/10004/24','Grace Achieng','grace@student.kyu.ac.ke','0744444444','Female','$2y$12$LvZWFoFhghVnoEeFZ3g4WezeBHkl1rl442KGRsooeh8eWPV/659tG','student'),
(6,'CT100/G/10005/24','David Otieno','david@student.kyu.ac.ke','0755555555','Male','$2y$12$LvZWFoFhghVnoEeFZ3g4WezeBHkl1rl442KGRsooeh8eWPV/659tG','student'),
(7,'CT100/G/10006/24','Ruth Wanjiru','ruth@student.kyu.ac.ke','0766666666','Female','$2y$12$LvZWFoFhghVnoEeFZ3g4WezeBHkl1rl442KGRsooeh8eWPV/659tG','student');

INSERT INTO hostels (hostel_id, name, gender, location, fee_per_semester, image) VALUES
(1,'Mt Kenya Block A','Male','Main Campus, North Wing',18000.00,'hostel_block_a.jpg'),
(2,'Kirinyaga Block B','Female','Main Campus, East Wing',16000.00,'hostel_block_b.jpg'),
(3,'Baringo Block C','Mixed','Annex, Near Library',20000.00,'hostel_block_c.jpg');

INSERT INTO rooms (room_id, hostel_id, room_number, capacity, status, amenities) VALUES
(1,1,'A101',4,'Available','Wi-Fi,Study Desk,Hot Shower'),
(2,1,'A102',4,'Available','Wi-Fi,Study Desk,Hot Shower'),
(3,1,'A103',2,'Available','Wi-Fi,Study Desk,Hot Shower,Wardrobe'),
(4,1,'A201',4,'Maintenance','Wi-Fi,Study Desk'),
(5,2,'B101',4,'Available','Wi-Fi,Study Desk,Hot Shower'),
(6,2,'B102',2,'Available','Wi-Fi,Study Desk,Hot Shower,Wardrobe'),
(7,2,'B103',4,'Available','Wi-Fi,Study Desk'),
(8,3,'C101',2,'Available','Wi-Fi,Study Desk,Hot Shower,Wardrobe'),
(9,3,'C102',2,'Available','Wi-Fi,Study Desk,Hot Shower,Wardrobe'),
(10,3,'C103',4,'Available','Wi-Fi,Study Desk');

-- Two approved and paid-in-part bookings, a verified deposit, two pending applications
-- awaiting verification, and one application rejected for an invalid transaction code.
INSERT INTO applications (user_id, room_id, semester, status, amount_due, amount_paid, payment_method, payment_reference, payment_status, rejection_reason, decided_at) VALUES
(2,1,'Semester 1 2026/2027','Approved',18000.00,18000.00,'M-Pesa Express / Paybill','QHK78923KL','Verified',NULL,NOW()),
(3,1,'Semester 1 2026/2027','Approved',18000.00,9000.00,'Bank Transfer','FT26113XB4','Verified',NULL,NOW()),
(4,5,'Semester 1 2026/2027','Approved',16000.00,0.00,'KCB Agent Deposit','KCB4482917','Verified',NULL,NOW()),
(5,6,'Semester 1 2026/2027','Pending',16000.00,0.00,'M-Pesa Express / Paybill','SBK3J7L2M9','Unverified',NULL,NULL),
(6,2,'Semester 1 2026/2027','Pending',18000.00,0.00,'Bank Transfer','FT26120RT8','Unverified',NULL,NULL),
(7,6,'Semester 1 2026/2027','Rejected',16000.00,0.00,'M-Pesa Express / Paybill','QHK0012AB9','Rejected','Invalid M-Pesa Transaction Code',NOW());

INSERT INTO maintenance_tickets (user_id, room_id, category, description, status) VALUES
(2,1,'Plumbing','The bathroom tap in the room is leaking continuously.','Pending'),
(2,1,'Electrical','Ceiling light bulb holder is faulty and flickers.','In Progress'),
(3,1,'Furniture','One bed frame has a broken support slat.','Resolved'),
(4,5,'Plumbing','Blocked sink in the shared washroom.','Resolved'),
(4,5,'Electrical','Socket near the study desk has no power.','Pending'),
(4,5,'Furniture','Wardrobe door hinge has come loose.','In Progress');
