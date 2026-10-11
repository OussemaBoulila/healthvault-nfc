<?php
require_once __DIR__ . '/config/db.php';
header('Content-Type: application/json');

$uuid = $_GET['uuid'] ?? '';
if ($uuid === '') {
    jsonFail(400, "UUID patient manquant.");
}

$db = getDBConnection();
$sessionPatientId = requireSession($db);

$stmt = $db->prepare("SELECT id, uuid, full_name_pseudonymized, birth_year, gender, blood_group, phone_contact FROM patients WHERE uuid = ?");
$stmt->execute([$uuid]);
$patient = $stmt->fetch();

if (!$patient) {
    jsonFail(404, "Dossier introuvable.");
}
if ((int)$patient['id'] !== $sessionPatientId) {
    jsonFail(403, "Cette session n'est pas autorisée pour ce dossier.");
}

$c_stmt = $db->prepare("SELECT condition_name, icd10_code, diagnosed_date, certified_by FROM chronic_conditions WHERE patient_id = ? AND status = 'ACTIVE'");
$c_stmt->execute([$patient['id']]);
$conditions = $c_stmt->fetchAll();

$a_stmt = $db->prepare("SELECT substance, allergy_class, severity, certified_by FROM allergies WHERE patient_id = ?");
$a_stmt->execute([$patient['id']]);
$allergies = $a_stmt->fetchAll();

$m_stmt = $db->prepare("SELECT drug_name, dci_molecule, dosage, frequency, prescribed_by, doctor_license, start_date, end_date FROM active_prescriptions WHERE patient_id = ? AND is_active = 1 ORDER BY start_date DESC, id DESC");
$m_stmt->execute([$patient['id']]);
$medications = $m_stmt->fetchAll();

auditLog($db, (int)$patient['id'], 'RECORD_READ');

echo json_encode([
    "status" => "OK",
    "profile" => $patient,
    "conditions" => $conditions,
    "allergies" => $allergies,
    "active_medications" => $medications
]);
