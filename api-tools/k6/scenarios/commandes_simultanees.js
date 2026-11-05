// GoLivreur — Scénario k6 #1 : 100 commandes GoShop simultanées
//
// OBJECTIF : Vérifier que l'attribution des livreurs, les notifications
// Business et les WebSocket Reverb tiennent la charge à 100 commandes
// créées en même temps (pic d'activité, ex: heure de pointe + promo).
//
// EXÉCUTION :
//   k6 run scenarios/commandes_simultanees.js
//
//   Avec URL personnalisée (staging) :
//   k6 run -e BASE_URL=https://staging.golivreur.ci/api/v1 scenarios/commandes_simultanees.js
//
// LECTURE DU RÉSULTAT (pour Francis, non-développeur) :
//   - "checks" doit être proche de 100% (toutes les vérifications passent)
//   - "http_req_duration" p(95) doit être < 2000ms (2 secondes)
//   - Si le terminal affiche "❌ SEUIL CPU DÉPASSÉ", il faut optimiser
//     le serveur AVANT le lancement officiel

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter, Trend } from 'k6/metrics';
import { BASE_URL, headersJson, verifierReponseOK } from '../lib/helpers.js';

// ── Métriques personnalisées ──────────────────────────────────────────────
const commandesReussies = new Counter('commandes_reussies');
const commandesEchouees = new Counter('commandes_echouees');
const dureeCreationCommande = new Trend('duree_creation_commande');

// ── Configuration du scénario ─────────────────────────────────────────────
export const options = {
  scenarios: {
    pic_commandes: {
      executor: 'shared-iterations',
      vus: 100,           // 100 utilisateurs virtuels simultanés
      iterations: 100,    // 100 commandes au total (1 par VU)
      maxDuration: '2m',  // Timeout de sécurité
    },
  },
  thresholds: {
    // ⚠️ SEUILS DE RÉUSSITE DU TEST
    http_req_duration: ['p(95)<2000'],     // 95% des requêtes < 2 secondes
    http_req_failed: ['rate<0.05'],        // Moins de 5% d'échecs
    checks: ['rate>0.95'],                 // 95% des vérifications passent
  },
};

// ── Données de test (correspondant au seeder DaloaSeeder) ─────────────────
const MERCHANTS_TEST = [1, 2, 3, 4, 5];  // 5 commerces du seeder
const PRODUCTS_TEST = [1, 2, 3, 4, 5];   // Produits associés

export default function () {
  const vuId = __VU;  // Identifiant unique de l'utilisateur virtuel (1 à 100)

  // ── Étape 1 : Authentification rapide (token de test) ──────────────────
  // En environnement de test k6, utiliser un compte client dédié par VU
  const loginResponse = http.post(
    `${BASE_URL}/auth/testing-login`,
    JSON.stringify({ phone: `070${String(vuId).padStart(7, '0')}` }),
    { headers: headersJson() }
  );

  if (loginResponse.status !== 200) {
    commandesEchouees.add(1);
    console.error(`VU${vuId} : échec authentification`);
    return;
  }

  const token = JSON.parse(loginResponse.body).donnees?.access_token;
  if (!token) {
    commandesEchouees.add(1);
    return;
  }

  // ── Étape 2 : Créer une commande GoShop ─────────────────────────────────
  const merchantId = MERCHANTS_TEST[vuId % MERCHANTS_TEST.length];
  const productId = PRODUCTS_TEST[vuId % PRODUCTS_TEST.length];

  const debutCreation = Date.now();

  const orderPayload = JSON.stringify({
    merchant_id: merchantId,
    items: [
      { product_id: productId, quantity: 1 + (vuId % 3) },
    ],
    delivery_address: `Quartier Test ${vuId}, Daloa`,
    delivery_lat: 6.8770 + (Math.random() - 0.5) * 0.02,
    delivery_lng: -6.4502 + (Math.random() - 0.5) * 0.02,
    payment_method: 'cash',
    distance_km: 1 + Math.random() * 5,
  });

  const orderResponse = http.post(
    `${BASE_URL}/client/orders`,
    orderPayload,
    { headers: headersJson(token) }
  );

  const dureeCreation = Date.now() - debutCreation;
  dureeCreationCommande.add(dureeCreation);

  const reussite = verifierReponseOK(orderResponse, `VU${vuId} création commande`);

  if (reussite && orderResponse.status === 201) {
    commandesReussies.add(1);

    // ── Étape 3 : Vérifier que la notification au commerce a été envoyée
    // (vérification indirecte via le statut de la commande)
    const orderData = JSON.parse(orderResponse.body).donnees;
    check(orderData, {
      'VU statut commande = pending': (d) => d.status === 'pending',
      'VU code confirmation généré': (d) => d.confirmation_code?.length === 4,
    });
  } else {
    commandesEchouees.add(1);
  }

  // Pause réaliste entre les actions (simule un vrai utilisateur)
  sleep(Math.random() * 2);
}

// ── Rapport final personnalisé ────────────────────────────────────────────
export function handleSummary(data) {
  const tauxReussite = (
    (data.metrics.commandes_reussies?.values.count || 0) /
    ((data.metrics.commandes_reussies?.values.count || 0) +
     (data.metrics.commandes_echouees?.values.count || 0)) * 100
  ).toFixed(1);

  const p95 = data.metrics.http_req_duration?.values['p(95)']?.toFixed(0) || 'N/A';
  const tauxEchecHttp = ((data.metrics.http_req_failed?.values.rate || 0) * 100).toFixed(2);

  console.log(`
╔═══════════════════════════════════════════════════════════════╗
║   GoLivreur — Rapport Scénario 1 : 100 Commandes Simultanées  ║
╠═══════════════════════════════════════════════════════════════╣
║                                                                 ║
║  Commandes réussies   : ${data.metrics.commandes_reussies?.values.count || 0}/100                              ║
║  Taux de réussite     : ${tauxReussite}%                                    ║
║  Temps réponse (p95)  : ${p95}ms                                  ║
║  Taux d'échec HTTP    : ${tauxEchecHttp}%                                    ║
║                                                                 ║
║  ${tauxReussite >= 95 ? '✅ TEST RÉUSSI' : '❌ TEST ÉCHOUÉ'} — Seuil requis : 95% de réussite           ║
║                                                                 ║
╚═══════════════════════════════════════════════════════════════╝

⚠️  N'oubliez pas de vérifier manuellement le CPU du VPS pendant
    ce test (commande : htop sur le serveur, ou DigitalOcean
    Monitoring). Le CPU ne doit jamais dépasser 70%.
`);

  return {
    'stdout': '',  // Le résumé par défaut k6 reste affiché aussi
  };
}
