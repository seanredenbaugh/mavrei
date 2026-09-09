SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE properties (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    legacy_id INT UNSIGNED NULL UNIQUE,
    slug VARCHAR(160) NOT NULL UNIQUE,
    title VARCHAR(180) NOT NULL,
    property_type ENUM('house', 'apartment', 'airbnb') NOT NULL DEFAULT 'house',
    status ENUM('rented', 'available', 'coming_soon', 'sold') NOT NULL DEFAULT 'rented',
    address_line1 VARCHAR(180) NOT NULL,
    address_line2 VARCHAR(180) NULL,
    city VARCHAR(100) NOT NULL,
    state CHAR(2) NOT NULL,
    postal_code VARCHAR(12) NOT NULL,
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(10,7) NOT NULL,
    bedrooms DECIMAL(3,1) NULL,
    bathrooms DECIMAL(3,1) NULL,
    square_feet INT UNSIGNED NULL,
    units SMALLINT UNSIGNED NULL,
    year_built SMALLINT UNSIGNED NULL,
    purchase_price DECIMAL(12,2) NULL,
    purchase_date DATE NULL,
    zillow_value DECIMAL(12,2) NULL,
    zillow_checked_at DATE NULL,
    rent_amount DECIMAL(10,2) NULL,
    nightly_rate DECIMAL(10,2) NULL,
    sale_price DECIMAL(12,2) NULL,
    available_date DATE NULL,
    description TEXT NULL,
    private_notes TEXT NULL,
    featured_photo VARCHAR(255) NULL,
    is_visible TINYINT(1) NOT NULL DEFAULT 1,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_map (is_visible, property_type, status),
    INDEX idx_location (latitude, longitude)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE property_photos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    property_id INT UNSIGNED NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    caption VARCHAR(255) NULL,
    alt_text VARCHAR(255) NULL,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_photo_property FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    INDEX idx_photo_order (property_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE property_value_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    property_id INT UNSIGNED NOT NULL,
    value_amount DECIMAL(12,2) NOT NULL,
    value_source ENUM('zillow', 'appraisal', 'estimate', 'sale') NOT NULL DEFAULT 'zillow',
    valued_on DATE NOT NULL,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_value_property FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    INDEX idx_value_date (property_id, valued_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    last_login_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

