<?php

namespace App\Service;

use App\Entity\Cigarette;
use App\Repository\CigaretteRepository;

class MessageService
{
    public function __construct(
        private CigaretteRepository $cigaretteRepository
    ) {}

    /**
     * Génère un message d'encouragement contextuel
     * @param Cigarette[] $todayCigs
     * @param Cigarette[] $yesterdayCigs
     * @param array $dailyScore
     * @return array{type: string, message: string, icon: string}|null
     */
    public function getEncouragementMessage(array $todayCigs, array $yesterdayCigs, array $dailyScore): ?array
    {
        $todayCount = count($todayCigs);
        $totalScore = $dailyScore['total_score'];
        $hour = (int) (new \DateTime())->format('H');

        // Seed pour variété des messages (change avec le jour et le nombre de clopes)
        $seed = (int) (new \DateTime())->format('Ymd') + $todayCount;

        // 1. Aucune clope aujourd'hui
        if ($todayCount === 0) {
            return $this->getZeroCigaretteMessage($hour, $seed);
        }

        // 2. Record en vue
        $minRecord = $this->cigaretteRepository->getMinDailyCount();
        if ($minRecord !== null && $todayCount <= $minRecord && $hour >= 14) {
            return $this->getRecordMessage($todayCount, $seed);
        }

        // 3. Comparaison avec hier à la même heure
        $yesterdayAtSameTime = $this->countYesterdayAtSameTime($yesterdayCigs);

        if ($todayCount < $yesterdayAtSameTime) {
            $diff = $yesterdayAtSameTime - $todayCount;
            return $this->getLessMessage($diff, $seed);
        }

        // 4. Plus de clopes qu'hier
        if ($todayCount > $yesterdayAtSameTime && $yesterdayAtSameTime > 0) {
            $diff = $todayCount - $yesterdayAtSameTime;
            return $this->getMoreMessage($diff, $seed);
        }

        // 5. Score très positif
        if ($totalScore > 30) {
            return $this->getGoodScoreMessage($totalScore, $seed);
        }

        // 6. Score positif moyen
        if ($totalScore > 0) {
            return $this->getOkScoreMessage($totalScore, $seed);
        }

        // 7. Score négatif mais encourageant
        if ($totalScore >= -30) {
            return $this->getEncourageMessage($seed);
        }

        // 8. Score très négatif - message de soutien
        if ($totalScore < -30) {
            return [
                'type' => 'warning',
                'icon' => '🤝',
                'message' => 'Journée difficile ? Demain est un nouveau jour !',
            ];
        }

        // 9. Message du soir
        if ($hour >= 19 && $totalScore >= 0) {
            return $this->getEveningMessage($seed);
        }

        return null;
    }

    private function countYesterdayAtSameTime(array $yesterdayCigs): int
    {
        $now = new \DateTime();
        $count = 0;
        foreach ($yesterdayCigs as $cig) {
            $cigTime = $cig->getSmokedAt();
            $cigTimeToday = (clone $cigTime)->modify('+1 day');
            if ($cigTimeToday <= $now) {
                $count++;
            }
        }
        return $count;
    }

    private function getZeroCigaretteMessage(int $hour, int $seed): array
    {
        $zeroMessages = [
            ['icon' => '🏆', 'message' => 'Zéro clope ! Tu gères comme un champion !'],
            ['icon' => '🌟', 'message' => 'Journée parfaite jusqu\'ici ! Continue !'],
            ['icon' => '💪', 'message' => 'Aucune clope, ta volonté est impressionnante !'],
            ['icon' => '🎉', 'message' => 'Bravo ! Pas une seule clope !'],
        ];

        $morningMessages = [
            ['icon' => '☀️', 'message' => 'Nouvelle journée, nouvelles opportunités !'],
            ['icon' => '🌅', 'message' => 'C\'est parti pour une bonne journée !'],
            ['icon' => '🌄', 'message' => 'Le matin est le moment idéal pour bien démarrer'],
            ['icon' => '☕', 'message' => 'Un café, de la motivation, c\'est tout ce qu\'il te faut !'],
        ];

        if ($hour < 10) {
            $msg = $morningMessages[$seed % count($morningMessages)];
        } elseif ($hour >= 20) {
            $msg = ['icon' => '🏆', 'message' => 'Journée sans clope ! Incroyable !'];
        } else {
            $msg = $zeroMessages[$seed % count($zeroMessages)];
        }

        return ['type' => 'success', 'message' => $msg['message'], 'icon' => $msg['icon']];
    }

