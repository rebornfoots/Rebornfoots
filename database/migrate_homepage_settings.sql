-- Run once to make homepage offer and hero content editable in Admin > Promotions.
CREATE TABLE IF NOT EXISTS site_settings (
    setting_key VARCHAR(64) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES
('offer_enabled', '0'),
('offer_text', ''),
('hero_eyebrow', 'Traditional goodness, delivered'),
('hero_title', 'Food that still tastes like home.'),
('hero_text', 'Small-batch butter, aromatic ghee and natural palm jaggery, prepared with time-honoured methods by people who care.'),
('hero_image_url', ''),
('hero_cta_label', 'Shop the harvest'),
('hero_cta_url', '#products');
