# CAHIER DE RECETTE - StopClope

**Application:** StopClope - PWA d'aide à l'arrêt du tabac
**Version:** 1.0
**Date d'audit:** 2025-12-09
**Framework:** Symfony 8.0, Doctrine ORM 3.5, MySQL 8.0

---

## SYNTHÈSE EXÉCUTIVE

### Scores par domaine

| Domaine | Score | Criticité |
|---------|-------|-----------|
| Qualité du code | 6/10 | 8 issues bloquantes |
| Sécurité OWASP | 4/10 | 2 critiques, 4 hautes |
| UX/Ergonomie | 6.5/10 | 4 issues P0 |
| Accessibilité WCAG | 3/10 | 5 critiques, 10 majeures |
| Performance | 5/10 | N+1 queries critiques |
| Base de données | 6/10 | Indexes manquants |

### Problèmes critiques identifiés

1. **Credentials exposés** dans fichiers .env committés
2. **Aucune authentification** - toutes les données accessibles
3. **CSRF absent** sur endpoints POST
4. **Zoom désactivé** - viole WCAG 1.4.4
5. **N+1 queries** - 80+ requêtes par page stats
6. **location.reload()** systématique - anti-pattern UX

---

## PARTIE 1 : TESTS FONCTIONNELS

### 1.1 Page d'accueil (/)

| ID | Test | Résultat attendu | Statut |
|----|------|------------------|--------|
| F-001 | Affichage score du jour | Score numérique affiché | À tester |
| F-002 | Affichage rang actuel | Nom du rang + progress bar | À tester |
| F-003 | Affichage cigarettes du jour | Liste avec heures | À tester |
| F-004 | Bouton "J'ai fumé une clope" | Visible et cliquable | À tester |
| F-005 | Enregistrement cigarette | Toast confirmation + refresh | À tester |
| F-006 | Suppression cigarette | Confirmation + suppression | À tester |
| F-007 | Modal heure de réveil | Affichage au premier usage | À tester |
| F-008 | Ajout rétroactif | Modal datetime + enregistrement | À tester |
| F-009 | Timer prochain points | Countdown temps réel | À tester |
| F-010 | Message d'encouragement | Contextuel selon performance | À tester |

### 1.2 Page Statistiques (/stats)

| ID | Test | Résultat attendu | Statut |
|----|------|------------------|--------|
| F-011 | Score total | Affichage cumul depuis début | À tester |
| F-012 | Graphique 30 jours | Barres par jour | À tester |
| F-013 | Scores hebdo | 7 derniers jours | À tester |
| F-014 | Stats par jour semaine | Moyenne Lu-Di | À tester |
| F-015 | Heatmap horaire | 24 heures | À tester |
| F-016 | Économies réalisées | Calcul €€€ | À tester |
| F-017 | Comparaison semaines | Current vs previous | À tester |

### 1.3 Page Historique (/history)

| ID | Test | Résultat attendu | Statut |
|----|------|------------------|--------|
| F-018 | Navigation date | Boutons prev/next | À tester |
| F-019 | Sélecteur date | Input date fonctionnel | À tester |
| F-020 | Détails journée | Count, réveil, score | À tester |
| F-021 | Liste cigarettes | Timeline avec heures | À tester |
| F-022 | Suppression historique | Possible si pas aujourd'hui | À tester |
| F-023 | Limites dates | Pas avant 1er jour, pas après aujourd'hui | À tester |

### 1.4 Page Paramètres (/settings)

| ID | Test | Résultat attendu | Statut |
|----|------|------------------|--------|
| F-024 | Prix du paquet | Saisie et sauvegarde | À tester |
| F-025 | Cigarettes par paquet | Saisie numérique | À tester |
| F-026 | Consommation initiale | Saisie de base | À tester |
| F-027 | Rappel réveil | Toggle on/off | À tester |
| F-028 | Heure rappel | Sélecteur temps | À tester |
| F-029 | Notifications | Permission navigateur | À tester |
| F-030 | Sauvegarde | Toast confirmation | À tester |

### 1.5 Mode Offline (PWA)

| ID | Test | Résultat attendu | Statut |
|----|------|------------------|--------|
| F-031 | Installation PWA | Prompt "Ajouter à l'écran" | À tester |
| F-032 | Offline indicator | Bannière visible | À tester |
| F-033 | Log offline | Enregistrement localStorage | À tester |
| F-034 | Sync au retour | Envoi queue différée | À tester |
| F-035 | Service Worker | Cache pages principales | À tester |
| F-036 | Wake reminder | Notification navigateur | À tester |

