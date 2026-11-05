// GoLivreur — Helpers communs pour tous les scripts k6
// Importé par les 3 scénarios de tests de charge

import { check } from 'k6';
import http from 'k6/http';

// ── Configuration commune ──────────────────────────────────────────────────

export const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000/api/v1';

// Seuil CPU — si dépassé, le test doit être considéré comme un échec
// Le serveur VPS (8Go RAM / 4CPU) ne doit jamais dépasser 70% de CPU
export const SEUIL_CPU_POURCENT = 70;

// ── Headers JSON standards ────────────────────────────────────────────────

export function headersJson(token = null) {
  const headers = {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  };
  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }
  return headers;
}

// ── Vérification standard de réponse API GoLivreur ────────────────────────

export function verifierReponseOK(response, nomEtape) {
  return check(response, {
    [`${nomEtape} — statut 200/201`]: (r) => r.status === 200 || r.status === 201,
    [`${nomEtape} — succes=true`]: (r) => {
      try {
        return JSON.parse(r.body).succes === true;
      } catch {
        return false;
      }
    },
    [`${nomEtape} — temps réponse < 2s`]: (r) => r.timings.duration < 2000,
  });
}

// ── Authentification rapide (bypass OTP pour les tests de charge) ────────
// ⚠️ Utilise un endpoint de test réservé à l'environnement k6
// À activer uniquement sur un environnement de test dédié, JAMAIS en prod

export function obtenirTokenTest(role, index) {
  const response = http.post(
    `${BASE_URL}/testing/quick-auth`,
    JSON.stringify({ role: role, index: index }),
    { headers: headersJson() }
  );

  if (response.status !== 200) {
    console.error(`Échec auth test pour ${role}#${index}: ${response.status}`);
    return null;
  }

  const body = JSON.parse(response.body);
  return body.donnees.access_token;
}

// ── Génération de coordonnées GPS aléatoires autour de Daloa ─────────────

export function positionAleatoireDaloa() {
  // Centre Daloa : 6.8770, -6.4502 — rayon ~3km
  const latBase = 6.8770;
  const lngBase = -6.4502;
  const deltaLat = (Math.random() - 0.5) * 0.03;
  const deltaLng = (Math.random() - 0.5) * 0.03;
  return {
    lat: latBase + deltaLat,
    lng: lngBase + deltaLng,
  };
}

// ── Calcul HMAC pour les webhooks (simulé pour les tests) ────────────────

export function genererSignatureTest(payload, secret) {
  // k6 n'a pas de module crypto natif équivalent à Node.js
  // Utilise k6/crypto pour le HMAC SHA256
  // Voir scenarios/paiements_simultanes.js pour l'implémentation complète
  return 'signature-generee-dans-le-scenario';
}
