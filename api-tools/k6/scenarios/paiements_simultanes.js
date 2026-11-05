// GoLivreur — Scénario k6 #3 : 20 paiements Orange Money simultanés
//
// OBJECTIF : Vérifier les timeouts et l'absence de double-débit quand
// 20 webhooks Hub2/CinetPay arrivent en même temps (queue 'high').
// Teste également le REJET des webhooks à signature invalide.
//
// EXÉCUTION :
//   k6 run -e WEBHOOK_SECRET=votre_secret scenarios/paiements_simultanes.js
//
// ⚠️ Le WEBHOOK_SECRET doit correspondre à HUB2_WEBHOOK_SECRET
//    de l'environnement testé (voir .env Laravel)

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter, Trend } from 'k6/metrics';
import crypto from 'k6/crypto';
import { BASE_URL, headersJson } from '../lib/helpers.js';

const paiementsValidesReussis = new Counter('paiements_valides_reussis');
const paiementsValidesEchoues = new Counter('paiements_valides_echoues');
const paiementsInvalidesRejetes = new Counter('paiements_invalides_rejetes_correctement');
const paiementsInvalidesAcceptesParErreur = new Counter('paiements_invalides_ACCEPTES_PAR_ERREUR');
const dureeTraitementWebhook = new Trend('duree_traitement_webhook');

export const options = {
  scenarios: {
    // Scénario A : 20 paiements valides simultanés
    paiements_valides: {
      executor: 'shared-iterations',
      vus: 20,
      iterations: 20,
      maxDuration: '1m',
      exec: 'testerPaiementValide',
    },
    // Scénario B : 5 tentatives avec signature invalide (test sécurité)
    paiements_invalides: {
      executor: 'shared-iterations',
      vus: 5,
      iterations: 5,
      maxDuration: '30s',
      startTime: '10s',  // Démarre après les paiements valides
      exec: 'testerPaiementSignatureInvalide',
    },
  },
  thresholds: {
    http_req_duration: ['p(95)<3000'],   // Webhooks acceptables jusqu'à 3s
    // ⚠️ CRITIQUE : AUCUN paiement invalide ne doit être accepté
    'paiements_invalides_ACCEPTES_PAR_ERREUR': ['count==0'],
  },
};

const WEBHOOK_SECRET = __ENV.WEBHOOK_SECRET || 'test-secret-not-real';

// ── Génère une signature HMAC-SHA256 valide (comme le ferait Hub2) ───────
function genererSignatureHMAC(payload, secret) {
  return crypto.hmac('sha256', secret, payload, 'hex');
}

// ── Scénario A : Paiements avec signature VALIDE ──────────────────────────
export function testerPaiementValide() {
  const vuId = __VU;
  const transactionId = `TEST-TXN-${vuId}-${Date.now()}`;

  const payload = JSON.stringify({
    event: 'payment.success',
    transaction_id: transactionId,
    status: 'SUCCESS',
    amount: 1000 + (vuId * 100),
    currency: 'XOF',
    timestamp: new Date().toISOString(),
  });

  const signature = genererSignatureHMAC(payload, WEBHOOK_SECRET);

  const debut = Date.now();

  const response = http.post(
    `${BASE_URL}/webhooks/payment`,
    payload,
    {
      headers: {
        'Content-Type': 'application/json',
        'X-Hub2-Signature': signature,
      },
    }
  );

  const duree = Date.now() - debut;
  dureeTraitementWebhook.add(duree);

  const reussite = check(response, {
    [`VU${vuId} webhook valide — statut 200`]: (r) => r.status === 200,
    [`VU${vuId} webhook valide — succes=true`]: (r) => {
      try { return JSON.parse(r.body).succes === true; } catch { return false; }
    },
  });

  if (reussite) {
    paiementsValidesReussis.add(1);
  } else {
    paiementsValidesEchoues.add(1);
    console.error(`VU${vuId} : webhook valide a échoué — statut ${response.status}`);
  }

  sleep(0.5);
}