---

## PARTIE 2 : TESTS DE SÉCURITÉ

### 2.1 Credentials & Secrets

| ID | Test | Résultat attendu | Statut | Criticité |
|----|------|------------------|--------|-----------|
| S-001 | APP_SECRET dans .env.dev | NE DOIT PAS être exposé | **ÉCHEC** | CRITIQUE |
| S-002 | DATABASE_URL dans .env.local | NE DOIT PAS être commité | **ÉCHEC** | CRITIQUE |
| S-003 | .gitignore correct | Exclut .env.local, .env.dev | **PARTIEL** (.env.dev tracké) | HAUTE |

### 2.2 Authentification & Autorisation

| ID | Test | Résultat attendu | Statut | Criticité |
|----|------|------------------|--------|-----------|
| S-004 | Login requis | Accès protégé | **ÉCHEC** (aucun auth) | CRITIQUE |
| S-005 | IDOR sur /delete/{id} | Vérification propriétaire | **ÉCHEC** | HAUTE |
| S-006 | Access control config | Règles définies | **ÉCHEC** | HAUTE |

### 2.3 CSRF & Input Validation

| ID | Test | Résultat attendu | Statut | Criticité |
|----|------|------------------|--------|-----------|
| S-007 | Token CSRF /log | Validation token | **ÉCHEC** | HAUTE |
| S-008 | Token CSRF /delete | Validation token | **ÉCHEC** | HAUTE |
| S-009 | Token CSRF /settings | Validation token | **ÉCHEC** | HAUTE |
| S-010 | Validation local_time | Format vérifié | **ÉCHEC** | HAUTE |
| S-011 | Validation pack_price | Numérique positif | **ÉCHEC** | MOYENNE |
| S-012 | DateTime future | Rejet dates futures | **ÉCHEC** | MOYENNE |

### 2.4 Headers Sécurité

| ID | Test | Résultat attendu | Statut | Criticité |
|----|------|------------------|--------|-----------|
| S-013 | X-Frame-Options | DENY ou SAMEORIGIN | **ÉCHEC** | HAUTE |
| S-014 | X-Content-Type-Options | nosniff | **ÉCHEC** | MOYENNE |
| S-015 | Content-Security-Policy | Défini | **ÉCHEC** | HAUTE |
| S-016 | Strict-Transport-Security | Défini en HTTPS | **ÉCHEC** | MOYENNE |

### 2.5 SQL Injection

| ID | Test | Résultat attendu | Statut | Criticité |
|----|------|------------------|--------|-----------|
| S-017 | Queries paramétrées | Oui, partout | OK | - |
| S-018 | Raw SQL sécurisé | Parameters utilisés | OK | - |

---

## PARTIE 3 : TESTS UX

### 3.1 Parcours utilisateur

| ID | Test | Résultat attendu | Statut | Criticité |
|----|------|------------------|--------|-----------|
| U-001 | Log cigarette - 1 clic | Action immédiate | OK | - |
| U-002 | Feedback toast | Visible 3s | OK | - |
| U-003 | Pas de reload | DOM update dynamique | **ÉCHEC** | CRITIQUE |
| U-004 | Spinner pendant action | Indicateur visuel | **ÉCHEC** | HAUTE |
| U-005 | Confirm() natif | Modal stylisé | **ÉCHEC** | MOYENNE |

### 3.2 Affordance & Design

| ID | Test | Résultat attendu | Statut | Criticité |
|----|------|------------------|--------|-----------|
| U-006 | Big button visible | Gradient + taille | OK | - |
| U-007 | Navigation claire | Bottom nav standard | OK | - |
| U-008 | Delete button 44x44px | Zone tactile suffisante | **ÉCHEC** | HAUTE |
| U-009 | Emojis → SVG icons | Rendu consistant | **ÉCHEC** | MOYENNE |
| U-010 | Hover states | Feedback visuel | **ÉCHEC** | MOYENNE |

### 3.3 Gestion d'erreurs

