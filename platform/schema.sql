-- Fronote PLATFORM — schéma (MariaDB). Idempotent : appliqué par bin/migrate.php.
CREATE TABLE IF NOT EXISTS establishments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  uai VARCHAR(16) NULL,
  city VARCHAR(120) NULL,
  kind VARCHAR(40) NOT NULL DEFAULT 'college',
  contact_name VARCHAR(160) NULL,
  contact_email VARCHAR(190) NULL,
  status ENUM('autorise','installe','suspendu','archive') NOT NULL DEFAULT 'autorise',
  license_id INT NULL,
  license_prefix CHAR(6) NULL,
  tier VARCHAR(32) NOT NULL DEFAULT 'base',
  modules_json TEXT NULL,
  max_activations INT NOT NULL DEFAULT 1,
  expires_at DATE NULL,
  notes TEXT NULL,
  created_by VARCHAR(120) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_license (license_id),
  KEY k_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Identité d'une instance qui parle à la plateforme : par jeton d'installation (activation sur
-- dist) ou par clé d'API émise ici (instances hors distribution, ex. instance de référence).
CREATE TABLE IF NOT EXISTS instance_links (
  id INT AUTO_INCREMENT PRIMARY KEY,
  establishment_id INT NULL,
  activation_id INT NULL,
  license_id INT NULL,
  token_hash CHAR(64) NULL,
  api_key_hash CHAR(64) NULL,
  label VARCHAR(190) NULL,
  hostname VARCHAR(190) NULL,
  app_url VARCHAR(255) NULL,
  app_version VARCHAR(40) NULL,
  verified_at DATETIME NULL,
  last_seen DATETIME NULL,
  revoked TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_token (token_hash),
  UNIQUE KEY uk_apikey (api_key_hash),
  KEY k_etab (establishment_id),
  CONSTRAINT fk_link_etab FOREIGN KEY (establishment_id) REFERENCES establishments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Demandes ESCALADÉES par l'équipe d'un établissement (les tickets internes restent dans l'instance).
CREATE TABLE IF NOT EXISTS tickets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  link_id INT NOT NULL,
  establishment_id INT NULL,
  instance_ref VARCHAR(64) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  category VARCHAR(40) NOT NULL DEFAULT 'technique',
  priority ENUM('basse','normale','haute','urgente') NOT NULL DEFAULT 'normale',
  status ENUM('nouveau','en_cours','attente_etablissement','resolu','ferme') NOT NULL DEFAULT 'nouveau',
  sent_by VARCHAR(160) NULL,
  context_json TEXT NULL,
  assigned_to VARCHAR(120) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ref (link_id, instance_ref),
  KEY k_status (status),
  CONSTRAINT fk_ticket_link FOREIGN KEY (link_id) REFERENCES instance_links(id),
  CONSTRAINT fk_ticket_etab FOREIGN KEY (establishment_id) REFERENCES establishments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT NOT NULL,
  side ENUM('etablissement','plateforme') NOT NULL,
  author VARCHAR(160) NOT NULL,
  body TEXT NOT NULL,
  internal TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_ticket (ticket_id),
  CONSTRAINT fk_msg_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  actor VARCHAR(120) NOT NULL,
  action VARCHAR(80) NOT NULL,
  target VARCHAR(190) NULL,
  detail TEXT NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
