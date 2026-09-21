-- Meet Lab.cafe – schéma databázy (MySQL 8 / MariaDB 10.6+)
SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(190) NOT NULL UNIQUE,
  name          VARCHAR(120) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('admin','member') NOT NULL DEFAULT 'member',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS folders (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(120) NOT NULL,
  color      VARCHAR(7) NOT NULL DEFAULT '#6366f1',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tags (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(80) NOT NULL UNIQUE,
  color      VARCHAR(7) NOT NULL DEFAULT '#0ea5e9',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Databáza účastníkov (ľudia, ktorí sa zúčastňujú porád)
CREATE TABLE IF NOT EXISTS participants (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(120) NOT NULL,
  email       VARCHAR(190) NULL,
  organisation VARCHAR(120) NULL,
  position    VARCHAR(120) NULL,
  aliases     VARCHAR(255) NULL COMMENT 'ďalšie mená/prezývky oddelené čiarkou (pomáha pri rozpoznávaní)',
  notes       TEXT NULL,
  color       VARCHAR(7) NOT NULL DEFAULT '#f59e0b',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_participants_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meetings (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  created_by      INT UNSIGNED NULL,
  folder_id       INT UNSIGNED NULL,
  title           VARCHAR(200) NOT NULL,
  meeting_date    DATETIME NOT NULL,
  location        VARCHAR(160) NULL,
  language        VARCHAR(8) NULL,
  source          ENUM('record','upload') NOT NULL DEFAULT 'upload',
  status          ENUM('queued','transcribing','transcribed','analyzing','done','error') NOT NULL DEFAULT 'queued',
  error_message   TEXT NULL,
  audio_path      VARCHAR(255) NULL,
  audio_mime      VARCHAR(80) NULL,
  audio_size      BIGINT UNSIGNED NULL,
  audio_duration  DECIMAL(9,2) NULL,
  stt_provider    VARCHAR(40) NULL,
  stt_job_id      VARCHAR(120) NULL,
  transcript_text LONGTEXT NULL,
  summary         TEXT NULL,
  analysis_json   JSON NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_meetings_date (meeting_date),
  INDEX idx_meetings_status (status),
  INDEX idx_meetings_folder (folder_id),
  FULLTEXT KEY ft_meetings (title, transcript_text, summary),
  CONSTRAINT fk_meetings_folder FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE SET NULL,
  CONSTRAINT fk_meetings_user   FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meeting_tags (
  meeting_id INT UNSIGNED NOT NULL,
  tag_id     INT UNSIGNED NOT NULL,
  PRIMARY KEY (meeting_id, tag_id),
  CONSTRAINT fk_mt_meeting FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE,
  CONSTRAINT fk_mt_tag     FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rečníci rozpoznaní diarizáciou v konkrétnej porade a ich priradenie k účastníkom
CREATE TABLE IF NOT EXISTS meeting_speakers (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  meeting_id     INT UNSIGNED NOT NULL,
  speaker_label  VARCHAR(40) NOT NULL COMMENT 'label z diarizácie, napr. speaker_0',
  participant_id INT UNSIGNED NULL,
  suggested_name VARCHAR(120) NULL COMMENT 'meno navrhnuté AI z kontextu rozhovoru',
  talk_seconds   DECIMAL(9,2) NOT NULL DEFAULT 0,
  word_count     INT UNSIGNED NOT NULL DEFAULT 0,
  confirmed      TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_meeting_speaker (meeting_id, speaker_label),
  CONSTRAINT fk_ms_meeting     FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE,
  CONSTRAINT fk_ms_participant FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Účastníci pozvaní/prítomní na porade (aj bez toho, aby hovorili)
CREATE TABLE IF NOT EXISTS meeting_participants (
  meeting_id     INT UNSIGNED NOT NULL,
  participant_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (meeting_id, participant_id),
  CONSTRAINT fk_mp_meeting     FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE,
  CONSTRAINT fk_mp_participant FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transcript_segments (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  meeting_id    INT UNSIGNED NOT NULL,
  position      INT UNSIGNED NOT NULL,
  speaker_label VARCHAR(40) NULL,
  start_sec     DECIMAL(9,2) NOT NULL DEFAULT 0,
  end_sec       DECIMAL(9,2) NOT NULL DEFAULT 0,
  text          TEXT NOT NULL,
  INDEX idx_segments_meeting (meeting_id, position),
  CONSTRAINT fk_seg_meeting FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Štruktúrovaný zápis: témy/bloky porady
CREATE TABLE IF NOT EXISTS meeting_topics (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  meeting_id INT UNSIGNED NOT NULL,
  position   INT UNSIGNED NOT NULL,
  title      VARCHAR(200) NOT NULL,
  summary    TEXT NOT NULL,
  start_sec  DECIMAL(9,2) NULL,
  CONSTRAINT fk_topic_meeting FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS key_points (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  meeting_id INT UNSIGNED NOT NULL,
  position   INT UNSIGNED NOT NULL,
  kind       ENUM('key_point','decision','open_question') NOT NULL DEFAULT 'key_point',
  text       TEXT NOT NULL,
  CONSTRAINT fk_kp_meeting FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS action_items (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  meeting_id     INT UNSIGNED NOT NULL,
  participant_id INT UNSIGNED NULL,
  assignee_name  VARCHAR(120) NULL COMMENT 'meno tak, ako zaznelo, ak nie je priradený účastník',
  description    TEXT NOT NULL,
  due_date       DATE NULL,
  priority       ENUM('low','normal','high') NOT NULL DEFAULT 'normal',
  status         ENUM('open','done') NOT NULL DEFAULT 'open',
  source_quote   TEXT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  done_at        DATETIME NULL,
  INDEX idx_ai_meeting (meeting_id),
  INDEX idx_ai_participant (participant_id, status),
  CONSTRAINT fk_ai_meeting     FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE,
  CONSTRAINT fk_ai_participant FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fronta úloh na pozadí (prepis, analýza)
CREATE TABLE IF NOT EXISTS jobs (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  meeting_id  INT UNSIGNED NOT NULL,
  type        ENUM('transcribe','analyze') NOT NULL,
  status      ENUM('pending','running','done','failed') NOT NULL DEFAULT 'pending',
  attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error  TEXT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at  DATETIME NULL,
  finished_at DATETIME NULL,
  INDEX idx_jobs_status (status, id),
  CONSTRAINT fk_jobs_meeting FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