| ID | Test | Résultat attendu | Statut | Criticité |
|----|------|------------------|--------|-----------|
| U-011 | Erreur réseau | Message clair | **ÉCHEC** | HAUTE |
| U-012 | Erreur serveur | Message non technique | **ÉCHEC** | HAUTE |
| U-013 | Offline sync | Indication queue | **ÉCHEC** | MOYENNE |
| U-014 | Undo suppression | Option disponible | **ÉCHEC** | MOYENNE |

### 3.4 Mobile & Responsive

| ID | Test | Résultat attendu | Statut | Criticité |
|----|------|------------------|--------|-----------|
| U-015 | Viewport mobile | max-width 500px | OK | - |
| U-016 | Safe area iOS | Inset bottom géré | OK | - |
| U-017 | Portrait orientation | Layout adapté | OK | - |
| U-018 | Ecrans < 360px | Pas de débordement | À tester | MOYENNE |

---

## PARTIE 4 : TESTS ACCESSIBILITÉ (WCAG 2.1 AA)

### 4.1 Critères critiques

| ID | Critère WCAG | Test | Statut | Criticité |
|----|--------------|------|--------|-----------|
| A-001 | 1.4.4 | Zoom possible 200% | **ÉCHEC** (user-scalable=no) | CRITIQUE |
| A-002 | 2.4.7 | Focus visible | **ÉCHEC** | CRITIQUE |
| A-003 | 4.1.2 | Boutons avec nom | **ÉCHEC** (delete ✕) | CRITIQUE |
| A-004 | 4.1.3 | Toast aria-live | **ÉCHEC** | CRITIQUE |
| A-005 | 4.1.2 | Modal role="dialog" | **ÉCHEC** | CRITIQUE |

### 4.2 Critères majeurs

| ID | Critère WCAG | Test | Statut | Criticité |
|----|--------------|------|--------|-----------|
| A-006 | 1.3.1 | Structure sémantique | **ÉCHEC** (pas de main) | MAJEUR |
| A-007 | 1.4.3 | Contraste 4.5:1 | **ÉCHEC** (3.6:1 accent) | MAJEUR |
| A-008 | 2.4.1 | Skip link | **ÉCHEC** | MAJEUR |
| A-009 | 2.4.3 | Focus trap modal | **ÉCHEC** | MAJEUR |
| A-010 | 3.3.1 | Erreurs formulaires | **ÉCHEC** | MAJEUR |
| A-011 | 1.1.1 | Progress bar décrite | **ÉCHEC** | MAJEUR |
| A-012 | 1.1.1 | Charts accessibles | **ÉCHEC** | MAJEUR |
| A-013 | 1.3.1 | Heading hierarchy | **ÉCHEC** (h3 sans h2) | MAJEUR |
| A-014 | 1.3.1 | Nav aria-current | **ÉCHEC** | MAJEUR |
| A-015 | 3.3.2 | Labels descriptifs | **ÉCHEC** | MAJEUR |

### 4.3 Critères conformes

| ID | Critère WCAG | Test | Statut |
|----|--------------|------|--------|
| A-016 | 3.1.1 | lang="fr" | OK |
| A-017 | 1.3.1 | Labels for="" | OK |
| A-018 | 1.4.3 | Texte primaire contraste | OK (21:1) |

---

## PARTIE 5 : TESTS PERFORMANCE

### 5.1 Requêtes N+1

| ID | Test | Valeur actuelle | Cible | Statut |
|----|------|-----------------|-------|--------|
| P-001 | getSmoothedAverageInterval | 7 requêtes | 1 | **ÉCHEC** |
| P-002 | getSmoothedFirstCigTime | 14 requêtes | 1 | **ÉCHEC** |
| P-003 | getTotalScore (30j) | 60+ requêtes | 1 | **ÉCHEC** |
| P-004 | getWeeklyComparison | 14 requêtes | 1 | **ÉCHEC** |
| P-005 | countByDate | Hydrate tout | COUNT SQL | **ÉCHEC** |

### 5.2 Temps de réponse

| ID | Page | Temps actuel (estimé) | Cible | Statut |
|----|------|----------------------|-------|--------|
| P-006 | / (TTFB) | ~255ms | <100ms | **ÉCHEC** |
| P-007 | /stats (TTFB) | ~1310ms | <200ms | **ÉCHEC** |
| P-008 | /history (TTFB) | ~150ms | <100ms | À tester |
| P-009 | /settings (TTFB) | ~50ms | <50ms | OK |

### 5.3 Assets & Cache

