# StopClope - Roadmap d'Amélioration

> Roadmap établie suite à l'audit multi-agents du 11/12/2024
> Mise à jour : 14/12/2024
> Scores post-Sprint 7 : Code 7.5/10 | Sécurité 7.5/10 | UX 7.5/10 | Performance 7.5/10 | A11y 7/10 | Product 7/10
> Score moyen : 7.3/10

---

## Vue d'ensemble

### Problèmes critiques identifiés

| Priorité | Problème | Impact | Statut |
|----------|----------|--------|--------|
| **P0** | ~~Pas d'authentification~~ | ~~Critique~~ | ✅ Fait |
| **P0** | ~~Performance O(n²)~~ | ~~Critique~~ | ✅ Fait (DailyScore) |
| **P0** | ~~Scoring mesure RÉGULARITÉ pas RÉDUCTION~~ | ~~Critique~~ | ✅ Fait |
| **P1** | ~~Vulnérabilité IDOR sur /delete/{id}~~ | ~~Haute~~ | ✅ Fait |
| **P1** | ~~Aucun feedback après enregistrement~~ | ~~Haute~~ | ✅ Fait |
| **P1** | ~~A11y : modal confirm() non accessible~~ | ~~Haute~~ | ✅ Fait |
| **P2** | Cache Redis (optionnel) | Moyenne | En attente |
| **P2** | ~~N+1 queries sur getDailyAverageInterval~~ | ~~Moyenne~~ | ✅ Fait |
| **P2** | ~~Contrastes insuffisants (WCAG AA)~~ | ~~Moyenne~~ | ✅ Fait |

### Nouveaux problèmes (Audit 14/12/2024)

| Priorité | Problème | Impact | Statut |
|----------|----------|--------|--------|
| **P0** | ~~APP_SECRET commité dans `.env.dev`~~ | ~~Critique~~ | ✅ Fait |
| **P0** | ~~N+1 queries BadgeService (checkZeroDay, checkChampion)~~ | ~~Haute~~ | ✅ Fait |
| **P0** | ~~CSS/JS inline (~80KB non cacheable)~~ | ~~Haute~~ | ✅ Fait |
| **P1** | ~~WakeUpRepository sans filtrage user~~ | ~~Moyenne~~ | ✅ Fait |
| **P1** | ~~Refresh complet après logging~~ | ~~Moyenne~~ | ✅ Gardé (cohérence UI) |
| **P1** | ~~Duplication code (calculateDailyScoreFromData)~~ | ~~Moyenne~~ | ✅ Fait |
| **P2** | ~~getStreak() non optimisé (getStreakOptimized existe)~~ | ~~Moyenne~~ | ✅ Fait |
| **P2** | ~~Focus trap manquant dans modales~~ | ~~Basse~~ | ✅ Fait |

---

## Sprint 1 : Fondations & Sécurité

**Objectif** : Rendre l'application sécurisée et performante

### 1.1 Authentification & Multi-utilisateurs

**Fichiers concernés** :
- `src/Entity/User.php` (nouveau)
- `config/packages/security.yaml`
- `src/Controller/SecurityController.php` (nouveau)
- `src/Entity/Cigarette.php` (ajout relation User)

**Tâches** :
- [x] Créer l'entité User avec Symfony Security
- [x] Implémenter login/register/logout
- [x] Ajouter relation ManyToOne User sur Cigarette (nullable initialement)
- [x] **Migration données existantes** : À la création du premier compte, rattacher automatiquement toutes les cigarettes orphelines (user_id = NULL) à ce user
- [x] Ajouter firewall et access_control
- [x] Filtrer toutes les requêtes par user connecté
- [ ] Rendre la relation User obligatoire après migration

**Stratégie de migration** :
```php
// Dans RegisterController ou un EventSubscriber post-registration
// Si c'est le premier user ET qu'il existe des cigarettes sans user
$orphanCigarettes = $cigaretteRepo->findBy(['user' => null]);
if ($orphanCigarettes && $userRepo->count([]) === 1) {
    foreach ($orphanCigarettes as $cig) {
        $cig->setUser($newUser);
    }
    $em->flush();
}
```

