# HealthVault NFC

Plateforme de dossier médical sur carte NFC — HackCure 2026 (Track 2 : Web & Mobile, Track 3 : aide à la décision clinique).

**Stack :** HTML5 / CSS / JavaScript vanilla · PHP 8.2 (API REST) · SQLite (PDO) · Docker.

## Lancer le projet

```bash
chmod -R 777 database      # Linux/macOS : permet à Apache (www-data) de créer la base SQLite
docker compose up --build -d
```

- Accueil : http://localhost:8000/
- Portail praticien : http://localhost:8000/doctor.html
- Borne d'admission : http://localhost:8000/intake.html

La base `database/healthvault.sqlite` est créée automatiquement au premier appel de l'API, avec le patient de démonstration.

## Compte de démonstration

| Champ | Valeur |
|---|---|
| Jeton NFC | `token-demo-1234` |
| PIN | `1234` |

## Scénarios de démo (Track 3)

1. **Amoxicilline** → alerte CRITIQUE : allergie à la pénicilline (bêta-lactamines).
2. **Ibuprofène** → alerte HIGH_RISK : AINS + irbésartan (risque rénal).
3. **Paracétamol** → aucune collision, prescription possible.

## API

| Endpoint | Méthode | Rôle |
|---|---|---|
| `/api/intake_scan.php` | POST | Borne d'accueil : jeton NFC seul → identité minimale (`identify`) puis mise en file d'attente (`admit`) |
| `/api/auth_scan.php` | POST | Jeton NFC + PIN → jeton de session (30 min) |
| `/api/get_patient.php?uuid=` | GET | Dossier complet (session requise) |
| `/api/check_prescription.php` | POST | Détection d'allergies / interactions |
| `/api/save_prescription.php` | POST | Enregistre la prescription |
| `/api/fhir_export.php?uuid=` | GET | Bundle FHIR (Patient, Condition, AllergyIntolerance, MedicationRequest) |

Les endpoints protégés attendent l'en-tête `Authorization: Bearer <session_token>`.

## Sécurité et vie privée

- PIN hashé (bcrypt), identité pseudonymisée, CIN hashé.
- Sessions liées à un patient, avec expiration ; chaque accès est journalisé dans `audit_logs`.
- Le patient est déduit de la session côté serveur (pas de `patient_id` fourni par le client).
- Sortie HTML échappée côté client (pas d'injection via les données du dossier).
- Les appels `/api/` ne passent jamais par le cache du service worker.
- Le cache hors-ligne (localStorage) est vidé à la fermeture du dossier.

## Limites connues (démo)

- Les règles d'interaction sont un jeu minimal codé en dur, à remplacer par une vraie base pharmacologique.
- CORS ouvert (`*`) et PIN à 4 chiffres sans limitation de tentatives : à durcir avant tout usage réel.
- Données synthétiques uniquement.
