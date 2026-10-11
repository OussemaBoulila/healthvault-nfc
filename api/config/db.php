<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { exit(0); }

const DEMO_PIN = '1234';
const SESSION_TTL_SECONDS = 1800; // 30 minutes

function getDBConnection(): PDO {
    $dbDir = __DIR__ . '/../../database';
    $dbPath = $dbDir . '/healthvault.sqlite';

    if (!is_dir($dbDir)) {
        mkdir($dbDir, 0777, true);
    }

    $isNew = !file_exists($dbPath);
    try {
        $pdo = new PDO("sqlite:" . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec("PRAGMA foreign_keys = ON");

        if ($isNew) {
            $sql = file_get_contents($dbDir . '/schema.sql');
            $pdo->exec($sql);
            // Replace the placeholder with a real bcrypt hash of the demo PIN.
            $upd = $pdo->prepare("UPDATE patients SET pin_hash = ? WHERE pin_hash = 'PLACEHOLDER'");
            $upd->execute([password_hash(DEMO_PIN, PASSWORD_DEFAULT)]);
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS sessions (
            token VARCHAR(64) PRIMARY KEY,
            patient_id INT NOT NULL,
            expires_at INTEGER NOT NULL
        )");

        // Reception queue (filled by the intake kiosk)
        $pdo->exec("CREATE TABLE IF NOT EXISTS queue_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            patient_id INT NOT NULL,
            box VARCHAR(50) NOT NULL,
            status VARCHAR(20) DEFAULT 'WAITING',
            admitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (patient_id) REFERENCES patients(id)
        )");

        // Non-repudiation: the audit trail is append-only (no UPDATE / DELETE)
        $pdo->exec("CREATE TRIGGER IF NOT EXISTS audit_logs_no_update BEFORE UPDATE ON audit_logs
            BEGIN SELECT RAISE(ABORT, 'audit_logs is append-only'); END");
        $pdo->exec("CREATE TRIGGER IF NOT EXISTS audit_logs_no_delete BEFORE DELETE ON audit_logs
            BEGIN SELECT RAISE(ABORT, 'audit_logs is append-only'); END");

        return $pdo;
    } catch (PDOException $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(["status" => "FATAL", "error" => "Erreur de base de données."]);
        exit;
    }
}

function readJsonBody(): array {
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function jsonFail(int $code, string $message): void {
    http_response_code($code);
    echo json_encode(["status" => "ERROR", "message" => $message, "error" => $message]);
    exit;
}

/**
 * Validates the Bearer session token issued by auth_scan.php.
 * Returns the patient_id the session is bound to.
 */
function requireSession(PDO $db): int {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strtolower($k) === 'authorization') { $header = $v; }
        }
    }
    if (!preg_match('/^Bearer\s+([a-f0-9]{32})$/i', $header, $m)) {
        jsonFail(401, "Session absente. Veuillez scanner la carte et saisir le PIN.");
    }
    $stmt = $db->prepare("SELECT patient_id FROM sessions WHERE token = ? AND expires_at > ?");
    $stmt->execute([strtolower($m[1]), time()]);
    $row = $stmt->fetch();
    if (!$row) {
        jsonFail(401, "Session expirée ou invalide.");
    }
    return (int)$row['patient_id'];
}

const DEFAULT_PRACTITIONER = 'Dr. Yassine Rached (CNOM-14820)';

function auditLog(PDO $db, int $patientId, string $type, string $by = DEFAULT_PRACTITIONER): void {
    $log = $db->prepare("INSERT INTO audit_logs (patient_id, accessed_by, access_type, ip_address) VALUES (?, ?, ?, ?)");
    $log->execute([$patientId, $by, $type, $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']);
}