**Critères d'acceptation** :
- Un utilisateur ne voit que ses propres données
- Impossible d'accéder aux routes sans authentification
- Mot de passe hashé avec password_hashers natif Symfony
- **Les données existantes sont automatiquement rattachées au premier compte créé**
- Les UserSettings existants sont également migrés

### 1.2 Performance - Pré-calcul des scores

**Fichiers concernés** :
- `src/Entity/DailyScore.php` (nouveau)
- `src/Service/ScoringService.php`
- `src/Repository/DailyScoreRepository.php` (nouveau)

**Tâches** :
- [x] Créer entité DailyScore (date, score, streak, user)
- [x] Modifier ScoringService pour pré-calculer à chaque log
- [x] getTotalScore() = SUM des DailyScore au lieu de recalcul
- [x] getStreak() = lecture directe au lieu de recalcul
- [x] Script de migration pour calculer l'historique

**Métriques cibles** :
- TTFB < 200ms (vs 1.6s actuel, 4.5s projeté à 1 an)
- Complexité O(1) au lieu de O(n²)

### 1.3 Correction vulnérabilité IDOR

**Fichier concerné** : `src/Controller/HomeController.php`

**Tâches** :
- [x] Vérifier ownership sur /delete/{id}
- [x] Retourner 403 si cigarette.user !== currentUser
- [ ] Ajouter tests de sécurité

### 1.4 Corrections bugs critiques

**Fichier concerné** : `src/Service/ScoringService.php`

**Tâches** :
- [x] Ligne 93 : Remplacer `$diff == 0` par `abs($diff) < 0.001`
- [x] Ligne 436 : Valider retour de DateTime::createFromFormat
- [x] Gérer index -1 potentiel sur les tableaux (vérifié : tous les accès sont protégés par des conditions)

---

## Sprint 2 : Refonte Scoring & UX

**Objectif** : Scoring qui mesure vraiment la réduction + feedback immédiat

### 2.1 Refonte du système de scoring ✅

**Problème initial** : Le scoring récompensait la régularité des intervalles, pas la réduction.

**Modèle implémenté** :

```
Score journalier =
  + 20 pts par intervalle >= intervalle_cible (basé sur palier dynamique)
  - 20 pts par cigarette au-delà du palier (malus plafonné à -20)

Bonus potentiels (réalisés en fin de journée) :
  + Bonus réduction vs hier (si moins de clopes)
  + Bonus régularité (si respect des intervalles)
  + Bonus hebdo (si réduction vs semaine précédente)
```

**Fichiers concernés** :
- `src/Service/ScoringService.php`
- `src/Entity/DailyScore.php`

**Tâches** :
- [x] Définir palier dynamique basé sur moyenne 14j
- [x] Calculer score basé sur nb cigarettes vs palier
- [x] Bonus comme "potentiels" (non comptés en temps réel)
- [x] Bonus réalisés uniquement en fin de journée (persistDailyScore)
- [x] Afficher clairement le palier et la progression
- [x] 20 points par intervalle / malus plafonné à -20

**Critères d'acceptation** :
- ✅ Impossible d'avoir bon score avec consommation stable élevée
- ✅ L'utilisateur voit son palier et sa progression
- ✅ Score encourage explicitement la réduction
- ✅ Bonus affichés comme "potentiels" pour éviter confusion en cours de journée

### 2.2 Feedback immédiat après log ✅

**Fichiers concernés** :
- `templates/home/index.html.twig`
- `src/Controller/HomeController.php`

**Tâches** :
- [x] Afficher points gagnés/perdus après chaque log (+20, -5, etc.)
- [x] Animation visuelle du score qui change
- [x] Message contextuel ("Bien joué !" / "Tu peux faire mieux")
- [x] Afficher impact sur le streak
- [x] Rechargement complet de la page après action (cohérence UI)

**Implémenté** : Toast avec points + message d'encouragement, puis rechargement complet après 800ms pour garantir la cohérence de toutes les données affichées.

