<?php

namespace App\Service;

use App\Entity\Cigarette;
use App\Entity\User;
use App\Entity\WakeUp;
use App\Repository\CigaretteRepository;
use App\Repository\WakeUpRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

class DayBoundaryService
{
    public function __construct(
        private WakeUpRepository $wakeUpRepository,
        private CigaretteRepository $cigaretteRepository,
        private EntityManagerInterface $entityManager,
        private ScoringService $scoringService,
        private Security $security
    ) {}

    /**
     * Détermine la date effective (journée logique) d'une cigarette.
     *
     * Algorithme (attribution immédiate) :
     * 1. Trouver le réveil du même jour calendaire que smokedAt
     * 2. Si réveil existe ET smokedAt >= heure de réveil → effective_date = jour calendaire
     * 3. Si réveil existe ET smokedAt < heure de réveil → effective_date = veille
     * 4. Si PAS de réveil aujourd'hui → effective_date = veille
     * 5. Fallback (aucun réveil dans le système) → effective_date = date calendaire
     */
    public function resolveEffectiveDate(\DateTimeInterface $smokedAt, ?User $user = null): \DateTime
    {
        $calendarDate = (clone $smokedAt)->setTime(0, 0, 0);
        $yesterday = (clone $calendarDate)->modify('-1 day');

        $wakeUp = $this->wakeUpRepository->findByDate($calendarDate);

        if ($wakeUp) {
            $wakeDateTime = $wakeUp->getWakeDateTime();
            if ($smokedAt >= $wakeDateTime) {
                return $calendarDate;
            }
            return $yesterday;
        }

        // Pas de réveil aujourd'hui : vérifier s'il existe des réveils dans le système
        $yesterdayWakeUp = $this->wakeUpRepository->findByDate($yesterday);
        if ($yesterdayWakeUp) {
            // Il y a des données de réveil → attribution immédiate à la veille
            return $yesterday;
        }

        // Aucun réveil récent → fallback sur la date calendaire
        return $calendarDate;
    }

    /**
     * Recalcule les effective_date des cigarettes après le log d'un réveil.
     *
     * Quand un réveil est loggué pour le jour J à l'heure H :
     * - Les clopes du jour calendaire J avec smokedAt >= H et effectiveDate = J-1
     *   doivent passer à effectiveDate = J
     * - Les clopes du jour calendaire J avec smokedAt < H et effectiveDate = J
     *   doivent passer à effectiveDate = J-1
     */
    public function recalculateForWakeUp(WakeUp $wakeUp): void
    {
        $date = (clone $wakeUp->getDate())->setTime(0, 0, 0);
        $yesterday = (clone $date)->modify('-1 day');
        $wakeDateTime = $wakeUp->getWakeDateTime();

        $user = $this->security->getUser();

        // Chercher toutes les clopes du jour calendaire (entre 00:00 et 23:59)
        $dayStart = (clone $date)->setTime(0, 0, 0);
        $dayEnd = (clone $date)->setTime(23, 59, 59);

        $qb = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Cigarette::class, 'c')
            ->where('c.smokedAt >= :dayStart')
            ->andWhere('c.smokedAt <= :dayEnd')
            ->setParameter('dayStart', $dayStart)
            ->setParameter('dayEnd', $dayEnd);

        if ($user instanceof User) {
            $qb->andWhere('c.user = :user')->setParameter('user', $user);
        } else {
            $qb->andWhere('c.user IS NULL');
        }

        $cigarettes = $qb->getQuery()->getResult();

        $changed = false;
        foreach ($cigarettes as $cig) {
            $newEffectiveDate = ($cig->getSmokedAt() >= $wakeDateTime) ? $date : $yesterday;
            $currentEffective = $cig->getEffectiveDate();

            if (!$currentEffective || $currentEffective->format('Y-m-d') !== $newEffectiveDate->format('Y-m-d')) {
                $cig->setEffectiveDate(clone $newEffectiveDate);
                $changed = true;
            }
        }

        if ($changed) {
            $this->entityManager->flush();

            // Recalculer les scores des 2 jours affectés
            $this->scoringService->invalidateCache();
            $this->scoringService->persistDailyScore($date);
            $this->scoringService->persistDailyScore($yesterday);
        }
    }

    /**
     * Retourne les bornes temporelles réelles d'une journée logique.
     * start = heure de réveil du jour, end = heure de réveil du lendemain - 1s
     *
     * @return array{0: \DateTime, 1: \DateTime} [start, end]
     */
    public function getDayBoundaries(\DateTimeInterface $date): array
    {
        $dateNormalized = (clone $date)->setTime(0, 0, 0);
        $nextDate = (clone $dateNormalized)->modify('+1 day');

        $wakeUp = $this->wakeUpRepository->findByDate($dateNormalized);
        $nextWakeUp = $this->wakeUpRepository->findByDate($nextDate);

        if ($wakeUp) {
            $start = clone $wakeUp->getWakeDateTime();
        } else {
            $start = (clone $dateNormalized)->setTime(0, 0, 0);
        }

        if ($nextWakeUp) {
            $end = (clone $nextWakeUp->getWakeDateTime())->modify('-1 second');
        } else {
            // Pas de réveil le lendemain → étendre jusqu'à demain 23:59:59
            $end = (clone $nextDate)->setTime(23, 59, 59);
        }

        return [$start, $end];
    }
}
