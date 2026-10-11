<?php
require_once __DIR__ . '/config/db.php';
header('Content-Type: application/fhir+json; charset=utf-8');

$uuid = $_GET['uuid'] ?? '';
$db = getDBConnection();
$sessionPatientId = requireSession($db);

$stmt = $db->prepare("SELECT * FROM patients WHERE uuid = ?");
$stmt->execute([$uuid]);
$patient = $stmt->fetch();

if (!$patient) {
    jsonFail(404, "Patient non trouvé");
}
if ((int)$patient['id'] !== $sessionPatientId) {
    jsonFail(403, "Cette session n'est pas autorisée pour ce dossier.");
}

$pid = (int)$patient['id'];
$entries = [[
    "resource" => [
        "resourceType" => "Patient",
        "id" => $patient['uuid'],
        "identifier" => [
            ["system" => "https://sante.gov.tn/ins", "value" => $patient['cin_hashed']]
        ],
        "name" => [["text" => $patient['full_name_pseudonymized']]],
        "gender" => $patient['gender'] === 'M' ? 'male' : 'female',
        "birthDate" => (string)$patient['birth_year']
    ]
]];

$c = $db->prepare("SELECT condition_name, icd10_code, diagnosed_date FROM chronic_conditions WHERE patient_id = ? AND status = 'ACTIVE'");
$c->execute([$pid]);
foreach ($c->fetchAll() as $row) {
    $entries[] = ["resource" => [
        "resourceType" => "Condition",
        "clinicalStatus" => ["coding" => [["system" => "http://terminology.hl7.org/CodeSystem/condition-clinical", "code" => "active"]]],
        "code" => [
            "coding" => [["system" => "http://hl7.org/fhir/sid/icd-10", "code" => $row['icd10_code'], "display" => $row['condition_name']]],
            "text" => $row['condition_name']
        ],
        "subject" => ["reference" => "Patient/" . $patient['uuid']],
        "onsetDateTime" => $row['diagnosed_date']
    ]];
}

$a = $db->prepare("SELECT substance, severity FROM allergies WHERE patient_id = ?");
$a->execute([$pid]);
foreach ($a->fetchAll() as $row) {
    $entries[] = ["resource" => [
        "resourceType" => "AllergyIntolerance",
        "criticality" => $row['severity'] === 'CRITICAL' ? 'high' : 'low',
        "code" => ["text" => $row['substance']],
        "patient" => ["reference" => "Patient/" . $patient['uuid']]
    ]];
}

$m = $db->prepare("SELECT drug_name, dci_molecule, dosage, frequency, prescribed_by, doctor_license, start_date, end_date FROM active_prescriptions WHERE patient_id = ? AND is_active = 1");
$m->execute([$pid]);
foreach ($m->fetchAll() as $row) {
    $entries[] = ["resource" => [
        "resourceType" => "MedicationRequest",
        "status" => "active",
        "intent" => "order",
        "medicationCodeableConcept" => ["text" => $row['drug_name'] . " (" . $row['dci_molecule'] . ")"],
        "subject" => ["reference" => "Patient/" . $patient['uuid']],
        "authoredOn" => $row['start_date'],
        "requester" => [
            "identifier" => ["value" => $row['doctor_license']],
            "display" => $row['prescribed_by']
        ],
        "dosageInstruction" => [["text" => $row['dosage'] . " — " . $row['frequency']]],
        "dispenseRequest" => ["validityPeriod" => ["start" => $row['start_date'], "end" => $row['end_date']]]
    ]];
}

auditLog($db, $pid, 'FHIR_EXPORT');

echo json_encode([
    "resourceType" => "Bundle",
    "type" => "collection",
    "entry" => $entries
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
