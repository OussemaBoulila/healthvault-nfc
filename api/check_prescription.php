<?php
require_once __DIR__ . '/config/db.php';
header('Content-Type: application/json');

$data = readJsonBody();
$candidate_molecule = mb_strtolower(trim($data['candidate_molecule'] ?? ''), 'UTF-8');

$db = getDBConnection();
$patient_id = requireSession($db);

// Fetch patient allergies
$a_stmt = $db->prepare("SELECT substance, allergy_class, severity FROM allergies WHERE patient_id = ?");
$a_stmt->execute([$patient_id]);
$allergies = $a_stmt->fetchAll();

// Fetch active baseline medications
$m_stmt = $db->prepare("SELECT drug_name, dci_molecule FROM active_prescriptions WHERE patient_id = ? AND is_active = 1");
$m_stmt->execute([$patient_id]);
$medications = $m_stmt->fetchAll();

$alerts = [];

// Rule 1: Beta-lactam / Penicillin cross-allergy collision
$betaLactams = ['amoxicilline', 'augmentin', 'ampicilline', 'ceftriaxone', 'cefixime'];
if (in_array($candidate_molecule, $betaLactams, true)) {
    foreach ($allergies as $allergy) {
        $class = mb_strtolower($allergy['allergy_class'], 'UTF-8');
        $substance = mb_strtolower($allergy['substance'], 'UTF-8');
        if ($class === 'beta-lactam' || strpos($substance, 'pénicilline') !== false || strpos($substance, 'penicilline') !== false) {
            $alerts[] = [
                "severity" => "CRITICAL",
                "type" => "CONTRE_INDICATION_ALLERGIQUE",
                "confidence_score" => 0.99,
                "trigger_parameter" => "Allergie avérée à : " . $allergy['substance'],
                "mechanism" => "Risque d'hypersensibilité immédiate de type I (Choc anaphylactique / Œdème de Quincke).",
                "recommendation" => "Contre-indication absolue. Remplacer par un macrolide ou fluoroquinolone."
            ];
        }
    }
}

// Rule 2: NSAID + ACE-I / ARB (renal decompensation risk)
$nsaids = ['ibuprofène', 'kétoprofène', 'diclofénac', 'aspirine'];
$raas = ['irbésartan', 'ramipril', 'énalapril', 'losartan'];
if (in_array($candidate_molecule, $nsaids, true)) {
    foreach ($medications as $med) {
        if (in_array(mb_strtolower($med['dci_molecule'], 'UTF-8'), $raas, true)) {
            $alerts[] = [
                "severity" => "HIGH_RISK",
                "type" => "INTERACTION_MEDICAMENTEUSE",
                "confidence_score" => 0.94,
                "trigger_parameter" => "Traitement concomitant par " . $med['dci_molecule'] . " (" . $med['drug_name'] . ")",
                "mechanism" => "Inhibition des prostaglandines rénales vasodilatatrices par l'AINS. Risque d'insuffisance rénale aiguë.",
                "recommendation" => "Privilégier le Paracétamol pour la prise en charge analgésique."
            ];
        }
    }
}

echo json_encode([
    "status" => count($alerts) > 0 ? "ALERT_DETECTED" : "CLEAR",
    "alert_count" => count($alerts),
    "alerts" => $alerts,
    "disclaimer" => "Avis consultatif d'aide à la décision (SADM). Validation du praticien requise."
]);
