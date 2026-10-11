<?php
// Reception kiosk endpoint.
//  action=identify (default): NFC token only -> minimal identity (no medical history, no PIN needed)
//  action=admit             : assigns the patient to a consultation box / queue
require_once __DIR__ . '/config/db.php';
header('Content-Type: application/json');

const INTAKE_AGENT = 'Accueil — Borne d\'admission';
const BOXES = ['Box 1', 'Box 2', 'Box 3'];

$data = readJsonBody();
$action = $data['action'] ?? 'identify';
$nfc_token = trim($data['nfc_token'] ?? '');

if ($nfc_token === '') {
    jsonFail(400, "Jeton NFC obligatoire.");
}

$db = getDBConnection();
$stmt = $db->prepare("SELECT id, uuid, full_name_pseudonymized, birth_year, gender, blood_group, phone_contact FROM patients WHERE nfc_token = ?");
$stmt->execute([$nfc_token]);
$patient = $stmt->fetch();

if (!$patient) {
    jsonFail(404, "Carte non reconnue.");
}

$age = (int)date('Y') - (int)$patient['birth_year'];
$identity = [
    "uuid" => $patient['uuid'],
    "full_name_pseudonymized" => $patient['full_name_pseudonymized'],
    "birth_year" => (int)$patient['birth_year'],
    "age" => $age,
    "gender" => $patient['gender'],
    "blood_group" => $patient['blood_group'],
    "emergency_contact" => $patient['phone_contact']
];

if ($action === 'admit') {
    $box = trim($data['box'] ?? 'Box 2');
    if (!in_array($box, BOXES, true)) {
        jsonFail(400, "Box inconnu.");
    }
    $db->prepare("INSERT INTO queue_entries (patient_id, box) VALUES (?, ?)")->execute([$patient['id'], $box]);
    auditLog($db, (int)$patient['id'], 'INTAKE_ADMISSION', INTAKE_AGENT);

    $pos = $db->prepare("SELECT COUNT(*) AS n FROM queue_entries WHERE box = ? AND status = 'WAITING'");
    $pos->execute([$box]);

    echo json_encode([
        "status" => "ADMITTED",
        "patient" => $identity,
        "box" => $box,
        "queue_position" => (int)$pos->fetch()['n'],
        "admitted_at" => date('c')
    ]);
    exit;
}

auditLog($db, (int)$patient['id'], 'INTAKE_IDENTIFY', INTAKE_AGENT);
echo json_encode(["status" => "OK", "patient" => $identity, "boxes" => BOXES]);
