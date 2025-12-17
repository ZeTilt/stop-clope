<?php

namespace App\Command;

use App\Constants\ScoringConstants;
use App\Entity\DailyScore;
use App\Entity\User;
use App\Repository\DailyScoreRepository;
use App\Repository\UserRepository;
use App\Service\IntervalCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:recalculate-scores',
    description: 'Recalcule et persiste les scores quotidiens pour tous les utilisateurs',
)]
class RecalculateScoresCommand extends Command
{
    public function __construct(
        private UserRepository $userRepository,
        private DailyScoreRepository $dailyScoreRepository,
        private IntervalCalculator $intervalCalculator,
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('user', 'u', InputOption::VALUE_OPTIONAL, 'ID utilisateur spécifique')
            ->addOption('days', 'd', InputOption::VALUE_OPTIONAL, 'Nombre de jours à recalculer', 365)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $userId = $input->getOption('user');
        $days = (int) $input->getOption('days');

        if ($userId) {
            $users = [$this->userRepository->find($userId)];
            if (!$users[0]) {
                $io->error("Utilisateur {$userId} non trouvé");
                return Command::FAILURE;
            }
        } else {
            $users = $this->userRepository->findAll();
        }

        $io->title('Recalcul des scores quotidiens');
        $io->info(sprintf('Traitement de %d utilisateur(s) sur %d jours', count($users), $days));

        foreach ($users as $user) {
            $this->processUser($user, $days, $io);
        }

        $io->success('Recalcul terminé !');

        return Command::SUCCESS;
    }

    private function processUser(User $user, int $days, SymfonyStyle $io): void
    {
        $io->section("Utilisateur: {$user->getEmail()}");

        // Trouver la première cigarette de cet utilisateur
        $firstCig = $this->entityManager->createQueryBuilder()
            ->select('MIN(c.smokedAt)')
            ->from('App\Entity\Cigarette', 'c')
            ->where('c.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        if (!$firstCig) {
            $io->warning('Aucune cigarette trouvée');
            return;
        }

        $startDate = new \DateTime($firstCig);
        $endDate = new \DateTime('yesterday');
        $endDate->setTime(23, 59, 59);

        // Limiter au nombre de jours demandé
        $maxStart = (new \DateTime())->modify("-{$days} days");
        if ($startDate < $maxStart) {
            $startDate = $maxStart;
        }

        // Charger TOUTES les données en 2 requêtes (efficace)
        $allCigarettes = $this->loadAllCigarettes($user, $startDate, $endDate);
        $allWakeups = $this->loadAllWakeups($user, $startDate, $endDate);

        $currentDate = clone $startDate;
        $currentDate->setTime(0, 0, 0);

        $streak = 0;
        $bestStreak = 0;
        $processed = 0;

        $io->progressStart((int) $currentDate->diff($endDate)->days + 1);

        while ($currentDate <= $endDate) {
            $dateStr = $currentDate->format('Y-m-d');
            $cigs = $allCigarettes[$dateStr] ?? [];
            $wakeUp = $allWakeups[$dateStr] ?? null;

            // Calculer le score de base via IntervalCalculator (pas de dépendance au contexte de sécurité)
            $baseScore = $this->intervalCalculator->calculateDailyScoreFromData(
                $currentDate,
                $allCigarettes,
                $allWakeups
            );

            // Calculer les bonus
            $yesterdayStr = (clone $currentDate)->modify('-1 day')->format('Y-m-d');
            $yesterdayCigs = $allCigarettes[$yesterdayStr] ?? [];
            $yesterdayCount = count($yesterdayCigs);
            $todayCount = count($cigs);

            $bonuses = $this->calculateBonuses(
                $todayCount,
                $yesterdayCount,
                $baseScore,
                $currentDate,
                $allCigarettes
            );

            $score = $baseScore + $bonuses;

            // Calculer le streak
            if ($score > 0) {
                $streak++;
                if ($streak > $bestStreak) {
                    $bestStreak = $streak;
                }
            } else {
                $streak = 0;
            }

            // Calculer l'intervalle moyen
            $avgInterval = null;
            if (count($cigs) >= 2) {
                $totalMinutes = 0;
                for ($i = 1; $i < count($cigs); $i++) {
                    $diff = $cigs[$i]->getSmokedAt()->getTimestamp() - $cigs[$i - 1]->getSmokedAt()->getTimestamp();
                    $totalMinutes += $diff / 60;
                }
                $avgInterval = $totalMinutes / (count($cigs) - 1);
            }

            // Créer ou mettre à jour le DailyScore
            $dailyScore = new DailyScore();
            $dailyScore->setUser($user);
            $dailyScore->setDate(clone $currentDate);
            $dailyScore->setScore($score);
            $dailyScore->setCigaretteCount($todayCount);
            $dailyScore->setStreak($streak);
            $dailyScore->setAverageInterval($avgInterval);

            $this->dailyScoreRepository->upsert($dailyScore);

            $currentDate->modify('+1 day');
            $processed++;
            $io->progressAdvance();
        }

        $io->progressFinish();
        $io->success(sprintf('%d jours traités, meilleur streak: %d', $processed, $bestStreak));
    }