    private function getRecordMessage(int $todayCount, int $seed): array
    {
        $recordMessages = [
            ['icon' => '🎯', 'message' => 'Record en vue ! Seulement ' . $todayCount . ' clope' . ($todayCount > 1 ? 's' : '') . ' !'],
            ['icon' => '🔥', 'message' => 'Tu bats ton record ! Continue !'],
            ['icon' => '⭐', 'message' => 'Nouveau record personnel possible !'],
            ['icon' => '🥇', 'message' => 'Tu es en train d\'écrire l\'histoire !'],
        ];

        $msg = $recordMessages[$seed % count($recordMessages)];
        return ['type' => 'success', 'message' => $msg['message'], 'icon' => $msg['icon']];
    }

    private function getLessMessage(int $diff, int $seed): array
    {
        $lessMessages = [
            ['icon' => '💪', 'message' => '%d clope%s de moins qu\'hier à cette heure !'],
            ['icon' => '📉', 'message' => 'En avance ! %d de moins qu\'hier'],
            ['icon' => '👏', 'message' => 'Super ! Tu as %d clope%s d\'avance sur hier'],
            ['icon' => '🎊', 'message' => 'Bravo ! %d en moins qu\'hier, ça paye !'],
        ];

        $msg = $lessMessages[$seed % count($lessMessages)];
        $plural = $diff > 1 ? 's' : '';

        return [
            'type' => 'success',
            'message' => sprintf($msg['message'], $diff, $plural),
            'icon' => $msg['icon'],
        ];
    }

    private function getMoreMessage(int $diff, int $seed): array
    {
        $moreMessages = [
            ['icon' => '💡', 'message' => '%d de plus qu\'hier. Essaie d\'espacer un peu'],
            ['icon' => '🔔', 'message' => 'Petit dépassement : +%d vs hier'],
            ['icon' => '⏰', 'message' => '+%d vs hier. Prends ton temps pour la prochaine'],
        ];

        $msg = $moreMessages[$seed % count($moreMessages)];

        return [
            'type' => 'warning',
            'message' => sprintf($msg['message'], $diff),
            'icon' => $msg['icon'],
        ];
    }

    private function getGoodScoreMessage(int $totalScore, int $seed): array
    {
        $goodScoreMessages = [
            ['icon' => '🚀', 'message' => 'En feu aujourd\'hui ! +' . $totalScore . ' pts'],
            ['icon' => '✨', 'message' => 'Très bon rythme ! +' . $totalScore . ' pts'],
            ['icon' => '💫', 'message' => 'Tu cartones ! Continue comme ça !'],
            ['icon' => '🎯', 'message' => 'Excellent ! Tes efforts paient !'],
        ];

        $msg = $goodScoreMessages[$seed % count($goodScoreMessages)];
        return ['type' => 'success', 'message' => $msg['message'], 'icon' => $msg['icon']];
    }

    private function getOkScoreMessage(int $totalScore, int $seed): array
    {
        $okScoreMessages = [
            ['icon' => '👌', 'message' => 'Tu es dans le vert (+' . $totalScore . ' pts)'],
            ['icon' => '✅', 'message' => 'Score positif ! Continue sur cette lancée'],
            ['icon' => '👍', 'message' => 'Bien joué ! +' . $totalScore . ' pts au compteur'],
        ];

        $msg = $okScoreMessages[$seed % count($okScoreMessages)];
        return ['type' => 'success', 'message' => $msg['message'], 'icon' => $msg['icon']];
    }

    private function getEncourageMessage(int $seed): array
    {
        $encourageMessages = [
            ['icon' => '💡', 'message' => 'Essaie d\'espacer un peu plus tes clopes'],
            ['icon' => '🌱', 'message' => 'Chaque petit effort compte, ne lâche pas !'],
            ['icon' => '💭', 'message' => 'Prends une grande respiration avant la prochaine'],
            ['icon' => '🎯', 'message' => 'Focus sur l\'intervalle, tu peux y arriver !'],
        ];

        $msg = $encourageMessages[$seed % count($encourageMessages)];
        return ['type' => 'warning', 'message' => $msg['message'], 'icon' => $msg['icon']];
    }

    private function getEveningMessage(int $seed): array
    {
        $eveningMessages = [
            ['icon' => '🌙', 'message' => 'Bientôt la fin de journée, tiens bon !'],
            ['icon' => '🌆', 'message' => 'La soirée approche, termine en beauté !'],
        ];

        $msg = $eveningMessages[$seed % count($eveningMessages)];
        return ['type' => 'success', 'message' => $msg['message'], 'icon' => $msg['icon']];
    }
}
