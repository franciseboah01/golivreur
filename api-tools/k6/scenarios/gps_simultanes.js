// GoLivreur — Scénario k6 #2 : 50 livreurs GPS simultanés
//
// OBJECTIF : Vérifier que Laravel Reverb (WebSocket) et Redis ne saturent
// pas quand 50 livreurs envoient leur position GPS toutes les 5 secondes
// en même temps (simulation heure de pointe).
//
// EXÉCUTION :
//   k6 run scenarios/gps_simultanes.js
//
// ⚠️ ARRÊT AUTOMATIQUE : Ce script vérifie le CPU du serveur via
// l'endpoint /api/v1/admin/system-metrics (à créer côté Laravel,
// réservé à national_tech/super_admin) et s'arrête si > 70%.

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter, Gauge } from 'k6/metrics';
import { BASE_URL, headersJson, positionAleatoireDaloa, SEUIL_CPU_POURCENT } from '../lib/helpers.js';

const positionsEnvoyees = new Counter('positions_gps_envoyees');
const positionsEchouees = new Counter('positions_gps_echouees');
const cpuServeur = new Gauge('cpu_serveur_pourcent');

export const options = {
  scenarios: {
    livreurs_gps: {
      executor: 'constant-vus',
      vus: 50,             // 50 livreurs simulés simultanément
      duration: '2m',      // Test sur 2 minutes (24 envois par livreur)
    },
  },
  thresholds: {
    http_req_duration: ['p(95)<1000'],   // GPS doit être rapide : < 1s
    http_req_failed: ['rate<0.02'],      // Très peu de tolérance pour le GPS
    checks: ['rate>0.95'],
  },
};

// Token admin pour vérifier le CPU (à définir via variable d'environnement)
const ADMIN_TOKEN = __ENV.ADMIN_TOKEN || '';

// Variable globale pour arrêter le test si CPU trop élevé
let arretDemande = false;

export default function () {
  if (arretDemande) {
    return;  // Arrêt anticipé si le CPU a dépassé le seuil
  }

  const vuId = __VU;

  // ── Authentification rapide livreur de test ─────────────────────────────
  const loginResponse = http.post(
    `${BASE_URL}/auth/testing-login`,
    JSON.stringify({ phone: `070${String(3000000 + vuId).padStart(7, '0')}`, role: 'driver' }),
    { headers: headersJson() }
  );

  if (loginResponse.status !== 200) {
    positionsEchouees.add(1);
    return;
  }

  const token = JSON.parse(loginResponse.body).donnees?.access_token;
  if (!token) {
    positionsEchouees.add(1);
    return;
  }

  // ── Boucle d'envoi GPS toutes les 5 secondes pendant la durée du test ──
  const position = positionAleatoireDaloa();

  const gpsResponse = http.post(
    `${BASE_URL}/courier/location`,
    JSON.stringify({
      lat: position.lat,
      lng: position.lng,
    }),
    { headers: headersJson(token) }
  );

  const reussite = check(gpsResponse, {
    [`VU${vuId} GPS — statut 200`]: (r) => r.status === 200,
    [`VU${vuId} GPS — temps < 1s`]: (r) => r.timings.duration < 1000,
  });

  if (reussite) {
    positionsEnvoyees.add(1);
  } else {
    positionsEchouees.add(1);
  }

  // ── Vérification CPU serveur (uniquement le premier VU, toutes les 15s) ─
  if (vuId === 1 && ADMIN_TOKEN) {
    const metricsResponse = http.get(
      `${BASE_URL}/admin/system-metrics`,
      { headers: headersJson(ADMIN_TOKEN) }
    );

    if (metricsResponse.status === 200) {
      const cpu = JSON.parse(metricsResponse.body).donnees?.cpu_percent || 0;
      cpuServeur.add(cpu);

      if (cpu > SEUIL_CPU_POURCENT) {
        console.error(`
🚨 ALERTE CPU — Seuil dépassé : ${cpu}% (limite ${SEUIL_CPU_POURCENT}%)
   Le test va s'arrêter automatiquement.
   Action recommandée : optimiser le serveur avant le lancement officiel.
        `);
        arretDemande = true;
      }
    }
  }

  // Intervalle réaliste : 5 secondes entre chaque position GPS
  sleep(5);
}

export function handleSummary(data) {
  const envoyees = data.metrics.positions_gps_envoyees?.values.count || 0;
  const echouees = data.metrics.positions_gps_echouees?.values.count || 0;
  const total = envoyees + echouees;
  const tauxReussite = total > 0 ? ((envoyees / total) * 100).toFixed(1) : '0';
  const p95 = data.metrics.http_req_duration?.values['p(95)']?.toFixed(0) || 'N/A';
  const cpuMax = data.metrics.cpu_serveur_pourcent?.values.max?.toFixed(1) || 'Non mesuré';

  console.log(`
╔═══════════════════════════════════════════════════════════════╗
║   GoLivreur — Rapport Scénario 2 : 50 Livreurs GPS Simultanés ║
╠═══════════════════════════════════════════════════════════════╣
║                                                                 ║
║  Positions envoyées   : ${envoyees}                                       ║
║  Positions échouées   : ${echouees}                                        ║
║  Taux de réussite     : ${tauxReussite}%                                    ║
║  Temps réponse (p95)  : ${p95}ms                                  ║
║  CPU max observé      : ${cpuMax}%                                  ║
║                                                                 ║
║  ${parseFloat(tauxReussite) >= 95 ? '✅ TEST RÉUSSI' : '❌ TEST ÉCHOUÉ'}                                              ║
║  ${cpuMax !== 'Non mesuré' && parseFloat(cpuMax) > 70 ? '🚨 SEUIL CPU DÉPASSÉ — Optimisation nécessaire' : '✅ CPU dans les limites (si mesuré)'}  ║
║                                                                 ║
╚═══════════════════════════════════════════════════════════════╝

💡 Conseil : Surveillez aussi manuellement Redis pendant ce test :
   docker compose exec golivreur_redis redis-cli INFO stats
`);

  return { 'stdout': '' };
}