### 2.3 Amélioration des messages

**Fichier concerné** : `src/Controller/HomeController.php` → extraire vers Service

**Tâches** :
- [x] Extraire getEncouragementMessage() vers MessageService
- [x] Ajouter variété (20+ messages par catégorie)
- [x] Messages contextuels selon :
  - Heure de la journée
  - Progression vs objectif
  - Historique récent (amélioration/régression)
- [x] Ton empathique, jamais culpabilisant

### 2.4 Stats plus parlantes

**Fichiers concernés** :
- `templates/home/stats.html.twig`
- `src/Controller/HomeController.php`

**Nouvelles stats à ajouter** :
- [x] Tendance claire : ↗️ +2 clopes/jour vs semaine dernière
- [x] Projection : "À ce rythme, tu atteins ton objectif dans X jours"
- [x] Comparaison semaine/semaine avec graphique
- [x] "Meilleur jour" et "jour le plus difficile" de la semaine

---

## Sprint 3 : Engagement & Gamification

**Objectif** : Garder l'utilisateur motivé sur le long terme

### 3.1 Palier automatique dynamique

**Fichiers concernés** :
- `src/Service/GoalService.php`
- `src/Repository/CigaretteRepository.php`
- `templates/home/settings.html.twig`

**Tâches** :
- [x] Définir consommation initiale au premier usage
- [x] ~~Paliers automatiques : -1 clope/semaine~~ → Palier dynamique basé sur moyenne 14j
- [x] ~~Option objectif personnalisé~~ → Supprimé (redondant avec palier dynamique)
- [x] Visualisation progression vers objectif
- [x] Célébration quand palier atteint
- [x] Plafond de verre : le palier ne peut jamais remonter (seulement descendre)

**Nouveau modèle de palier** :
```
palier_du_jour = min(palier_précédent, floor(moyenne_14_jours) - 1)
```
- Basé sur la moyenne glissante des 14 derniers jours
- On soustrait 1 pour toujours pousser vers la réduction
- Le palier ne peut jamais augmenter (plafond de verre)

### 3.2 Notifications push

**Fichiers concernés** :
- `public/sw.js`
- `src/Controller/NotificationController.php` (nouveau)

**Tâches** :
- [ ] Implémenter Web Push API côté serveur
- [ ] Notification si pas de log depuis X heures (configurable)
- [ ] Rappel objectif quotidien
- [x] Célébration streak milestone (7j, 30j, etc.)
- [ ] Option désactivation granulaire

### 3.3 Badges et achievements

**Fichiers concernés** :
- `src/Entity/Badge.php` (nouveau)
- `src/Service/BadgeService.php` (nouveau)

**Badges proposés** :
- [x] "Premier pas" : Premier jour complété
- [x] "Une semaine" : 7 jours de streak
- [x] "Économe" : 10€ économisés
- [x] "Marathonien" : 30 jours de streak
- [x] "Réducteur" : -50% vs consommation initiale
- [x] "Champion" : Objectif 0 atteint (7 jours consécutifs)

### 3.4 Export et partage

**Tâches** :
- [x] Export CSV des données
- [x] Export JSON des données
- [ ] Export PDF rapport mensuel
- [ ] Partage anonymisé des achievements
- [ ] Mode "médecin" : rapport pour professionnel santé

---

## Sprint 4 : Accessibilité & Polish

**Objectif** : Conformité WCAG 2.1 AA + UX raffinée

### 4.1 Corrections accessibilité critiques

**Fichiers concernés** :
- `templates/base.html.twig`
- `templates/home/index.html.twig`
- Tous les templates

**Tâches** :
- [x] Remplacer confirm() par modal accessible (role="dialog", aria-modal)
- [x] Ajouter labels sur tous les inputs (customTime notamment)
- [x] Corriger contrastes : #a0a0a0 → #c0c0c0 (ratio 5.3:1 WCAG AA)
- [x] Taille cibles tactiles : 44x44px minimum
- [x] Navigation clavier complète (focus visible, tab order)
- [x] Skip link "Aller au contenu"
- [x] aria-live pour mises à jour dynamiques

