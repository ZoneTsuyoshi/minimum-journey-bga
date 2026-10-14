# 仕様書

実装の前に、必ずここへ仕様書を作成します。作り方は [../development/task-workflow.md](../development/task-workflow.md) を参照してください。

- ファイル名: `NNN-<slug>.md`（`NNN` は 3 桁の連番、`slug` は英小文字とハイフン）
- 雛形: [_template.md](_template.md)
- ステータス: `Draft`（作成中）→ `Approved`（PR マージ済み）→ `Implemented`（実装済み）

## 一覧

| No. | タイトル | ステータス | 対応タスク |
| --- | --- | --- | --- |
| 001 | ゲームルール仕様 | Approved | T-001 |
| 002 | カードデータ仕様 | Approved | T-002 |
| 003 | BGA プロジェクト構成・技術方針 | Approved | T-003 |
| 004 | CI（lint チェック） | Approved | T-004 |
| 005 | BGA Studio への自動同期 | Approved | T-005 |
| 006 | 状態遷移 | Approved | T-006 |
| 007 | データモデル・通知 | Draft | T-007 |
| 009 | UI・アセット設計 | Approved | T-009 |
| 010 | ドロー時効果の優先度 | Approved | T-010 |
| 011 | 探査力変動効果の適用順 | Approved | T-011 |

各仕様書は別ブランチ（`docs/spec-NNN-<slug>`）で作成し、PR のマージで `main` に入る。
