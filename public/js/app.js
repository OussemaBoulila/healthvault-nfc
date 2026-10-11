if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('/sw.js').catch(console.error);
}

/* ---------- Helpers ---------- */
const $ = (id) => document.getElementById(id);

// Escape any server-provided string before putting it in innerHTML
function esc(value) {
  return String(value ?? '').replace(/[&<>"']/g, (c) => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
  ));
}

function toast(message, type = '') {
  const el = document.createElement('div');
  el.className = 'toast ' + type;
  el.textContent = message;
  $('toasts')?.appendChild(el);
  setTimeout(() => el.remove(), 4500);
}

const session = {
  get token() { return sessionStorage.getItem('hv_session'); },
  set token(v) { v ? sessionStorage.setItem('hv_session', v) : sessionStorage.removeItem('hv_session'); },
  get uuid() { return sessionStorage.getItem('hv_uuid'); },
  set uuid(v) { v ? sessionStorage.setItem('hv_uuid', v) : sessionStorage.removeItem('hv_uuid'); }
};

function authHeaders() {
  return { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + (session.token || '') };
}

let currentPatientId = null;
let currentPatient = null; // profile of the open record (used for printing)
let lastAnalysis = null; // result of the latest interaction check

/* ---------- Drug catalog (autocomplete) ---------- */
// `molecule` is the key understood by the rules engine (api/check_prescription.php)
const DRUGS = [
  { label: 'Amoxicilline 1g (Beta-lactamine)', brand: 'Amoxicilline', molecule: 'amoxicilline' },
  { label: 'Augmentin 1g (Beta-lactamine)', brand: 'Augmentin', molecule: 'augmentin' },
  { label: 'Ampicilline 1g (Beta-lactamine)', brand: 'Ampicilline', molecule: 'ampicilline' },
  { label: 'Ceftriaxone 1g (Céphalosporine)', brand: 'Ceftriaxone', molecule: 'ceftriaxone' },
  { label: 'Cefixime 200mg (Céphalosporine)', brand: 'Cefixime', molecule: 'cefixime' },
  { label: 'Ibuprofène 400mg (AINS)', brand: 'Ibuprofène', molecule: 'ibuprofène' },
  { label: 'Kétoprofène 100mg (AINS)', brand: 'Kétoprofène', molecule: 'kétoprofène' },
  { label: 'Diclofénac 50mg (AINS)', brand: 'Diclofénac', molecule: 'diclofénac' },
  { label: 'Aspirine 500mg (AINS)', brand: 'Aspirine', molecule: 'aspirine' },
  { label: 'Paracétamol 1g (Analgésique)', brand: 'Paracétamol', molecule: 'paracétamol' },
  { label: 'Azithromycine 500mg (Macrolide)', brand: 'Azithromycine', molecule: 'azithromycine' },
  { label: 'Oméprazole 20mg (IPP)', brand: 'Oméprazole', molecule: 'oméprazole' }
];

function resolveDrug(text) {
  const t = text.trim().toLowerCase();
  return DRUGS.find(d => d.label.toLowerCase() === t) || null;
}

const drugList = $('drugList');
if (drugList) {
  drugList.innerHTML = DRUGS.map(d => `<option value="${esc(d.label)}"></option>`).join('');
}

/* ---------- Online / offline status ---------- */
function setStatus(online) {
  const badge = $('statusBadge');
  if (!badge) return;
  badge.className = 'badge ' + (online ? 'badge-verified' : 'badge-offline');
  badge.textContent = online ? 'Système En Ligne' : 'Mode Hors-Ligne (Cache Local Actif)';
}
window.addEventListener('offline', () => setStatus(false));
window.addEventListener('online', () => setStatus(true));
if (!navigator.onLine) setStatus(false);

/* ---------- Authentication ---------- */
$('btnAuthScan')?.addEventListener('click', async () => {
  const token = $('tokenInput').value.trim();
  const pin = $('pinInput').value.trim();
  const btn = $('btnAuthScan');
  btn.disabled = true;

  try {
    const res = await fetch('/api/auth_scan.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ nfc_token: token, pin: pin })
    });
    const data = await res.json();
    if (res.ok) {
      session.token = data.session_token;
      session.uuid = data.patient_uuid;
      localStorage.setItem('cached_token', token);
      await loadPatientRecord(data.patient_uuid);
    } else {
      toast("Erreur d'accès : " + (data.message || res.status), 'error');
    }
  } catch (err) {
    // Mode dégradé: only serve the snapshot of the card that was scanned online earlier
    const cached = localStorage.getItem('cached_patient');
    if (cached && localStorage.getItem('cached_token') === token) {
      setStatus(false);
      renderDashboard(JSON.parse(cached), true);
    } else {
      toast('Mode hors-ligne : aucun instantané en cache pour cette carte.', 'error');
    }
  } finally {
    btn.disabled = false;
  }
});

