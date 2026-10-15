/**
 * MINIMUM JOURNEY のクライアント（骨格。I-04）。
 * 盤面の表示は I-08。ここでは状態ごとのステータスバーの文言とボタン、ラウンド・プレイヤーの簡易表示だけを持つ。
 * 状態クラスはサーバー側の状態クラス名（PHP の States/ のクラス名）で登録する。
 */

/**
 * 準備フェイズのドロー／ストップ選択（状態 21）。全員が選び終えるまで何度でも変更できる（006 §4.3、009 §9.1）。
 */
class PrepChoice {
    /**
     * @param {Game} game
     * @param {Bga} bga
     */
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
        /** @type {string | null} */
        this.myChoice = null;
        this.canDraw = true;
    }

    /**
     * @param {{ selectors?: number[], chosen?: number[], _private?: { myChoice?: string | null, canDraw?: boolean } }} args
     * @param {boolean} isCurrentPlayerActive
     */
    onEnteringState(args, isCurrentPlayerActive) {
        this.myChoice = args._private?.myChoice ?? null;
        this.canDraw = args._private?.canDraw ?? true;
        this.onPlayerActivationChange(args, isCurrentPlayerActive);
    }

    /**
     * @param {unknown} _args
     * @param {boolean} _isCurrentPlayerActive
     */
    onLeavingState(_args, _isCurrentPlayerActive) {}

    /**
     * 選び終えた人は非アクティブになる。その間も、本人は選択を変更できる（006 §4.3）。
     *
     * @param {unknown} _args
     * @param {boolean} isCurrentPlayerActive
     */
    onPlayerActivationChange(_args, isCurrentPlayerActive) {
        if (!isCurrentPlayerActive && this.myChoice === null) {
            this.bga.statusBar.setTitle(_('Other players must choose: draw or stop'));
            return;
        }
        if (this.myChoice === null) {
            this.bga.statusBar.setTitle(_('${you} must choose: draw or stop'));
        } else {
            this.bga.statusBar.setTitle(
                this.myChoice === 'draw'
                    ? _('You chose to draw. You can change your choice until everyone has chosen')
                    : _('You chose to stop. You can change your choice until everyone has chosen'),
            );
        }
        this.bga.statusBar.removeActionButtons();
        this.addChoiceButton('draw', _('Draw'), !this.canDraw);
        this.addChoiceButton('stop', _('Stop'), false);
    }

    /**
     * @param {'draw' | 'stop'} choice
     * @param {string} label
     * @param {boolean} disabled
     */
    addChoiceButton(choice, label, disabled) {
        this.bga.statusBar.addActionButton(
            label,
            async () => {
                await this.bga.actions.performAction('actSelectPrep', { choice }, { checkAction: false });
                this.myChoice = choice;
                this.onPlayerActivationChange({}, false);
            },
            { color: this.myChoice === choice ? 'primary' : 'secondary', disabled },
        );
    }
}

/**
 * 049 時間停止装置の後選び（状態 23）。他プレイヤーの選択の開示後に選ぶ。押した時点で確定する。
 */
class PrepChoiceLate {
    /**
     * @param {Game} game
     * @param {Bga} bga
     */
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    /**
     * @param {{ canDraw?: boolean }} args
     * @param {boolean} isCurrentPlayerActive
     */
    onEnteringState(args, isCurrentPlayerActive) {
        this.bga.statusBar.setTitle(
            isCurrentPlayerActive ? _('${you} must choose: draw or stop') : _('${actplayer} must choose: draw or stop'),
        );
        if (isCurrentPlayerActive) {
            this.bga.statusBar.addActionButton(
                _('Draw'),
                () => this.bga.actions.performAction('actSelectPrepLate', { choice: 'draw' }),
                { disabled: args.canDraw === false },
            );
            this.bga.statusBar.addActionButton(
                _('Stop'),
                () => this.bga.actions.performAction('actSelectPrepLate', { choice: 'stop' }),
            );
        }
    }

    /**
     * @param {unknown} _args
     * @param {boolean} _isCurrentPlayerActive
     */
    onLeavingState(_args, _isCurrentPlayerActive) {}
}

/**
 * 探査手番（状態 43）。骨格ではパスだけ。
 */
class ExploreChoose {
    /**
     * @param {Game} game
     * @param {Bga} bga
     */
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    /**
     * @param {unknown} _args
     * @param {boolean} isCurrentPlayerActive
     */
    onEnteringState(_args, isCurrentPlayerActive) {
        this.bga.statusBar.setTitle(
            isCurrentPlayerActive ? _('${you} must explore or pass') : _('${actplayer} must explore or pass'),
        );
        if (isCurrentPlayerActive) {
            this.bga.statusBar.addActionButton(_('Pass'), () => this.bga.actions.performAction('actPass'), { color: 'secondary' });
        }
    }

    /**
     * @param {unknown} _args
     * @param {boolean} _isCurrentPlayerActive
     */
    onLeavingState(_args, _isCurrentPlayerActive) {}
}

export class Game {
    /**
     * @param {Bga} bga
     */
    constructor(bga) {
        this.bga = bga;
        /** @type {any} */
        this.gamedatas = null;

        this.bga.states.register('PrepChoice', new PrepChoice(this, bga));
        this.bga.states.register('PrepChoiceLate', new PrepChoiceLate(this, bga));
        this.bga.states.register('ExploreChoose', new ExploreChoose(this, bga));
    }

    /**
     * ページの表示・再読み込みのたびに呼ばれる。gamedatas は PHP の getAllDatas の戻り値。
     * 盤面の表示は I-08。ここではラウンドとプレイヤーごとの値を文字で出すだけ。
     *
     * @param {any} gamedatas
     */
    setup(gamedatas) {
        this.gamedatas = gamedatas;

        this.bga.gameArea.getElement().insertAdjacentHTML('beforeend', `
            <div id="mj-status"></div>
            <div id="mj-players"></div>
        `);
        this.renderStatus();

        this.setupNotifications();
    }

    renderStatus() {
        const status = document.getElementById('mj-status');
        if (status !== null) {
            status.textContent = _('Round') + ' ' + this.gamedatas.round;
        }
        const players = document.getElementById('mj-players');
        if (players === null) {
            return;
        }
        players.textContent = '';
        for (const playerId of this.gamedatas.playerOrder) {
            const info = this.gamedatas.players[playerId];
            const line = document.createElement('div');
            line.innerHTML = `${this.bga.players.getFormattedPlayerName(playerId)}: ${_('Deck')} ${info.deckCount}`;
            players.appendChild(line);
        }
    }

    setupNotifications() {
        this.bga.notifications.setupPromiseNotifications({});
    }

    /**
     * @param {{ round: number }} args
     */
    async notif_roundStarted(args) {
        this.gamedatas.round = args.round;
        this.renderStatus();
    }

    async notif_prepMarked() {}

    async notif_prepChoiceRecorded() {}

    // 準備フェイズの通知（盤面の更新は I-08）。ログは BGA が通知のメッセージから出す
    async notif_choiceRevealed() {}

    async notif_prepFinishedCleared() {}

    async notif_cardDrawn() {}

    async notif_faceDownSet() {}

    async notif_cardSideways() {}

    /**
     * @param {{ players: Object<string, { deckCount: number, facedownCount: number, prepCount: number }> }} args
     */
    async notif_countersUpdate(args) {
        for (const [playerId, counters] of Object.entries(args.players)) {
            Object.assign(this.gamedatas.players[playerId], counters);
        }
        this.renderStatus();
    }

    async notif_exploreStarted() {}

    async notif_explorePassed() {}
}
