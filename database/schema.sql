SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE properties (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    legacy_id INT UNSIGNED NULL UNIQUE,
    slug VARCHAR(160) NOT NULL UNIQUE,
    title VARCHAR(180) NOT NULL,
    property_type VARCHAR(30) NOT NULL DEFAULT 'house',
    status VARCHAR(30) NOT NULL DEFAULT 'rented',
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

CREATE TABLE site_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admin_login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_hash CHAR(64) NOT NULL,
    email_hash CHAR(64) NOT NULL,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_ip_time (ip_hash, attempted_at),
    INDEX idx_login_email_time (email_hash, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE airbnb_rehab_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    property_id INT UNSIGNED NOT NULL,
    area VARCHAR(80) NOT NULL,
    item_name VARCHAR(190) NOT NULL,
    estimated_cost DECIMAL(10,2) NULL,
    actual_cost DECIMAL(10,2) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'planned',
    vendor VARCHAR(120) NULL,
    purchased_on DATE NULL,
    notes TEXT NULL,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_airbnb_rehab_property FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    INDEX idx_airbnb_rehab (property_id, area, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE airbnb_recurring_costs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    property_id INT UNSIGNED NOT NULL,
    category VARCHAR(80) NOT NULL,
    name VARCHAR(160) NOT NULL,
    typical_amount DECIMAL(10,2) NULL,
    frequency VARCHAR(20) NOT NULL DEFAULT 'monthly',
    active TINYINT(1) NOT NULL DEFAULT 1,
    notes TEXT NULL,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_airbnb_recurring_property FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    INDEX idx_airbnb_recurring (property_id, active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE airbnb_monthly_expenses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    property_id INT UNSIGNED NOT NULL,
    recurring_cost_id BIGINT UNSIGNED NULL,
    expense_month DATE NOT NULL,
    category VARCHAR(80) NOT NULL,
    description VARCHAR(190) NOT NULL,
    amount DECIMAL(10,2) NULL,
    paid_on DATE NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_airbnb_expense_property FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    CONSTRAINT fk_airbnb_expense_recurring FOREIGN KEY (recurring_cost_id) REFERENCES airbnb_recurring_costs(id) ON DELETE SET NULL,
    UNIQUE KEY uq_airbnb_recurring_month (property_id, recurring_cost_id, expense_month),
    INDEX idx_airbnb_expense_month (property_id, expense_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE airbnb_reference_notes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    property_id INT UNSIGNED NOT NULL,
    category VARCHAR(80) NOT NULL DEFAULT 'Measurements',
    label VARCHAR(160) NOT NULL,
    note_value VARCHAR(255) NOT NULL,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_airbnb_note_property FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    INDEX idx_airbnb_note (property_id, category, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