    /**
     * Charge toutes les cigarettes de l'utilisateur, indexées par date
     * @return array<string, array>
     */
    private function loadAllCigarettes(User $user, \DateTime $startDate, \DateTime $endDate): array
    {
        $cigs = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from('App\Entity\Cigarette', 'c')
            ->where('c.user = :user')
            ->andWhere('c.smokedAt >= :start')
            ->andWhere('c.smokedAt <= :end')
            ->setParameter('user', $user)
            ->setParameter('start', (clone $startDate)->modify('-7 days')->setTime(0, 0, 0))
            ->setParameter('end', (clone $endDate)->setTime(23, 59, 59))
            ->orderBy('c.smokedAt', 'ASC')
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($cigs as $cig) {
            $dateStr = $cig->getSmokedAt()->format('Y-m-d');
            if (!isset($indexed[$dateStr])) {
                $indexed[$dateStr] = [];
            }
            $indexed[$dateStr][] = $cig;
        }

        return $indexed;
    }

    /**
     * Charge tous les wakeups de l'utilisateur, indexés par date
     * @return array<string, \App\Entity\WakeUp>
     */
    private function loadAllWakeups(User $user, \DateTime $startDate, \DateTime $endDate): array
    {
        $wakeups = $this->entityManager->createQueryBuilder()
            ->select('w')
            ->from('App\Entity\WakeUp', 'w')
            ->where('w.user = :user')
            ->andWhere('w.date >= :start')
            ->andWhere('w.date <= :end')
            ->setParameter('user', $user)
            ->setParameter('start', (clone $startDate)->modify('-7 days')->setTime(0, 0, 0))
            ->setParameter('end', (clone $endDate)->setTime(0, 0, 0))
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($wakeups as $wakeup) {
            $indexed[$wakeup->getDate()->format('Y-m-d')] = $wakeup;
        }

        return $indexed;
    }

    /**
     * Calcule les bonus pour un jour donné
     */
    private function calculateBonuses(
        int $todayCount,
        int $yesterdayCount,
        int $baseScore,
        \DateTimeInterface $date,
        array $allCigarettes
    ): int {
        $bonus = 0;

        // Bonus de réduction vs hier
        if ($yesterdayCount > 0 && $todayCount < $yesterdayCount) {
            $bonus += ($yesterdayCount - $todayCount) * ScoringConstants::BONUS_PER_REDUCED_CIG;
        }

        // Bonus de régularité : si score de base positif avec au moins 3 clopes
        if ($todayCount >= ScoringConstants::MIN_CIGS_FOR_REGULARITY_BONUS && $baseScore > 0) {
            $bonus += ScoringConstants::BONUS_REGULARITY;
        }

        // Bonus tendance hebdo
        $bonus += $this->calculateWeeklyBonus($date, $allCigarettes);

        return $bonus;
    }

    /**
     * Calcule le bonus de réduction semaine/semaine
     */
    private function calculateWeeklyBonus(\DateTimeInterface $date, array $allCigarettes): int
    {
        // Semaine actuelle (7 derniers jours incluant aujourd'hui)
        $thisWeekTotal = 0;
        $thisWeekDays = 0;
        for ($i = 0; $i < 7; $i++) {
            $dateStr = (clone $date)->modify("-{$i} day")->format('Y-m-d');
            if (isset($allCigarettes[$dateStr])) {
                $thisWeekTotal += count($allCigarettes[$dateStr]);
                $thisWeekDays++;
            }
        }

        // Semaine précédente
        $lastWeekTotal = 0;
        $lastWeekDays = 0;
        for ($i = 7; $i < 14; $i++) {
            $dateStr = (clone $date)->modify("-{$i} day")->format('Y-m-d');
            if (isset($allCigarettes[$dateStr])) {
                $lastWeekTotal += count($allCigarettes[$dateStr]);
                $lastWeekDays++;
            }
        }

        // Pas assez de données
        if ($thisWeekDays < 3 || $lastWeekDays < 3) {
            return 0;
        }

        $thisWeekAvg = $thisWeekTotal / $thisWeekDays;
        $lastWeekAvg = $lastWeekTotal / $lastWeekDays;
        $diffAvg = $thisWeekAvg - $lastWeekAvg;

        if ($diffAvg <= -ScoringConstants::SIGNIFICANT_WEEKLY_REDUCTION) {
            return ScoringConstants::BONUS_WEEKLY_SIGNIFICANT;
        } elseif ($diffAvg <= 0) {
            return ScoringConstants::BONUS_WEEKLY_STABLE;
        }

        return 0;
    }
}
