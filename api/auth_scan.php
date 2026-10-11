<?php
require_once __DIR__ . '/config/db.php';
header('Content-Type: application/json');

$data = readJsonBody();
$nfc_token = trim($data['nfc_token'] ?? '');
$pin = trim($data['pin'] ?? '');

if ($nfc_token === '' || $pin === '') {
    jsonFail(400, "Jeton NFC et code PIN obligatoires.");
}

$db = getDBConnection();
$stmt = $db->prepare("SELECT id, uuid, pin_hash FROM patients WHERE nfc_token = ?");
$stmt->execute([$nfc_token]);
$patient = $stmt->fetch();

if (!$patient || !password_verify($pin, $patient['pin_hash'])) {
    if ($patient) {
        auditLog($db, (int)$patient['id'], 'FAILED_PIN_ATTEMPT');
    }
    http_response_code(401);
    echo json_encode(["status" => "UNAUTHORIZED", "message" => "Code PIN invalide ou carte non reconnue."]);
    exit;
}

// Issue a session token bound to this patient
$session = bin2hex(random_bytes(16));
$db->prepare("DELETE FROM sessions WHERE expires_at <= ?")->execute([time()]);
$db->prepare("INSERT INTO sessions (token, patient_id, expires_at) VALUES (?, ?, ?)")
   ->execute([$session, $patient['id'], time() + SESSION_TTL_SECONDS]);

// Non-repudiation audit entry
auditLog($db, (int)$patient['id'], 'AUTHENTICATED_SCAN');

echo json_encode([
    "status" => "SUCCESS",
    "session_token" => $session,
    "patient_uuid" => $patient['uuid']
]);
