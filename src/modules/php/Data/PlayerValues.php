<?php

declare(strict_types=1);

namespace Bga\Games\MinimumJourney\Data;

/**
 * player テーブルの追加列（仕様書 007 §4.1）のアクセサ。
 * prep_choice は確定まで本人以外に送らない。player 行をそのまま JSON にして返さないこと。
 */
final class PlayerValues
{
    public function __construct(private readonly Database $db)
    {
    }

    public function prepFinished(int $playerId): bool
    {
        return (int) $this->column($playerId, 'prep_finished') === 1;
    }

    public function setPrepFinished(int $playerId, bool $value): void
    {
        $this->update($playerId, '`prep_finished` = ' . ($value ? 1 : 0));
    }

    public function prepChoice(int $playerId): ?PrepChoice
    {
        $v = $this->column($playerId, 'prep_choice');
        return $v === null ? null : PrepChoice::tryFrom((string) $v);
    }

    public function setPrepChoice(int $playerId, ?PrepChoice $choice): void
    {
        $this->update($playerId, '`prep_choice` = ' . ($choice === null ? 'NULL' : "'" . $choice->value . "'"));
    }

    public function isHalved(int $playerId): bool
    {
        return (int) $this->column($playerId, 'is_halved') === 1;
    }

    public function setHalved(int $playerId, bool $value): void
    {
        $this->update($playerId, '`is_halved` = ' . ($value ? 1 : 0));
    }

    public function exploreOutput(int $playerId): ?int
    {
        $v = $this->column($playerId, 'explore_output');
        return $v === null ? null : (int) $v;
    }

    public function setExploreOutput(int $playerId, ?int $value): void
    {
        $this->update($playerId, '`explore_output` = ' . ($value === null ? 'NULL' : $value));
    }

    /**
     * 全員の準備終了・準備選択・半減・確定探査出力を初期状態に戻す（状態 10 のラウンド開始）。
     */
    public function resetRoundValues(): void
    {
        $this->db->execute(
            'UPDATE `player` SET `prep_finished` = 0, `prep_choice` = NULL, `is_halved` = 0, `explore_output` = NULL',
        );
    }

    /**
     * 準備選択だけを全員 null に戻す（状態 20 の冒頭。前回の選択を持ち越さない）。
     */
    public function clearPrepChoices(): void
    {
        $this->db->execute('UPDATE `player` SET `prep_choice` = NULL');
    }

    /**
     * 公開してよい「準備終了」の一覧。
     *
     * @return array<int, bool> プレイヤー ID => 準備終了
     */
    public function prepFinishedMap(): array
    {
        $map = [];
        foreach ($this->db->rows('SELECT `player_id`, `prep_finished` FROM `player`') as $row) {
            $map[(int) $row['player_id']] = (int) $row['prep_finished'] === 1;
        }
        return $map;
    }

    /**
     * 公開してよい「選択済みか」の一覧（内容は含まない）。
     *
     * @return array<int, bool> プレイヤー ID => 選択済み
     */
    public function prepChosenMap(): array
    {
        $map = [];
        foreach ($this->db->rows('SELECT `player_id`, `prep_choice` FROM `player`') as $row) {
            $map[(int) $row['player_id']] = $row['prep_choice'] !== null;
        }
        return $map;
    }

    private function column(int $playerId, string $column): mixed
    {
        return $this->db->value("SELECT `$column` FROM `player` WHERE `player_id` = $playerId");
    }

    private function update(int $playerId, string $assignment): void
    {
        $this->db->execute("UPDATE `player` SET $assignment WHERE `player_id` = $playerId");
    }
}
