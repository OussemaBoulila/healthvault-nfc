<?php
require_once __DIR__ . '/config/db.php';
header('Content-Type: application/json');

$data = readJsonBody();
$drug_name = trim($data['drug_name'] ?? '');
$dci_molecule = trim($data['dci_molecule'] ?? '');
$dosage = trim($data['dosage'] ?? '');
$frequency = trim($data['frequency'] ?? '');
$doctor_name = trim($data['doctor_name'] ?? 'Dr. Yassine Rached');
$doctor_license = trim($data['doctor_license'] ?? 'CNOM-14820');

if ($drug_name === '') {
    jsonFail(400, "Données de prescription incomplètes.");
}
if (mb_strlen($drug_name) > 100 || mb_strlen($dci_molecule) > 100 || mb_strlen($frequency) > 50 || mb_strlen($dosage) > 50) {
    jsonFail(400, "Champ trop long.");
}

$db = getDBConnection();
$patient_id = requireSession($db);

$stmt = $db->prepare("INSERT INTO active_prescriptions (patient_id, drug_name, dci_molecule, dosage, frequency, prescribed_by, doctor_license, start_date, end_date, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, DATE('now'), DATE('now', '+30 days'), 1)");
$stmt->execute([$patient_id, $drug_name, $dci_molecule, $dosage, $frequency, $doctor_name, $doctor_license]);

auditLog($db, $patient_id, 'PRESCRIPTION_SIGNED');

echo json_encode([
    "status" => "SUCCESS",
    "message" => "Prescription validée et synchronisée sur la carte patient."
]);