async function loadPatientRecord(uuid) {
  try {
    const res = await fetch(`/api/get_patient.php?uuid=${encodeURIComponent(uuid)}`, { headers: authHeaders() });
    const data = await res.json();
    if (!res.ok) {
      toast(data.error || 'Impossible de charger le dossier.', 'error');
      return;
    }
    localStorage.setItem('cached_patient', JSON.stringify(data));
    renderDashboard(data, false);
  } catch (err) {
    const cached = localStorage.getItem('cached_patient');
    if (cached) {
      setStatus(false);
      renderDashboard(JSON.parse(cached), true);
    } else {
      toast('Serveur injoignable et aucun cache disponible.', 'error');
    }
  }
}

/* ---------- Rendering ---------- */
function renderDashboard(data, isOffline) {
  $('scanGate').style.display = 'none';
  $('patientDashboard').style.display = 'block';

  currentPatientId = data.profile.id;
  currentPatient = data.profile;
  $('patientName').textContent = data.profile.full_name_pseudonymized + (isOffline ? ' (HORS-LIGNE)' : '');
  $('patientMeta').textContent = `Né en ${data.profile.birth_year} | Groupe : ${data.profile.blood_group} | Ref : ${data.profile.uuid}`;

  $('conditionsList').innerHTML = data.conditions.length
    ? data.conditions.map(c => `
      <div class="timeline-item">
        <strong>${esc(c.condition_name)}</strong> (${esc(c.icd10_code)})<br>
        <small class="muted">Certifié par ${esc(c.certified_by)}</small>
      </div>`).join('')
    : '<div class="empty">Aucune pathologie chronique.</div>';

  $('allergiesList').innerHTML = data.allergies.length
    ? data.allergies.map(a => `<span class="badge badge-danger">Allergie : ${esc(a.substance)} (${esc(a.severity)})</span>`).join(' ')
    : '<div class="empty">Aucune allergie connue.</div>';

  $('medicationsList').innerHTML = data.active_medications.length
    ? data.active_medications.map(m => `
      <div class="timeline-item">
        <strong>${esc(m.drug_name)} (${esc(m.dci_molecule)})</strong> — ${esc(m.dosage)}<br>
        <small class="muted">${esc(m.frequency)} | Prescrit par ${esc(m.prescribed_by)} (${esc(m.doctor_license || '—')})<br>
        Du ${esc(m.start_date)} au ${esc(m.end_date || '—')}</small>
      </div>`).join('')
    : '<div class="empty">Aucun traitement actif.</div>';
}

/* ---------- Interaction check (XAI alerts) ---------- */
async function runCheck() {
  const drug = resolveDrug($('moleculeSelect').value);
  const molecule = drug ? drug.molecule : '';
  const alertContainer = $('alertContainer');
  lastAnalysis = null;

  if (!molecule) {
    alertContainer.style.display = 'none';
    return;
  }

  try {
    const res = await fetch('/api/check_prescription.php', {
      method: 'POST',
      headers: authHeaders(),
      body: JSON.stringify({ candidate_molecule: molecule })
    });
    const analysis = await res.json();
    if (!res.ok) {
      toast(analysis.message || 'Analyse impossible.', 'error');
      return;
    }
    lastAnalysis = { molecule, ...analysis };

    alertContainer.style.display = 'block';
    if (analysis.status === 'ALERT_DETECTED') {
      alertContainer.innerHTML = analysis.alerts.map(a => `
        <div class="alert-box ${a.severity === 'HIGH_RISK' ? 'high-risk' : ''}" role="alert">
          <div class="alert-title">
            <span>ALERTE SÉCURITÉ : ${esc(a.type)}</span>
            <span>Confiance : ${(Number(a.confidence_score) * 100).toFixed(0)}%</span>
          </div>
          <div class="alert-desc"><strong>Déclencheur :</strong> ${esc(a.trigger_parameter)}</div>
          <div class="alert-desc"><strong>Mécanisme :</strong> ${esc(a.mechanism)}</div>
          <div class="alert-desc" style="margin-top: 6px; font-weight: 600;"><strong>Action recommandée :</strong> ${esc(a.recommendation)}</div>
        </div>`).join('');
    } else {
      alertContainer.innerHTML = '<div class="clear-box">Aucune interaction ou collision détectée pour cette molécule.</div>';
    }
  } catch (err) {
    toast('Vérification impossible (hors-ligne).', 'error');
  }
}
// Fires as soon as a catalog entry is picked from the autocomplete (rules run server-side)
$('moleculeSelect')?.addEventListener('input', runCheck);
$('moleculeSelect')?.addEventListener('change', runCheck);