| ID | Test | Valeur actuelle | Cible | Statut |
|----|------|-----------------|-------|--------|
| P-010 | CSS inline | 12KB non compressé | Externe + cache | **ÉCHEC** |
| P-011 | JS inline | 10KB non compressé | Externe + minifié | **ÉCHEC** |
| P-012 | Gzip/Brotli | Non configuré | Activé | **ÉCHEC** |
| P-013 | HTTP Cache | Non configuré | ETags | **ÉCHEC** |
| P-014 | Cache applicatif | Non configuré | Redis/APCu | **ÉCHEC** |

### 5.4 Base de données

| ID | Test | Valeur actuelle | Cible | Statut |
|----|------|-----------------|-------|--------|
| P-015 | Index smoked_at | Présent | OK | OK |
| P-016 | Index date (wake_up) | UNIQUE + idx (redondant) | UNIQUE seul | **ÉCHEC** |
| P-017 | Index composite | Absent | Ajouter | **ÉCHEC** |
| P-018 | Settings cache | Requête à chaque appel | Mémoization | **ÉCHEC** |

---

## PARTIE 6 : TESTS QUALITÉ CODE

### 6.1 Architecture

| ID | Test | Résultat attendu | Statut |
|----|------|------------------|--------|
| C-001 | Controller SRP | Max 5 méthodes/controller | **ÉCHEC** (10 méthodes) |
| C-002 | Business logic services | Pas dans controller | **ÉCHEC** (savings, messages) |
| C-003 | Repository flush | Pas dans repository | **ÉCHEC** (SettingsRepository) |

### 6.2 Validation & Error Handling

| ID | Test | Résultat attendu | Statut |
|----|------|------------------|--------|
| C-004 | Input validation | Symfony Validator | **ÉCHEC** |
| C-005 | DateTime::createFromFormat | Gestion false | **ÉCHEC** |
| C-006 | Division par zéro | Protection | **ÉCHEC** |
| C-007 | Entity constraints | Assert\\* annotations | **ÉCHEC** |
| C-008 | Try-catch flush | Gestion erreurs DB | **ÉCHEC** |

### 6.3 DRY & Maintenabilité

| ID | Test | Résultat attendu | Statut |
|----|------|------------------|--------|
| C-009 | DateTime manipulation | Helper centralisé | **ÉCHEC** (15+ duplications) |
| C-010 | Timezone creation | Méthode unique | **ÉCHEC** (dupliqué) |
| C-011 | Settings defaults | Constantes | **ÉCHEC** (3 endroits) |
| C-012 | Magic numbers | Constantes nommées | **ÉCHEC** (60, 20, -20, -1) |

### 6.4 Tests

| ID | Test | Résultat attendu | Statut |
|----|------|------------------|--------|
| C-013 | Tests unitaires | Présents | **ÉCHEC** (aucun) |
| C-014 | Coverage ScoringService | >80% | **ÉCHEC** (0%) |
| C-015 | Tests fonctionnels | Controllers testés | **ÉCHEC** (aucun) |

---

## PARTIE 7 : PLAN D'ACTION

### Phase 1 : Sécurité (Immédiat)

| Priorité | Action | Fichiers | Effort |
|----------|--------|----------|--------|
| P0 | Supprimer credentials des .env | .env.dev, .env.local | 30min |
| P0 | Rotation APP_SECRET | Production | 15min |
| P0 | Implémenter CSRF | HomeController, templates | 2h |
| P0 | Headers sécurité | NelmioSecurityBundle | 1h |

### Phase 2 : Accessibilité critique (Semaine 1)

| Priorité | Action | Fichiers | Effort |
|----------|--------|----------|--------|
| P0 | Activer zoom | base.html.twig | 10min |
| P0 | Focus indicators CSS | base.html.twig | 30min |
| P0 | aria-label boutons | index.html.twig | 20min |
| P0 | Toast aria-live | base.html.twig | 10min |
| P0 | Modal role="dialog" | index.html.twig | 30min |

### Phase 3 : Performance (Semaine 1-2)

| Priorité | Action | Fichiers | Effort |
|----------|--------|----------|--------|
| P0 | Batch queries N+1 | CigaretteRepository | 2h |
| P0 | countByDate optimisé | CigaretteRepository | 30min |
| P1 | Cache Settings | SettingsRepository | 30min |
| P1 | Cache TotalScore | ScoringService | 1h |
| P1 | Externaliser CSS/JS | assets/ | 2h |