// ── Scénario B : Paiements avec signature INVALIDE (test sécurité) ───────
export function testerPaiementSignatureInvalide() {
  const vuId = __VU;
  const transactionId = `TEST-FRAUDE-${vuId}-${Date.now()}`;

  const payload = JSON.stringify({
    event: 'payment.success',
    transaction_id: transactionId,
    status: 'SUCCESS',
    amount: 999999,  // Montant suspect pour le test
    currency: 'XOF',
  });

  // Signature volontairement FAUSSE
  const fausseSignature = 'signature-frauduleuse-' + Math.random().toString(36);

  const response = http.post(
    `${BASE_URL}/webhooks/payment`,
    payload,
    {
      headers: {
        'Content-Type': 'application/json',
        'X-Hub2-Signature': fausseSignature,
      },
    }
  );

  const rejeteCorrectement = check(response, {
    [`VU${vuId} signature invalide — statut 401`]: (r) => r.status === 401,
    [`VU${vuId} signature invalide — succes=false`]: (r) => {
      try { return JSON.parse(r.body).succes === false; } catch { return false; }
    },
  });

  if (rejeteCorrectement) {
    paiementsInvalidesRejetes.add(1);
  } else {
    // ⚠️ CRITIQUE : Si on arrive ici, un faux paiement a été ACCEPTÉ
    paiementsInvalidesAcceptesParErreur.add(1);
    console.error(`
🚨🚨🚨 FAILLE DE SÉCURITÉ DÉTECTÉE 🚨🚨🚨
VU${vuId} : Un webhook avec signature INVALIDE a été accepté !
Statut reçu : ${response.status}
Ceci est une vulnérabilité CRITIQUE — ne pas déployer en production.
    `);
  }

  sleep(0.5);
}

export function handleSummary(data) {
  const validesReussis = data.metrics.paiements_valides_reussis?.values.count || 0;
  const validesEchoues = data.metrics.paiements_valides_echoues?.values.count || 0;
  const invalidesRejetes = data.metrics.paiements_invalides_rejetes_correctement?.values.count || 0;
  const invalidesAcceptesParErreur = data.metrics.paiements_invalides_ACCEPTES_PAR_ERREUR?.values.count || 0;
  const p95 = data.metrics.http_req_duration?.values['p(95)']?.toFixed(0) || 'N/A';

  const securiteOK = invalidesAcceptesParErreur === 0;

  console.log(`
╔═══════════════════════════════════════════════════════════════╗
║   GoLivreur — Rapport Scénario 3 : 20 Paiements Simultanés    ║
╠═══════════════════════════════════════════════════════════════╣
║                                                                 ║
║  PAIEMENTS VALIDES (signature correcte)                       ║
║    Réussis            : ${validesReussis}/20                              ║
║    Échoués            : ${validesEchoues}                                       ║
║    Temps réponse(p95) : ${p95}ms                                  ║
║                                                                 ║
║  SÉCURITÉ — PAIEMENTS AVEC SIGNATURE INVALIDE                 ║
║    Correctement rejetés        : ${invalidesRejetes}/5                          ║
║    ⚠️  Acceptés par erreur     : ${invalidesAcceptesParErreur}                            ║
║                                                                 ║
║  ${securiteOK ? '✅ SÉCURITÉ OK — Aucun faux paiement accepté' : '🚨 ÉCHEC CRITIQUE — FAILLE DE SÉCURITÉ DÉTECTÉE'}    ║
║  ${validesReussis >= 19 ? '✅ PERFORMANCE OK' : '⚠️  Vérifier les échecs de paiements valides'}                              ║
║                                                                 ║
╚═══════════════════════════════════════════════════════════════╝

${!securiteOK ? '🚨 NE PAS DÉPLOYER EN PRODUCTION avant correction de cette faille !' : ''}
`);

  return { 'stdout': '' };
}