/* ---------- Sign & sync prescription ---------- */
$('btnSignPrescription')?.addEventListener('click', async () => {
  const select = $('moleculeSelect');
  const drug = resolveDrug(select.value);
  const molecule = drug ? drug.molecule : '';
  const posology = $('posologyInput').value.trim();

  if (!drug) {
    toast('Veuillez choisir un médicament dans la liste de suggestions.', 'error');
    return;
  }
  if (!posology) {
    toast('Veuillez renseigner la posologie.', 'error');
    return;
  }

  // Never sign on stale/missing analysis
  if (!lastAnalysis || lastAnalysis.molecule !== molecule) {
    toast("L'analyse de sécurité n'est pas à jour. Réessayez.", 'error');
    return;
  }

  // Explicit practitioner override required when alerts were raised
  if (lastAnalysis.status === 'ALERT_DETECTED') {
    const hasCritical = lastAnalysis.alerts.some(a => a.severity === 'CRITICAL');
    const msg = hasCritical
      ? 'ALERTE CRITIQUE : contre-indication absolue détectée. Confirmer malgré tout la prescription sous votre responsabilité ?'
      : 'Une interaction à risque a été détectée. Confirmer malgré tout la prescription sous votre responsabilité ?';
    if (!confirm(msg)) return;
  }

  const drugName = drug.brand;
  const strength = drug.label.replace(drug.brand, '').split('(')[0].trim() || 'Standard';
  const btn = $('btnSignPrescription');
  btn.disabled = true;

  try {
    const res = await fetch('/api/save_prescription.php', {
      method: 'POST',
      headers: authHeaders(),
      body: JSON.stringify({
        drug_name: drugName,
        dci_molecule: molecule,
        dosage: strength,
        frequency: posology
      })
    });
    const data = await res.json();
    if (!res.ok) {
      toast(data.message || 'Échec de la prescription.', 'error');
      return;
    }
    toast(data.message || 'Prescription validée !', 'success');
    printPrescription({ drugName, strength, posology });
    select.value = '';
    $('alertContainer').style.display = 'none';
    lastAnalysis = null;
    await loadPatientRecord(session.uuid); // refresh in place instead of reloading the page
  } catch (err) {
    toast('Serveur injoignable : prescription non enregistrée.', 'error');
  } finally {
    btn.disabled = false;
  }
});

/* ---------- Printed prescription ---------- */
function printPrescription({ drugName, strength, posology }) {
  const p = currentPatient || {};
  const today = new Date().toLocaleDateString('fr-FR');
  $('printArea').innerHTML = `
    <h1>Ordonnance — HealthVault NFC</h1>
    <div class="rx-row"><strong>Dr. Yassine Rached</strong> — Cardiologie (CNOM-14820)</div>
    <div class="rx-row">Date : ${esc(today)}</div>
    <div class="rx-row">Patient : <strong>${esc(p.full_name_pseudonymized)}</strong> — né en ${esc(p.birth_year)} — réf. ${esc(p.uuid)}</div>
    <div class="rx-drug">${esc(drugName)} ${esc(strength)}</div>
    <div class="rx-row">Posologie : ${esc(posology)}</div>
    <div class="rx-row">Durée de validité : 30 jours</div>
    <div class="rx-sign">Signature et cachet du praticien</div>
    <div class="rx-foot">Prescription générée avec le système d'aide à la décision médicale (SADM) — validée sous la responsabilité du praticien.</div>`;
  window.print();
}

/* ---------- FHIR export ---------- */
$('btnFhir')?.addEventListener('click', async () => {
  try {
    const res = await fetch(`/api/fhir_export.php?uuid=${encodeURIComponent(session.uuid)}`, { headers: authHeaders() });
    if (!res.ok) {
      const err = await res.json();
      toast(err.message || 'Export impossible.', 'error');
      return;
    }
    const blob = await res.blob();
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `fhir-${session.uuid}.json`;
    a.click();
    URL.revokeObjectURL(a.href);
  } catch (err) {
    toast('Export FHIR impossible.', 'error');
  }
});

/* ---------- Close record ---------- */
$('btnLogout')?.addEventListener('click', () => {
  session.token = null;
  session.uuid = null;
  localStorage.removeItem('cached_patient'); // do not leave patient data on a shared device
  currentPatientId = null;
  lastAnalysis = null;
  $('patientDashboard').style.display = 'none';
  $('scanGate').style.display = 'block';
  $('alertContainer').style.display = 'none';
  $('moleculeSelect').value = '';
});
