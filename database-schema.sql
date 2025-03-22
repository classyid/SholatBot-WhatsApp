-- Tabel untuk menyimpan preferensi pengguna
CREATE TABLE user_preferences (
    id INT AUTO_INCREMENT PRIMARY KEY,
    phone VARCHAR(20) NOT NULL UNIQUE,
    default_city VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    
    -- Index untuk mempercepat pencarian
    INDEX (phone)
);
