-- =====================================================================
-- UniGo: Integrated Smart Transport System
-- Database Schema (MySQL 8+ / MariaDB compatible)
-- =====================================================================

CREATE DATABASE IF NOT EXISTS unigo_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE unigo_db;

-- ---------------------------------------------------------------------
-- 1. USERS  (passengers, drivers, operator owners, admins)
-- ---------------------------------------------------------------------
CREATE TABLE users (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    full_name       VARCHAR(120)        NOT NULL,
    email           VARCHAR(150)        NOT NULL UNIQUE,
    phone           VARCHAR(20)         NOT NULL UNIQUE,
    password_hash   VARCHAR(255)        NOT NULL,
    role            ENUM('passenger','driver','operator','admin') NOT NULL DEFAULT 'passenger',
    country         VARCHAR(60)         DEFAULT 'Uganda',
    district        VARCHAR(60)         DEFAULT NULL,
    trusted_contact_phone VARCHAR(20)   DEFAULT NULL,
    status          ENUM('active','suspended') NOT NULL DEFAULT 'active',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 2. OPERATORS (transport companies / fleet owners) - Section 10 billing
-- ---------------------------------------------------------------------
CREATE TABLE operators (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    owner_user_id       INT NOT NULL,
    company_name        VARCHAR(150) NOT NULL,
    country             VARCHAR(60)  NOT NULL DEFAULT 'Uganda',
    district            VARCHAR(60)  DEFAULT NULL,
    economy_index       DECIMAL(4,2) NOT NULL DEFAULT 1.00, -- country/district adjustment factor
    status              ENUM('active','pending','suspended') NOT NULL DEFAULT 'pending',
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3. VEHICLES
-- ---------------------------------------------------------------------
CREATE TABLE vehicles (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    operator_id     INT NOT NULL,
    plate_number    VARCHAR(20) NOT NULL UNIQUE,
    vehicle_type    ENUM('boda','taxi','bus','electric_bus','truck') NOT NULL,
    capacity        INT NOT NULL DEFAULT 1,
    status          ENUM('active','maintenance','offline') NOT NULL DEFAULT 'active',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (operator_id) REFERENCES operators(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 4. DRIVERS (linked user <-> vehicle)
-- ---------------------------------------------------------------------
CREATE TABLE drivers (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    vehicle_id      INT DEFAULT NULL,
    license_number  VARCHAR(40) NOT NULL,
    rating          DECIMAL(2,1) NOT NULL DEFAULT 5.0,
    status          ENUM('available','on_trip','offline') NOT NULL DEFAULT 'offline',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 5. ROUTES + STOPS  (city -> district -> country -> continent scaling
--    is handled by the country/district columns, not new tables)
-- ---------------------------------------------------------------------
CREATE TABLE routes (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    route_name      VARCHAR(150) NOT NULL,
    origin          VARCHAR(120) NOT NULL,
    destination     VARCHAR(120) NOT NULL,
    distance_km     DECIMAL(6,2) NOT NULL,
    country         VARCHAR(60)  NOT NULL DEFAULT 'Uganda',
    district        VARCHAR(60)  DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE route_stops (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    route_id        INT NOT NULL,
    stop_name       VARCHAR(120) NOT NULL,
    latitude        DECIMAL(10,7) NOT NULL,
    longitude       DECIMAL(10,7) NOT NULL,
    sequence_no     INT NOT NULL,
    FOREIGN KEY (route_id) REFERENCES routes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 6. FARES  (per route + vehicle type)
-- ---------------------------------------------------------------------
CREATE TABLE fares (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    route_id        INT NOT NULL,
    vehicle_type    ENUM('boda','taxi','bus','electric_bus','truck') NOT NULL,
    base_fare       DECIMAL(10,2) NOT NULL,
    per_km_rate     DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (route_id) REFERENCES routes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 7. TRIPS (a scheduled/live run of a vehicle on a route)
-- ---------------------------------------------------------------------
CREATE TABLE trips (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id      INT NOT NULL,
    route_id        INT NOT NULL,
    driver_id       INT DEFAULT NULL,
    departure_time  DATETIME NOT NULL,
    status          ENUM('scheduled','boarding','in_progress','completed','cancelled') NOT NULL DEFAULT 'scheduled',
    current_lat     DECIMAL(10,7) DEFAULT NULL,
    current_lng     DECIMAL(10,7) DEFAULT NULL,
    seats_available INT NOT NULL,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    FOREIGN KEY (route_id)   REFERENCES routes(id)   ON DELETE CASCADE,
    FOREIGN KEY (driver_id)  REFERENCES drivers(id)  ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 8. GPS PINGS  (raw tracking history, feeds the AI/analytics module)
-- ---------------------------------------------------------------------
CREATE TABLE gps_pings (
    id              BIGINT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id      INT NOT NULL,
    trip_id         INT DEFAULT NULL,
    latitude        DECIMAL(10,7) NOT NULL,
    longitude       DECIMAL(10,7) NOT NULL,
    speed_kmh       DECIMAL(5,2)  DEFAULT NULL,
    recorded_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    FOREIGN KEY (trip_id)    REFERENCES trips(id)    ON DELETE SET NULL,
    INDEX idx_vehicle_time (vehicle_id, recorded_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 9. BOOKINGS
-- ---------------------------------------------------------------------
CREATE TABLE bookings (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    trip_id         INT NOT NULL,
    passenger_id    INT NOT NULL,
    seat_number     VARCHAR(10) DEFAULT NULL,
    fare_amount     DECIMAL(10,2) NOT NULL,
    booking_type    ENUM('solo','shared') NOT NULL DEFAULT 'solo',
    status          ENUM('pending','confirmed','completed','cancelled') NOT NULL DEFAULT 'pending',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
    FOREIGN KEY (passenger_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 10. PAYMENTS
-- ---------------------------------------------------------------------
CREATE TABLE payments (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    booking_id          INT NOT NULL,
    amount              DECIMAL(10,2) NOT NULL,
    method              ENUM('mobile_money','card','cash','wallet') NOT NULL DEFAULT 'mobile_money',
    status              ENUM('pending','success','failed','refunded') NOT NULL DEFAULT 'pending',
    transaction_ref     VARCHAR(60) DEFAULT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 11. SOS / SAFETY ALERTS
-- ---------------------------------------------------------------------
CREATE TABLE sos_alerts (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    trip_id         INT DEFAULT NULL,
    latitude        DECIMAL(10,7) NOT NULL,
    longitude       DECIMAL(10,7) NOT NULL,
    status          ENUM('open','acknowledged','resolved') NOT NULL DEFAULT 'open',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 12. SUBSCRIPTIONS / BILLING (operator pays UniGo - Section 10)
-- ---------------------------------------------------------------------
CREATE TABLE subscriptions (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    operator_id             INT NOT NULL,
    base_fee_monthly        DECIMAL(10,2) NOT NULL DEFAULT 20.00,
    per_vehicle_fee         DECIMAL(10,2) NOT NULL DEFAULT 3.00,
    distance_fee_per_km     DECIMAL(10,4) NOT NULL DEFAULT 0.01,
    transaction_fee_pct     DECIMAL(4,2)  NOT NULL DEFAULT 1.50,
    billing_period_start    DATE NOT NULL,
    billing_period_end      DATE NOT NULL,
    total_km_tracked        DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_vehicles          INT NOT NULL DEFAULT 0,
    amount_due              DECIMAL(12,2) NOT NULL DEFAULT 0,
    status                  ENUM('draft','invoiced','paid','overdue') NOT NULL DEFAULT 'draft',
    FOREIGN KEY (operator_id) REFERENCES operators(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 13. AI INSIGHTS (stores model output: congestion / demand predictions)
-- ---------------------------------------------------------------------
CREATE TABLE ai_insights (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    route_id        INT NOT NULL,
    insight_type    ENUM('congestion_forecast','demand_forecast','fraud_flag') NOT NULL,
    score           DECIMAL(5,2) NOT NULL,     -- 0-100 severity / likelihood
    summary         VARCHAR(255) NOT NULL,
    generated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (route_id) REFERENCES routes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================================
-- SEED DATA - enough to demo every module end-to-end
-- Passwords for all seeded accounts are: Password123
-- =====================================================================
INSERT INTO users (full_name, email, phone, password_hash, role, district) VALUES
('System Admin', 'admin@unigo.africa', '+256700000001', '$2y$10$XGHSgAb2Rl1B6Ro.6.BSOefnl7YN6BdrfpiBQiwtfeUuw2G104oMW', 'admin', 'Kampala'),
('Grace Nakato', 'grace@example.com', '+256700000002', '$2y$10$XGHSgAb2Rl1B6Ro.6.BSOefnl7YN6BdrfpiBQiwtfeUuw2G104oMW', 'passenger', 'Kampala'),
('Peter Okello', 'peter@example.com', '+256700000003', '$2y$10$XGHSgAb2Rl1B6Ro.6.BSOefnl7YN6BdrfpiBQiwtfeUuw2G104oMW', 'driver', 'Kampala'),
('Kayola Transit Ltd', 'ops@kayola.co.ug', '+256700000004', '$2y$10$XGHSgAb2Rl1B6Ro.6.BSOefnl7YN6BdrfpiBQiwtfeUuw2G104oMW', 'operator', 'Kampala');

INSERT INTO operators (owner_user_id, company_name, country, district, economy_index, status) VALUES
(4, 'Kayola Transit Ltd', 'Uganda', 'Kampala', 1.00, 'active');

INSERT INTO vehicles (operator_id, plate_number, vehicle_type, capacity, status) VALUES
(1, 'UAX 123K', 'electric_bus', 40, 'active'),
(1, 'UBH 456T', 'taxi', 14, 'active'),
(1, 'UBJ 789B', 'boda', 1, 'active');

INSERT INTO drivers (user_id, vehicle_id, license_number, rating, status) VALUES
(3, 1, 'DL-UG-88231', 4.8, 'available');

INSERT INTO routes (route_name, origin, destination, distance_km, country, district) VALUES
('Kampala City - Entebbe Road', 'Kampala City Centre', 'Entebbe', 37.00, 'Uganda', 'Kampala'),
('Kampala - Jinja Corridor', 'Kampala', 'Jinja', 80.00, 'Uganda', NULL);

INSERT INTO route_stops (route_id, stop_name, latitude, longitude, sequence_no) VALUES
(1, 'Kampala Old Taxi Park', 0.3136, 32.5811, 1),
(1, 'Zana Stage', 0.2295, 32.5460, 2),
(1, 'Entebbe Terminal', 0.0512, 32.4633, 3);

INSERT INTO fares (route_id, vehicle_type, base_fare, per_km_rate) VALUES
(1, 'electric_bus', 1500.00, 100.00),
(1, 'taxi', 1000.00, 150.00),
(1, 'boda', 2000.00, 300.00);

INSERT INTO trips (vehicle_id, route_id, driver_id, departure_time, status, current_lat, current_lng, seats_available) VALUES
(1, 1, 1, NOW(), 'in_progress', 0.2295, 32.5460, 25);

INSERT INTO subscriptions (operator_id, base_fee_monthly, per_vehicle_fee, distance_fee_per_km, transaction_fee_pct, billing_period_start, billing_period_end, total_km_tracked, total_vehicles, amount_due, status) VALUES
(1, 20.00, 3.00, 0.01, 1.50, DATE_FORMAT(NOW(),'%Y-%m-01'), LAST_DAY(NOW()), 0, 3, 29.00, 'draft');