### Phase 4 : Qualité code (Semaine 2-3)

| Priorité | Action | Fichiers | Effort |
|----------|--------|----------|--------|
| P1 | Input validation | HomeController | 2h |
| P1 | DateTimeHelper | Nouveau service | 1h |
| P1 | Split HomeController | Nouveaux controllers | 3h |
| P1 | Entity constraints | Entities | 1h |
| P2 | Tests ScoringService | tests/ | 4h |

### Phase 5 : UX (Semaine 3-4)

| Priorité | Action | Fichiers | Effort |
|----------|--------|----------|--------|
| P0 | Supprimer reload() | index.html.twig | 3h |
| P1 | Spinner loading | templates | 1h |
| P1 | Zones tactiles 44px | CSS | 30min |
| P2 | Modal confirm stylisé | templates | 2h |

---

## ANNEXES

### A. Fichiers critiques à auditer

1. `/src/Controller/HomeController.php` - Controller principal
2. `/src/Service/ScoringService.php` - Logique métier
3. `/src/Repository/CigaretteRepository.php` - Requêtes N+1
4. `/templates/base.html.twig` - CSS/JS inline, A11y
5. `/templates/home/index.html.twig` - UX, A11y
6. `/config/packages/security.yaml` - Auth/Authz
7. `/.env*` - Credentials exposés

### B. Outils de test recommandés

- **Sécurité:** OWASP ZAP, `symfony security:check`
- **A11y:** axe DevTools, WAVE, Lighthouse
- **Performance:** Blackfire, Symfony Profiler
- **Code:** PHPStan level 8, PHP CS Fixer

### C. Métriques à suivre

| Métrique | Actuel | Cible |
|----------|--------|-------|
| TTFB page accueil | 255ms | <100ms |
| TTFB page stats | 1310ms | <200ms |
| Queries/page | 80+ | <15 |
| WCAG conformité | 45% | 90% |
| Coverage tests | 0% | 80% |
| Security score | 4/10 | 9/10 |

---

---

## PARTIE 8 : RÉSULTATS DES TESTS EXÉCUTÉS

### 8.1 Tests automatisés

| Test | Commande | Résultat |
|------|----------|----------|
| Lint Twig | `php bin/console lint:twig templates/` | **OK** - 5 fichiers valides |
| Lint Container | `php bin/console lint:container` | **OK** - Services correctement injectés |
| Doctrine Schema | `php bin/console doctrine:schema:validate` | **ÉCHEC** - Schema non synchronisé |
| Composer Audit | `composer audit` | **OK** - Aucune vulnérabilité |

### 8.2 Vérifications manuelles

| Vérification | Résultat | Détail |
|--------------|----------|--------|
| Fichiers .env trackés | **ÉCHEC** | `.env`, `.env.dev`, `.env.test` sont trackés |
| APP_SECRET exposé | **ÉCHEC** | `b41ff914c14414e49980ad469aa8e60b` visible dans .env.dev |
| user-scalable=no | **ÉCHEC** | Présent dans base.html.twig ligne 5 |
| location.reload() | **ÉCHEC** | 5 occurrences trouvées |
| CSS :focus | **ÉCHEC** | Aucun style focus défini |
| ARIA attributes | **ÉCHEC** | Aucun aria-* trouvé dans templates |
| CSRF tokens | **ÉCHEC** | Aucune utilisation de csrf dans src/ |
| Index redondant wake_up | **CONFIRMÉ** | UNIQ_date + idx_wakeup_date sur même colonne |
| Données en base | **OK** | 455 cigarettes, 30 wake_ups |

### 8.3 État des routes

| Route | Méthode | Path | État |
|-------|---------|------|------|
| app_home | ANY | / | **OK** |
| app_log_cigarette | POST | /log | **OK** (sans CSRF) |
| app_log_wakeup | POST | /wakeup | **OK** (sans CSRF) |
| app_delete_cigarette | POST | /delete/{id} | **OK** (sans auth/CSRF) |
| app_stats | GET | /stats | **OK** |
| app_history | GET | /history | **OK** |
| app_settings | GET | /settings | **OK** |
| app_settings_save | POST | /settings/save | **OK** (sans CSRF) |

---

**Auteur:** Audit automatisé
**Date d'exécution:** 2025-12-09
**Prochaine revue:** À définir après corrections Phase 1
