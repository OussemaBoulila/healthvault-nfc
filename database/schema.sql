-- HealthVault NFC SQLite Database Schema
-- Implements Privacy by Design, pseudonymized identity, and audit trail

CREATE TABLE IF NOT EXISTS patients (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid VARCHAR(64) UNIQUE NOT NULL,
    nfc_token VARCHAR(64) UNIQUE NOT NULL,
    pin_hash VARCHAR(255) NOT NULL,
    full_name_pseudonymized VARCHAR(100) NOT NULL,
    birth_year INT NOT NULL,
    gender VARCHAR(10) NOT NULL,
    blood_group VARCHAR(5) NOT NULL,
    cin_hashed VARCHAR(64) UNIQUE NOT NULL,
    phone_contact VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS chronic_conditions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    patient_id INT NOT NULL,
    condition_name VARCHAR(100) NOT NULL,
    icd10_code VARCHAR(10) NOT NULL,
    diagnosed_date DATE NOT NULL,
    status VARCHAR(20) DEFAULT 'ACTIVE',
    certified_by VARCHAR(100) NOT NULL,
    is_verified BOOLEAN DEFAULT 1,
    FOREIGN KEY (patient_id) REFERENCES patients(id)
);

CREATE TABLE IF NOT EXISTS allergies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    patient_id INT NOT NULL,
    substance VARCHAR(100) NOT NULL,
    allergy_class VARCHAR(50) NOT NULL,
    severity VARCHAR(20) NOT NULL,
    certified_by VARCHAR(100) NOT NULL,
    FOREIGN KEY (patient_id) REFERENCES patients(id)
);

CREATE TABLE IF NOT EXISTS active_prescriptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    patient_id INT NOT NULL,
    drug_name VARCHAR(100) NOT NULL,
    dci_molecule VARCHAR(100) NOT NULL,
    dosage VARCHAR(50) NOT NULL,
    frequency VARCHAR(50) NOT NULL,
    prescribed_by VARCHAR(100) NOT NULL,
    doctor_license VARCHAR(50) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_active BOOLEAN DEFAULT 1,
    FOREIGN KEY (patient_id) REFERENCES patients(id)
);

CREATE TABLE IF NOT EXISTS audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    patient_id INT NOT NULL,
    accessed_by VARCHAR(100) NOT NULL,
    access_type VARCHAR(50) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id)
);

-- Synthetic Dataset for HackCure 2026 Evaluation
-- Patient: 62-year-old chronic ALD patient (Type 2 Diabetes + Hypertension)
-- NOTE: pin_hash is a placeholder; db.php replaces it with a real hash of PIN '1234' on first run.
INSERT INTO patients (id, uuid, nfc_token, pin_hash, full_name_pseudonymized, birth_year, gender, blood_group, cin_hashed, phone_contact)
VALUES (
    1,
    'p-uuid-9842a',
    'token-demo-1234',
    'PLACEHOLDER',
    'M. B. Salem',
    1964,
    'M',
    'O+',
    'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
    '+216 98 123 456'
);

INSERT INTO chronic_conditions (patient_id, condition_name, icd10_code, diagnosed_date, certified_by, is_verified)
VALUES
(1, 'Diabète de Type 2', 'E11', '2021-03-15', 'Dr. Karray (Endocrinologue)', 1),
(1, 'Hypertension Artérielle', 'I10', '2019-11-20', 'Dr. Ben Ammar (Cardiologue)', 1);

INSERT INTO allergies (patient_id, substance, allergy_class, severity, certified_by)
VALUES
(1, 'Pénicilline G', 'Beta-lactam', 'CRITICAL', 'Dr. Ben Ammar (Cardiologue)');

INSERT INTO active_prescriptions (patient_id, drug_name, dci_molecule, dosage, frequency, prescribed_by, doctor_license, start_date, end_date, is_active)
VALUES
(1, 'Glucophage', 'Metformine', '850mg', '2 comprimés/jour', 'Dr. Karray (Endocrinologue)', 'CNOM-14820', '2026-09-01', '2026-12-01', 1),
(1, 'Aprovel', 'Irbésartan', '150mg', '1 comprimé/jour', 'Dr. Ben Ammar (Cardiologue)', 'CNOM-09312', '2026-08-15', '2026-11-15', 1);
