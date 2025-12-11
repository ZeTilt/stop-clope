<?php

namespace App\Command;

use App\Entity\DailyScore;
use App\Entity\User;
use App\Repository\CigaretteRepository;
use App\Repository\DailyScoreRepository;
use App\Repository\UserRepository;
use App\Repository\WakeUpRepository;
use App\Service\ScoringService;
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
        private CigaretteRepository $cigaretteRepository,
        private WakeUpRepository $wakeUpRepository,
        private DailyScoreRepository $dailyScoreRepository,
        private ScoringService $scoringService,
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

        $currentDate = clone $startDate;
        $currentDate->setTime(0, 0, 0);

        $streak = 0;
        $bestStreak = 0;
        $processed = 0;

        $io->progressStart((int) $currentDate->diff($endDate)->days + 1);

        while ($currentDate <= $endDate) {
            $dateStr = $currentDate->format('Y-m-d');

            // Récupérer les cigarettes du jour
            $cigs = $this->entityManager->createQueryBuilder()
                ->select('c')
                ->from('App\Entity\Cigarette', 'c')
                ->where('c.user = :user')
                ->andWhere('c.smokedAt >= :start')
                ->andWhere('c.smokedAt <= :end')
                ->setParameter('user', $user)
                ->setParameter('start', (clone $currentDate)->setTime(0, 0, 0))
                ->setParameter('end', (clone $currentDate)->setTime(23, 59, 59))
                ->orderBy('c.smokedAt', 'ASC')
                ->getQuery()
                ->getResult();

            // Calculer le score via ScoringService
            $dailyScoreData = $this->scoringService->calculateDailyScore($currentDate);
            $score = $dailyScoreData['total_score'];

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
            $dailyScore->setCigaretteCount(count($cigs));
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
}