### 4.2 Responsive et mobile

**Tâches** :
- [ ] Audit sur vrais devices (iOS Safari, Android Chrome)
- [x] Fix overflow horizontal sur petits écrans
- [x] Touch feedback amélioré
- [x] Gestes swipe pour navigation

### 4.3 Alignement charte graphique Alré Web ✅

**Objectif** : Cohérence visuelle avec la marque alre-web.bzh

**Tâches** :
- [x] Remplacer l'accent rouge (#E94560) par doré Alré (#D5B18A)
- [x] Ajuster les backgrounds pour cohérence
- [x] Mode clair optionnel (bg: #F6F1EA) - toggle fonctionnel dans Réglages
- [x] Unifier les border-radius
- [x] Appliquer les ombres Alré Web
- [x] Taille police ajustable

**Ajouts récents** :
- [x] Navigation avec Settings comme menu à part entière
- [x] Zones de clic agrandies (min 60px de hauteur)
- [x] Retour visuel au tap (.nav-item:active)

### 4.4 Onboarding amélioré

**Fichier concerné** : `templates/home/onboarding.html.twig`

**Tâches** :
- [x] Wizard interactif au lieu de slides
- [x] Définition objectif dès l'onboarding
- [ ] Import depuis autre app (optionnel)
- [x] Premier log guidé

---

## Sprint 5 : Architecture & Dette technique

**Objectif** : Code maintenable et scalable

### 5.1 Refactoring ScoringService

**Fichier concerné** : `src/Service/ScoringService.php` (562 lignes → ~300 lignes)

**Tâches** :
- [x] Extraire IntervalCalculator (calcul intervalles)
- [x] Extraire StreakService (gestion streaks)
- [x] Extraire RankService (calcul rangs)
- [ ] Tests unitaires pour chaque service
- [ ] Documentation des algorithmes

### 5.2 Refactoring HomeController

**Fichier concerné** : `src/Controller/HomeController.php` (539 lignes → ~400 lignes)

**Tâches** :
- [x] Extraire CigaretteService (logique métier)
- [x] Extraire StatsService (calculs statistiques)
- [ ] Controller = routing + validation uniquement
- [ ] Tests fonctionnels

### 5.3 Cache Redis

**Tâches** :
- [ ] Installer et configurer Redis
- [ ] Implémenter cache distribuée
- [ ] Invalidation intelligente
- [ ] Fallback si Redis indisponible

### 5.4 Tests ✅

**Couverture cible** : 80%

**Tâches** :
- [x] Tests unitaires services (PHPUnit) - 96 tests, 168 assertions
  - IntervalCalculatorTest: calculs points et intervalles
  - GoalServiceTest: palier dynamique, plafond, progression
  - MessageServiceTest: messages contextuels
- [x] Tests fonctionnels controllers
  - HomeControllerTest: protection des routes
  - SecurityControllerTest: login, register, redirections
- [ ] Tests E2E parcours critiques (Panther ou Playwright)
- [ ] CI/CD avec tests automatiques

### 5.5 Monitoring

**Tâches** :
- [ ] Intégrer Sentry pour erreurs
- [ ] Métriques performance (temps réponse, requêtes DB)
- [ ] Alerting sur dégradation
- [ ] Dashboard santé application

---

## Sprint 6 : Timeline Réduction ✅

**Objectif** : Remplacer la timeline OMS (arrêt total) par une timeline basée sur la RÉDUCTION

### Décision : Option B - Adapter

**Implémenté** : Nouvelle timeline basée sur le % de réduction, pas le temps depuis l'arrêt.

### Changements effectués

**Avant** (timeline OMS classique) :
- Basée sur le temps depuis l'arrêt total
- Bénéfices irréalistes pour quelqu'un qui réduit sans arrêter
- Sources : OMS (données pour arrêt complet uniquement)

**Après** (timeline réduction) :
- Milestones basés sur le % de réduction : 10%, 25%, 50%, 75%, 90%, 100%
- Bénéfices réels et prouvés par la science :
  - 25% réduction = -20% risque cancer poumon (NCI 2024)
  - 50% réduction = baisse notable du risque pulmonaire
- Encart "Ce que dit la science" avec nuances honnêtes :
  - Cancer poumon : risque proportionnel à la réduction
  - Cardio : pas de seuil "sûr", arrêt total recommandé
- Sources citées : NCI 2024, Global Heart Journal 2024

### Sources scientifiques utilisées
- [NCI 2024](https://dceg.cancer.gov/news-events/news/2024/reducing-smoking-lung-cancer) : -20% risque cancer en passant de 20 à 15 clopes/jour
- [Global Heart Journal 2024](https://pmc.ncbi.nlm.nih.gov/articles/PMC11843939/) : effets cardiovasculaires
- Méta-analyses sur harm reduction

---

## Métriques de succès

### Techniques

| Métrique              | Initial | Actuel     | Cible Final |
|-----------------------|---------|------------|-------------|
| TTFB                  | 1.6s    | < 300ms    | < 100ms     |
| Score Lighthouse Perf | ~60     | ~75        | > 95        |
| Score Lighthouse A11y | ~50     | ~70        | > 95        |
| Couverture tests      | 0%      | 96 tests   | 80%         |

### Produit

| Métrique      | Actuel | Cible  |
|---------------|--------|--------|
| Rétention J7  | ?      | > 40%  |
| Rétention J30 | ?      | > 20%  |
| NPS           | ?      | > 30   |

### Business

| Métrique                     | Projection |
|------------------------------|------------|
| Utilisateurs actifs (6 mois) | 1000+      |
| Taux de réduction moyen      | -30%       |

---

## Dépendances et risques

### Dépendances
1. **Sprint 1 bloque tout** : Sans auth et perf, impossible de déployer pour vrais utilisateurs
2. **Sprint 2.1 (scoring)** : Nécessite validation du nouveau modèle avant implémentation
3. **Sprint 3.2 (push)** : Nécessite certificat VAPID et configuration serveur

### Risques

| Risque                     | Probabilité | Impact  | Mitigation                   |
|----------------------------|-------------|---------|------------------------------|
| Migration données complexe | Moyenne     | Haute   | Script migration + backup    |
| Nouveau scoring rejeté     | Faible      | Haute   | A/B test avec utilisateurs   |
| Perf Redis insuffisante    | Faible      | Moyenne | Fallback PostgreSQL          |

---

## Checklist validation roadmap

- [x] Sprint 1 : Priorités validées ✅ (auth + perf complétés)
- [x] Sprint 2 : Nouveau modèle scoring approuvé ✅ (implémenté + bonus potentiels)
- [x] Sprint 3 : Features gamification validées ✅ (palier dynamique remplace objectif fixe)
- [x] Sprint 4 : Niveau A11y cible confirmé (AA) ✅ (complété)
- [x] Sprint 5 : Architecture cible validée ✅ (188 tests ajoutés)
- [x] Sprint 6 : Timeline OMS → Timeline Réduction ✅
- [x] Métriques de succès approuvées
- [x] Ordre des sprints confirmé

---

## Résumé des changements majeurs (12/12/2024)

### Palier automatique revu
- **Avant** : -1 clope/semaine fixe + objectif personnalisé optionnel
- **Maintenant** : `floor(moyenne_14j) - 1` avec plafond de verre (ne remonte jamais)
- L'objectif personnalisé a été supprimé car redondant

### Scoring amélioré
- **Avant** : Bonuses comptés en temps réel (trompeur en début de journée)
- **Maintenant** : Bonuses affichés comme "potentiels" et réalisés uniquement en fin de journée via `persistDailyScore()`

### UI/UX
- Rechargement complet de la page après actions (cohérence garantie)
- Toggle dark mode corrigé (bug double-clic)
- Settings accessible directement depuis la navigation principale
- Zones de clic agrandies (60px min)

---

## Sprint 7 : Corrections Post-Audit (14/12/2024)

**Objectif** : Corriger les problèmes identifiés par l'audit multi-agents

### 7.1 Sécurité (Score: 7/10 → 9/10)

**Tâches critiques** :
- [x] Supprimer APP_SECRET de `.env.dev` et régénérer
- [x] Ajouter `.env.dev` au `.gitignore` (déjà présent)
- [x] Ajouter filtrage par user dans `WakeUpRepository::findByDate()`
- [x] Configurer `login_throttling` (anti-bruteforce)

**Tâches moyennes** :
- [x] Logger les exceptions dans les controllers
- [x] Headers de sécurité HTTP (SecurityHeadersSubscriber - nelmio incompatible Sf8)
- [x] Ajouter contrainte complexité mot de passe (PasswordStrength)

### 7.2 Performance (Score: 6/10 → 9/10)

**Tâches critiques** :
- [x] Fixer `BadgeService::checkZeroDay()` - remplacer boucle par GROUP BY
- [x] Fixer `BadgeService::checkChampion()` - remplacer boucle par GROUP BY
- [x] Utiliser `getStreakOptimized()` au lieu de `getStreak()` dans ScoringService

**Tâches moyennes** :
- [x] Externaliser CSS dans `public/css/app.css` (~500 lignes)
- [x] Externaliser JS dans `public/js/app.js` (~300 lignes)
- [x] Implémenter cache Symfony pour données chaudes (TTL 60s)

### 7.3 Qualité Code (Score: 7.5/10 → 9/10)

**Tâches critiques** :
- [x] Supprimer duplication `calculateDailyScoreFromData()` (centralisé dans IntervalCalculator)
- [x] Utiliser `CigaretteService::parseTimeData()` dans HomeController (au lieu de dupliquer)
- [x] Centraliser calcul économies dans StatsService (BadgeService délègue à StatsService)

**Tâches moyennes** :
- [x] Supprimer méthodes @deprecated de ScoringService
- [x] Transformer méthodes statiques IntervalCalculator en méthodes d'instance
- [x] Créer constantes pour valeurs magiques (ScoringConstants.php)

### 7.4 UX (Score: 7.5/10 → 8.5/10)

**Tâches critiques** :
- [x] ~~Remplacer refresh complet par update dynamique du DOM après logging~~ (gardé refresh complet pour cohérence UI)
- [x] Ajouter bouton "Plus tard" visible dans modal réveil
- [x] Désactiver bouton principal 3 sec après log (anti double-clic)

**Tâches moyennes** :
- [x] Accordéon repliable pour "Détail des points"
- [x] Boutons rapides dans modal rétroactif ("Il y a 15 min", "Il y a 30 min", "Il y a 1h")
- [x] Tabs dans Stats : "Vue d'ensemble" / "Graphiques" / "Santé"
- [x] Toast "Annuler" temporaire (5 sec) après logging avec bouton d'annulation

### 7.5 Accessibilité (Score: 6.5/10 → 8/10)

**Tâches critiques** :
- [x] Wrapper tous les emojis avec `<span aria-hidden="true">`
- [x] Implémenter focus trap dans les modales
- [x] Ajouter `aria-live="polite"` sur section score (déjà présent sur toast)

**Tâches moyennes** :
- [x] Corriger contraste accent (#E8C9A0 = 4.5:1+)
- [x] Alternative textuelle pour graphiques (tableaux cachés pour lecteurs d'écran)
- [x] Améliorer visibilité skip link (CSS ajouté)

---

## Scores cibles après Sprint 7

| Domaine | Avant | Après | Delta |
|---------|-------|-------|-------|
| Code | 7.5/10 | 9/10 | +1.5 |
| Sécurité | 7/10 | 9/10 | +2 |
| UX | 7.5/10 | 8.5/10 | +1 |
| Performance | 6/10 | 9/10 | +3 |
| A11y | 6.5/10 | 8/10 | +1.5 |
| **Moyenne** | **6.9/10** | **8.7/10** | **+1.8** |

---

> **Pour toute évolution majeure** : relancer un audit multi-agents (code-reviewer, security-auditor, ux-reviewer, performance-expert, a11y-auditor) pour identifier les régressions et opportunités d'amélioration.

---

## Sprint 8 : Consolidation & Engagement (Audit 14/12/2024)

**Objectif** : Atteindre 8.5/10 en corrigeant les défauts critiques identifiés

### Résultats audit multi-agents (14/12/2024)

| Agent | Score | Points clés |
|-------|-------|-------------|
| Product Owner | 7/10 | Manque dimension émotionnelle, pas de "Je résiste" |
| Code Reviewer | 7.5/10 | Exceptions génériques, tests ScoringService manquants |
| Security Auditor | 7.5/10 | APP_SECRET vide en .env, CSP unsafe-inline |
| UX Reviewer | 7.5/10 | Surcharge cognitive home, bouton non sticky |
| Performance Expert | 7.5/10 | Cache configuré mais non utilisé |
| A11y Auditor | 7/10 | Graphiques sans alternatives textuelles liées |

### 8.1 Sécurité (P0 - Bloquant)

**Tâches critiques** :
- [x] Définir APP_SECRET réel en production (variable d'environnement serveur) ✅ Déjà fait
- [x] Remplacer CSP `'unsafe-inline'` par nonces pour scripts
- [x] Ajouter header HSTS (`Strict-Transport-Security`)

### 8.2 Accessibilité (P0 - Bloquant)

**Tâches critiques** :
- [x] Heatmap : afficher valeur numérique en plus de la couleur (WCAG 1.4.1)
- [x] Tabs : ajouter navigation flèches clavier (ArrowLeft/ArrowRight)
- [x] Graphiques : lier `aria-labelledby` aux tableaux sr-only existants
- [x] Badges : ajouter `aria-label` avec description complète

**Tâches mineures** :
- [x] Modales : remplacer `<h3>` par `<h2>` pour hiérarchie correcte
- [x] Delete button : ajouter `aria-label="Supprimer la cigarette de HH:MM"` ✅ Déjà fait
- [ ] Focus initial modal confirmation sur bouton primaire

### 8.3 Qualité Code (P1 - Important)

**Tâches** :
- [x] Remplacer `catch (\Exception $e)` par exceptions spécifiques dans controllers
- [x] Ajouter tests unitaires ScoringService (calculateDailyScore, persistDailyScore)
- [ ] Vérifier et résoudre potentielle dépendance circulaire BadgeService ↔ ScoringService

### 8.4 Performance (P1 - Important)

**Tâches** :
- [x] Activer le cache Symfony sur données chaudes (stats, badges) - StatsService::getFullStats()
- [ ] Ajouter HTTP cache headers sur assets statiques (Cache-Control: max-age)
- [ ] Configurer compression gzip/brotli sur serveur

### 8.5 Engagement & Produit (P1 - Important)

**Tâches** :
- [x] Bouton "Je résiste" : valoriser les moments où l'utilisateur résiste à l'envie
- [ ] Première journée : célébration/onboarding renforcé
- [ ] Message de bienvenue personnalisé au premier log

**Tâches futures (Sprint 9+)** :
- [ ] Notifications push configurables
- [ ] Partage social des achievements

### 8.6 UX (P2 - Souhaitable)

**Tâches** :
- [ ] Bouton principal sticky/flottant en bas d'écran
- [ ] Réduire métriques affichées sur home (progressive disclosure)
- [ ] Allonger durée toast "Annuler" à 8 secondes

---

## Scores cibles après Sprint 8

| Domaine | Actuel | Cible | Delta |
|---------|--------|-------|-------|
| Product | 7/10 | 8/10 | +1 |
| Code | 7.5/10 | 8.5/10 | +1 |
| Sécurité | 7.5/10 | 9/10 | +1.5 |
| UX | 7.5/10 | 8.5/10 | +1 |
| Performance | 7.5/10 | 8.5/10 | +1 |
| A11y | 7/10 | 8.5/10 | +1.5 |
| **Moyenne** | **7.3/10** | **8.5/10** | **+1.2** |
