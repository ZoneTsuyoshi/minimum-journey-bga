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


// ---------------------------------------------------------------------------
// 盤面の描画（I-08。仕様書 009 §7〜§11、007 §8・§10）
// ---------------------------------------------------------------------------

const BgaAnimations = await importEsmLib('bga-animations', '1.x');
const BgaCards = await importEsmLib('bga-cards', '1.x');

/**
 * 画面に出すカード 1 枚の情報。サーバーの `{ id, cardNo, art }` に表示用の項目を足したもの。
 * `id` が文字列のものは、山札の天辺などに使う偽のカード（裏面だけを描く）。
 *
 * @typedef {object} MjCard
 * @property {number | string} id
 * @property {number} cardNo カード番号。0 は裏面だけの偽カード
 * @property {number} art 絵柄番号（001〜003 は 1 以上。それ以外は 0）
 * @property {number} [order] 準備した順の通し番号（準備場のみ）
 * @property {boolean} [sideways] 横向き（1 人だけドローしたカード）
 * @property {number | null} [attachedTo] 付加カードのとき、本体（カード番号 46）の ID
 * @property {boolean} [hasAttached] 付加カードを持つ本体か
 * @property {number} [slot] 探査場の列のスロット番号
 */

/**
 * プレイヤーごとの表示用の値（007 §8.2 の players[pid] と 007 §10 の countersUpdate）。
 *
 * @typedef {object} MjStats
 * @property {number} deckCount
 * @property {number} facedownCount
 * @property {number} acquiredCount
 * @property {number} prepCount
 * @property {boolean} prepFinished
 * @property {boolean} isHalved
 * @property {number} outputNow
 * @property {number | null} output
 * @property {number | null} fame
 */

/** スプライトの寸法（scripts/lib/sprite-layout.mjs の TIERS と同じ値。009 §3.4） */
const SHEETS = {
    base: {
        cell: { w: 300, h: 419 },
        sheets: {
            start: { file: 'cards_start.webp', cols: 4, rows: 5 },
            drift: { file: 'cards_drift.webp', cols: 13, rows: 4 },
        },
    },
    zoom: {
        cell: { w: 500, h: 699 },
        sheets: {
            start: { file: 'cards_zoom_start.webp', cols: 4, rows: 5 },
            drift_a: { file: 'cards_zoom_drift_a.webp', cols: 6, rows: 5 },
            drift_b: { file: 'cards_zoom_drift_b.webp', cols: 6, rows: 4 },
        },
    },
};

/** start シートの並び（scripts/lib/sprite-layout.mjs の START_KEYS と同じ。009 §4.2） */
const START_KEYS = [
    '01_1', '01_2', '01_3', '01_4',
    '02_1', '02_2', '02_3', '02_4',
    '02_5', '02_6', '02_7', '02_8',
    '03_1', '03_2', '03_3', '03_4',
    '56', '57', '58', 'rev',
];

/** 探査山札・列のコスト */
const COSTS = [2, 4, 6, 8];

/** 通知で受ける値を整数にそろえる（PHP から文字列で来ることがある。009 §10） */
const int = (/** @type {unknown} */ v) => Number(v);

/**
 * カード番号と絵柄からスプライトキーを作る。
 *
 * @param {number} cardNo 0 のときは裏面（rev）
 * @param {number} art
 * @returns {string}
 */
function spriteKey(cardNo, art) {
    if (cardNo <= 0) {
        return 'rev';
    }
    const two = String(cardNo).padStart(2, '0');
    return cardNo <= 3 ? `${two}_${art}` : two;
}

/**
 * スプライトキー → シート・列・行（sprite-layout.mjs の cardPosition と同じ規則。009 §4.2・§4.3 案 M1）。
 *
 * @param {string} key
 * @param {'base' | 'zoom'} tier
 * @returns {{ sheet: string, col: number, row: number }}
 */
function cardPosition(key, tier) {
    const i = START_KEYS.indexOf(key);
    if (i >= 0) {
        return { sheet: 'start', col: i % 4, row: Math.floor(i / 4) };
    }
    const n = Number(key) - 4;
    if (!/^\d{2}$/.test(key) || n < 0 || n >= 52) {
        return { sheet: 'start', col: 3, row: 4 }; // 未知のキーは裏面にする
    }
    if (tier === 'base') {
        return { sheet: 'drift', col: n % 13, row: Math.floor(n / 13) };
    }
    if (n < 30) {
        return { sheet: 'drift_a', col: n % 6, row: Math.floor(n / 6) };
    }
    const m = n - 30;
    return { sheet: 'drift_b', col: m % 6, row: Math.floor(m / 6) };
}

/**
 * 探査場の列の規定枚数（001 §3。3 人 3/3/2/2、4 人 4/3/2/2）。
 *
 * @param {number} playerCount
 * @returns {Record<number, number>}
 */
function defaultRowQuota(playerCount) {
    return { 2: playerCount >= 4 ? 4 : 3, 4: 3, 6: 2, 8: 2 };
}

/**
 * 1 人分の領域（山札、裏向きの山、準備場、獲得カード、お守り）の部品。
 *
 * @typedef {object} PlayerArea
 * @property {HTMLElement} root
 * @property {any} deck Deck
 * @property {any} facedown Deck
 * @property {any} prep LineStock
 * @property {any} acquired AllVisibleDeck
 * @property {any} charm LineStock
 * @property {boolean} compact
 */

export class Game {
    /**
     * @param {Bga} bga
     */
    constructor(bga) {
        this.bga = bga;
        /** @type {any} */
        this.gamedatas = null;

        /** @type {Record<number, PlayerArea>} */
        this.areas = {};
        /** @type {Record<number, MjStats>} */
        this.stats = {};
        /** 準備の選択を終えたプレイヤー（内容は持たない。009 §9.1） */
        this.chosen = new Set();
        /** @type {number[]} */
        this.exploreOrder = [];
        this.exploreIndex = -1;
        this.gameEnded = false;
        /** @type {number | null} */
        this.charmHolder = null;
        /** @type {any[]} 探査場・全員の領域のすべての stock（ID から場所を探すのに使う） */
        this.allStocks = [];
        /** @type {Record<number, any>} 探査山札（コスト別の Deck） */
        this.exploreDecks = {};
        /** @type {Record<number, any>} 探査場の列（コスト別の SlotStock） */
        this.rowStocks = {};
        /** @type {any} */
        this.unknownStock = null;
        /** @type {any} */
        this.cardsManager = null;
        /** @type {Record<number, number>} */
        this.pileCounts = {};
        /** @type {Record<string, boolean>} 拡大用シートの読み込み済みフラグ */
        this.zoomReady = {};
        this.lastPointerType = 'mouse';
        /** @type {ReturnType<typeof setTimeout> | null} */
        this.zoomTimer = null;
        this.zoomOpen = false;

        this.bga.states.register('PrepChoice', new PrepChoice(this, bga));
        this.bga.states.register('PrepChoiceLate', new PrepChoiceLate(this, bga));
        this.bga.states.register('ExploreChoose', new ExploreChoose(this, bga));
    }

    /**
     * ページの表示・再読み込みのたびに呼ばれる。gamedatas は PHP の getAllDatas の戻り値（007 §8.2）。
     * I-05 以降が足す項目（prep、acquired、prepCount、options など）が無くても動くようにしてある。
     *
     * @param {any} gamedatas
     */
    setup(gamedatas) {
        this.gamedatas = gamedatas;
        // 拡大用のシートは使うまで読み込まない（009 §3.3）
        this.bga.images.dontPreloadImages(Object.values(SHEETS.zoom.sheets).map((s) => s.file));

        const animationManager = new BgaAnimations.Manager({
            animationsActive: () => this.bga.gameui.bgaAnimationsActive(),
        });
        this.cardsManager = new BgaCards.Manager({
            animationManager,
            type: 'mj-card',
            getId: (/** @type {MjCard} */ card) => card.id,
            // 表面を常に使い、山札の天辺だけ偽のカード（cardNo 0）で裏面を出す（009 §10）
            isCardVisible: (/** @type {MjCard} */ card) => card.cardNo > 0,
            getCardRotation: (/** @type {MjCard} */ card) => (card.sideways === true ? 90 : 0),
            fakeCardGenerator: (/** @type {string} */ deckId) => ({ id: `${deckId}-top`, cardNo: 0, art: 0 }),
            cardWidth: 120,
            cardHeight: 168,
            cardBorderRadius: '4.2%',
            setupDiv: (/** @type {MjCard} */ card, /** @type {HTMLDivElement} */ div) => this.setupCardDiv(card, div),
            setupFrontDiv: (/** @type {MjCard} */ card, /** @type {HTMLDivElement} */ div) => this.setupSprite(div, spriteKey(card.cardNo, card.art)),
            setupBackDiv: (/** @type {MjCard} */ _card, /** @type {HTMLDivElement} */ div) => this.setupSprite(div, 'rev'),
        });

        const playerOrder = /** @type {number[]} */ (gamedatas.playerOrder.map(int));
        const isSpectator = this.bga.players.isCurrentPlayerSpectator();
        const me = int(this.bga.players.getCurrentPlayerId());
        this.gameEnded = gamedatas.phase === 'end' || (typeof g_archive_mode !== 'undefined' && g_archive_mode);

        // 領域の並び: 自分を先頭に、席順で続ける。観戦者は席順のまま全員を縮小表示（009 §7.2・§11）
        const myIndex = playerOrder.indexOf(me);
        const ordered = isSpectator || myIndex < 0
            ? playerOrder
            : [...playerOrder.slice(myIndex), ...playerOrder.slice(0, myIndex)];

        this.bga.gameArea.getElement().insertAdjacentHTML('beforeend', `
            <div id="mj-board" class="mj-board">
                <div id="mj-explore" class="mj-explore mj-scope"></div>
                <div id="mj-players" class="mj-players"></div>
            </div>
            <div id="mj-zoom" class="mj-zoom" hidden aria-hidden="true">
                <div id="mj-zoom-img" class="mj-zoom-img"></div>
                <div id="mj-zoom-text" class="mj-zoom-text"></div>
            </div>
        `);

        this.setupExplore(gamedatas, playerOrder.length);
        for (const playerId of ordered) {
            const compact = isSpectator || playerId !== me;
            this.setupPlayerArea(playerId, gamedatas.players[playerId] ?? {}, compact);
        }
        this.setupCharm(gamedatas.charm);
        this.chosen = new Set((gamedatas.gamestate?.args?.chosen ?? []).map(int));
        this.refreshAllStats();
        this.setupZoom();
        this.setupNotifications();
    }

    // ---- 探査場 -----------------------------------------------------------

    /**
     * @param {any} gamedatas
     * @param {number} playerCount
     */
    setupExplore(gamedatas, playerCount) {
        const quota = gamedatas.rowQuota ?? defaultRowQuota(playerCount);
        const root = /** @type {HTMLElement} */ (document.getElementById('mj-explore'));
        const rowsHtml = COSTS.map((cost) => `
            <div class="mj-row" data-cost="${cost}">
                <div class="mj-pile-wrap">
                    <div id="mj-explore-deck-${cost}" class="mj-pile mj-explore-deck"></div>
                    <span class="mj-cost-tag"><span class="mj-icon mj-icon-cost" role="img" aria-label="${_('Exploration cost')}"></span>${cost}</span>
                </div>
                <div id="mj-row-${cost}" class="mj-row-slots"></div>
            </div>`).join('');
        root.innerHTML = `
            <h2 class="mj-heading">${_('Exploration field')}</h2>
            <div class="mj-unknown-wrap">
                <div id="mj-unknown" class="mj-unknown"></div>
                <span class="mj-cost-tag"><span class="mj-icon mj-icon-unknown" role="img" aria-label="${_('Unknown band')}"></span>10</span>
            </div>
            <p id="mj-end-warning" class="mj-warning" hidden>${_('The 8-cost pile is empty: the game ends at the end of this round')}</p>
            ${rowsHtml}`;

        const stockStyles = {
            selectableCardStyle: { class: 'mj-selectable' },
            unselectableCardStyle: { class: 'mj-unselectable' },
            selectedCardStyle: { class: 'mj-selected' },
        };
        this.unknownStock = this.registerStock(new BgaCards.LineStock(this.cardsManager, document.getElementById('mj-unknown'), { gap: '0', ...stockStyles }));
        if (gamedatas.unknown) {
            this.unknownStock.addCard(this.toCard(gamedatas.unknown));
        }

        for (const cost of COSTS) {
            this.pileCounts[cost] = int(gamedatas.piles?.[cost] ?? 0);
            const deckEl = /** @type {HTMLElement} */ (document.getElementById(`mj-explore-deck-${cost}`));
            this.exploreDecks[cost] = this.registerStock(new BgaCards.Deck(this.cardsManager, deckEl, {
                cardNumber: this.pileCounts[cost],
                counter: { show: true, position: 'bottom', extraClasses: 'mj-count' },
                ...stockStyles,
            }));
            const slotCount = Math.max(int(quota[cost] ?? 0), ...(gamedatas.rows?.[cost] ?? []).map((/** @type {{ slot: number }} */ r) => int(r.slot) + 1));
            this.rowStocks[cost] = this.registerStock(new BgaCards.SlotStock(this.cardsManager, document.getElementById(`mj-row-${cost}`), {
                slotsIds: Array.from({ length: slotCount }, (_v, i) => i),
                slotClasses: ['mj-slot'],
                mapCardToSlot: (/** @type {MjCard} */ card) => card.slot ?? 0,
                gap: '0',
                ...stockStyles,
            }));
            for (const entry of gamedatas.rows?.[cost] ?? []) {
                this.rowStocks[cost].addCard(this.toCard(entry.card, { slot: int(entry.slot) }));
            }
        }
        this.updatePileMarks();
    }

    /** 枚数 0 の山を空枠にし、コスト 8 が尽きたら注意を出す（009 §8.2） */
    updatePileMarks() {
        for (const cost of COSTS) {
            document.getElementById(`mj-explore-deck-${cost}`)?.classList.toggle('mj-empty', this.pileCounts[cost] === 0);
        }
        const warning = document.getElementById('mj-end-warning');
        if (warning !== null) {
            warning.hidden = this.pileCounts[8] !== 0;
        }
    }

    // ---- プレイヤーの領域とパネル -----------------------------------------

    /**
     * @param {number} playerId
     * @param {any} data getAllDatas の players[pid]
     * @param {boolean} compact 縮小表示（他プレイヤー・観戦者）
     */
    setupPlayerArea(playerId, data, compact) {
        const name = this.bga.players.getFormattedPlayerName(playerId);
        const container = /** @type {HTMLElement} */ (document.getElementById('mj-players'));
        container.insertAdjacentHTML('beforeend', `
            <section id="mj-player-${playerId}" class="mj-player mj-scope ${compact ? 'mj-compact' : 'mj-mine'}" data-mj-pid="${playerId}">
                <h3 class="mj-heading mj-player-head">
                    <span class="mj-player-name">${name}</span>
                    ${this.statsHtml(playerId)}
                </h3>
                <div class="mj-player-main">
                    <div class="mj-pile-wrap">
                        <div id="mj-deck-${playerId}" class="mj-pile mj-deck"></div>
                        <span class="mj-label">${_('Deck')}</span>
                    </div>
                    <div class="mj-pile-wrap">
                        <div id="mj-facedown-${playerId}" class="mj-pile mj-facedown"></div>
                        <span class="mj-label">${_('Face-down')}</span>
                    </div>
                    <div class="mj-prep-wrap">
                        <div id="mj-prep-${playerId}" class="mj-prep"></div>
                        <button type="button" id="mj-fold-${playerId}" class="mj-fold" hidden></button>
                    </div>
                    <div class="mj-pile-wrap">
                        <div id="mj-charm-${playerId}" class="mj-charm"></div>
                        <span class="mj-label">${_('Charm')}</span>
                    </div>
                </div>
                <div class="mj-acquired-wrap">
                    <span class="mj-label">${_('Acquired this round')}</span>
                    <div id="mj-acquired-${playerId}" class="mj-acquired"></div>
                </div>
            </section>`);

        const root = /** @type {HTMLElement} */ (document.getElementById(`mj-player-${playerId}`));
        const el = (/** @type {string} */ id) => /** @type {HTMLElement} */ (document.getElementById(`${id}-${playerId}`));
        const counter = { show: true, position: 'bottom', extraClasses: 'mj-count' };
        const deckCount = int(data.deckCount ?? 0);
        const facedownCount = int(data.facedownCount ?? 0);
        const prep = /** @type {any[]} */ (data.prep ?? []);
        const acquired = /** @type {any[]} */ (data.acquired ?? []);

        /** @type {PlayerArea} */
        const area = {
            root,
            compact,
            deck: this.registerStock(new BgaCards.Deck(this.cardsManager, el('mj-deck'), { cardNumber: deckCount, counter })),
            facedown: this.registerStock(new BgaCards.Deck(this.cardsManager, el('mj-facedown'), { cardNumber: facedownCount, counter })),
            prep: this.registerStock(new BgaCards.LineStock(this.cardsManager, el('mj-prep'), { wrap: 'wrap', gap: '0' })),
            acquired: this.registerStock(new BgaCards.AllVisibleDeck(this.cardsManager, el('mj-acquired'), { shift: '14px', direction: 'horizontal' })),
            charm: this.registerStock(new BgaCards.LineStock(this.cardsManager, el('mj-charm'), { gap: '0' })),
        };
        this.areas[playerId] = area;

        for (const entry of prep) {
            area.prep.addCards(this.prepEntryCards(entry));
        }
        area.acquired.addCards(acquired.map((c) => this.toCard(c)));

        this.stats[playerId] = {
            deckCount,
            facedownCount,
            acquiredCount: int(data.acquiredCount ?? acquired.length),
            prepCount: int(data.prepCount ?? prep.length),
            prepFinished: data.prepFinished === true,
            isHalved: data.isHalved === true,
            outputNow: int(data.outputNow ?? data.output ?? 0),
            output: data.output === null || data.output === undefined ? null : int(data.output),
            fame: data.fame === null || data.fame === undefined ? null : int(data.fame),
        };

        // 他プレイヤーの準備場は 3 行を超えたら折りたたむ（009 §8.2）
        if (compact) {
            const fold = el('mj-fold');
            fold.addEventListener('click', () => {
                area.root.classList.toggle('mj-unfolded');
                this.updateFold(playerId);
            });
            if (typeof ResizeObserver !== 'undefined') {
                new ResizeObserver(() => this.updateFold(playerId)).observe(el('mj-prep'));
            }
        }

        const panel = this.bga.playerPanels.getElement(playerId);
        panel.insertAdjacentHTML('beforeend', `<div class="mj-panel">${this.statsHtml(playerId)}</div>`);
    }

    /**
     * 準備場の 1 項目（本体＋付加カード）を、表示用のカードの並びにする。
     *
     * @param {{ card: any, order: number, sideways?: boolean, attached?: any[] }} entry
     * @returns {MjCard[]}
     */
    prepEntryCards(entry) {
        const attached = entry.attached ?? [];
        const host = this.toCard(entry.card, { order: int(entry.order), sideways: entry.sideways === true, hasAttached: attached.length > 0 });
        return [host, ...attached.map((a) => this.toCard(a, { attachedTo: int(host.id) }))];
    }

    /**
     * @param {{ card: any, holder: number } | null | undefined} charm
     */
    setupCharm(charm) {
        if (!charm) {
            return;
        }
        this.charmHolder = int(charm.holder);
        this.areas[this.charmHolder]?.charm.addCard(this.toCard(charm.card));
    }

    /**
     * パネルと領域の見出しに入れる数値の HTML。同じ内容を 2 か所に出す（009 §8.3）。
     * 更新は data-mj-pid と data-mj-stat で探して書き換える。
     *
     * @param {number} playerId
     * @returns {string}
     */
    statsHtml(playerId) {
        const stat = (/** @type {string} */ key, /** @type {string} */ title, /** @type {string} */ inner, hidden = false) =>
            `<span class="mj-stat" data-mj-pid="${playerId}" data-mj-stat="${key}" title="${title}"${hidden ? ' hidden' : ''}>${inner}</span>`;
        const icon = (/** @type {string} */ name, /** @type {string} */ label) =>
            `<span class="mj-icon mj-icon-${name}" role="img" aria-label="${label}"></span>`;
        return `<span class="mj-stats">`
            + stat('prepCount', _('Prepared cards'), `<span class="mj-stat-label">${_('Prep')}</span><b data-mj-val></b>`)
            + stat('output', _('Current exploration output'), `${icon('power', _('Exploration output'))}<b data-mj-val></b>`)
            + stat('deckCount', _('Cards in deck'), `<span class="mj-stat-label">${_('Deck')}</span><b data-mj-val></b>`)
            + stat('charm', _('Holds the charm'), `${icon('charm', _('Charm'))}`, true)
            + stat('fame', _('Total fame'), `${icon('fame', _('Fame'))}<b data-mj-val></b>`, true)
            + stat('chosen', _('Has chosen'), `<span aria-hidden="true">✓</span> ${_('Chosen')}`, true)
            + stat('turn', _('Exploration turn order'), `<span class="mj-stat-label">${_('Turn')}</span><b data-mj-val></b>`, true)
            + `</span>`;
    }

    refreshAllStats() {
        for (const playerId of Object.keys(this.stats).map(int)) {
            this.refreshStats(playerId);
        }
    }

    /**
     * 名声は、サーバーが値を送ったとき（オプション有効）だけ、プレイヤーにだけ出す。
     * 観戦者には終了後の最終得点だけを出す（009 No.15）。
     *
     * @param {number} playerId
     */
    refreshStats(playerId) {
        const s = this.stats[playerId];
        if (s === undefined) {
            return;
        }
        const isSpectator = this.bga.players.isCurrentPlayerSpectator();
        const showFame = s.fame !== null && (!isSpectator || this.gameEnded);
        const turnIndex = this.exploreOrder.indexOf(playerId);
        /** @type {Record<string, [boolean, string]>} */
        const view = {
            prepCount: [true, String(s.prepCount)],
            output: [true, (s.output ?? s.outputNow) + (s.output !== null && s.isHalved ? ' (÷2)' : '')],
            deckCount: [true, String(s.deckCount)],
            charm: [this.charmHolder === playerId, ''],
            fame: [showFame, String(s.fame ?? '')],
            chosen: [this.chosen.has(playerId), ''],
            turn: [turnIndex >= 0, String(turnIndex + 1)],
        };
        for (const node of document.querySelectorAll(`[data-mj-pid="${playerId}"][data-mj-stat]`)) {
            const element = /** @type {HTMLElement} */ (node);
            const entry = view[element.dataset.mjStat ?? ''];
            if (entry === undefined) {
                continue;
            }
            element.hidden = !entry[0];
            const val = element.querySelector('[data-mj-val]');
            if (val !== null) {
                val.textContent = entry[1];
            }
        }
        this.areas[playerId]?.root.classList.toggle('mj-active-turn', this.exploreIndex >= 0 && this.exploreOrder[this.exploreIndex] === playerId);
    }

    /**
     * 他プレイヤーの準備場の折りたたみボタンを更新する。
     *
     * @param {number} playerId
     */
    updateFold(playerId) {
        const area = this.areas[playerId];
        const prepEl = document.getElementById(`mj-prep-${playerId}`);
        const button = document.getElementById(`mj-fold-${playerId}`);
        if (area === undefined || !area.compact || prepEl === null || button === null) {
            return;
        }
        const unfolded = area.root.classList.contains('mj-unfolded');
        const overflow = prepEl.scrollHeight > prepEl.clientHeight + 1;
        button.hidden = !unfolded && !overflow;
        button.textContent = unfolded ? _('Collapse') : _('Show all');
    }

    // ---- カード要素 -------------------------------------------------------

    /**
     * 通知・getAllDatas のカードを表示用に整える。
     *
     * @param {{ id: number | string, cardNo: number, art: number }} raw
     * @param {Partial<MjCard>} [extra]
     * @returns {MjCard}
     */
    toCard(raw, extra = {}) {
        return { id: int(raw.id), cardNo: int(raw.cardNo), art: int(raw.art), ...extra };
    }

    /**
     * @template T
     * @param {T} stock
     * @returns {T}
     */
    registerStock(stock) {
        this.allStocks.push(stock);
        return stock;
    }

    /**
     * カード ID から、いまどの stock にあるかを探す。
     *
     * @param {number} cardId
     * @returns {{ stock: any, card: MjCard } | null}
     */
    locate(cardId) {
        for (const stock of this.allStocks) {
            const card = stock.getCards().find((/** @type {MjCard} */ c) => c.id === cardId);
            if (card !== undefined) {
                return { stock, card };
            }
        }
        return null;
    }

    /**
     * カード要素に付ける共通の属性（アクセシビリティ、順番の番号、横向き、付加カード）。
     *
     * @param {MjCard} card
     * @param {HTMLDivElement} div
     */
    setupCardDiv(card, div) {
        div.classList.add('mj-card');
        if (card.cardNo > 0) {
            div.dataset.mjKey = spriteKey(card.cardNo, card.art);
            // カード名は I-12 で英語名を入れる。それまでは番号で示す（009 §6、§11）
            div.setAttribute('aria-label', `${_('Card')} ${String(card.cardNo).padStart(3, '0')}`);
            div.setAttribute('role', 'img');
        }
        div.classList.toggle('mj-sideways', card.sideways === true);
        div.classList.toggle('mj-attached', card.attachedTo !== undefined && card.attachedTo !== null);
        div.classList.toggle('mj-has-attached', card.hasAttached === true);
        if (card.sideways === true) {
            div.insertAdjacentHTML('beforeend', `<span class="mj-sideways-mark" title="${_('Sideways: power and effects are ignored')}"></span>`);
        }
        if (card.order !== undefined && card.order > 0 && card.attachedTo === undefined) {
            div.insertAdjacentHTML('beforeend', `<span class="mj-order">${card.order}</span>`);
        }
    }

    /**
     * スプライトの表示位置を CSS 変数で渡す（009 §4.3）。位置の計算は CSS（px 単位）。
     *
     * @param {HTMLElement} div
     * @param {string} key
     */
    setupSprite(div, key) {
        const pos = cardPosition(key, 'base');
        const sheet = /** @type {{ file: string, cols: number, rows: number }} */ (/** @type {any} */ (SHEETS.base.sheets)[pos.sheet]);
        div.classList.add('mj-face');
        div.style.backgroundImage = `url(${this.bga.images.getImgUrl(sheet.file)})`;
        div.style.setProperty('--mj-cols', String(sheet.cols));
        div.style.setProperty('--mj-rows', String(sheet.rows));
        div.style.setProperty('--mj-col', String(pos.col));
        div.style.setProperty('--mj-row', String(pos.row));
    }

    /**
     * 表示を作り直す（横向きなど、作成後に変わった項目を反映する）。
     *
     * @param {number} cardId
     * @param {Partial<MjCard>} change
     */
    async updateCard(cardId, change) {
        const found = this.locate(cardId);
        if (found === null) {
            return;
        }
        const next = { ...found.card, ...change };
        const index = found.stock.getCards().findIndex((/** @type {MjCard} */ c) => c.id === cardId);
        await found.stock.removeCard(found.card);
        await found.stock.addCard(next, { index });
    }

    // ---- 拡大表示（009 §9.5） ---------------------------------------------

    /**
     * 英語の効果テキストなどのツールチップの中身。I-12 で実装する口（空なら画像だけを拡大する）。
     *
     * @param {number} _cardNo
     * @returns {string} HTML
     */
    getCardTooltipHtml(_cardNo) {
        return '';
    }

    setupZoom() {
        const board = /** @type {HTMLElement} */ (document.getElementById('mj-board'));
        const cardOf = (/** @type {EventTarget | null} */ target) => {
            const found = target instanceof Element ? target.closest('.mj-card') : null;
            return found instanceof HTMLElement && found.dataset.mjKey !== undefined ? found : null;
        };
        board.addEventListener('pointerover', (event) => {
            this.lastPointerType = event.pointerType;
            const cardEl = cardOf(event.target);
            if (event.pointerType === 'mouse' && cardEl !== null) {
                this.scheduleZoom(cardEl, 400);
            }
        });
        board.addEventListener('pointerout', (event) => {
            if (event.pointerType === 'mouse') {
                this.hideZoom();
            }
        });
        board.addEventListener('pointerdown', (event) => {
            this.lastPointerType = event.pointerType;
            const cardEl = cardOf(event.target);
            if (event.pointerType !== 'mouse' && cardEl !== null) {
                this.scheduleZoom(cardEl, 500); // 長押し
            }
        });
        for (const type of ['pointerup', 'pointercancel', 'pointermove']) {
            board.addEventListener(type, () => {
                if (this.lastPointerType !== 'mouse' && !this.zoomOpen) {
                    this.cancelZoomTimer();
                }
            });
        }
        // タッチ: 選択できないカードはタップでも開く。開いている間は、どこをタップしても閉じる
        document.addEventListener('click', (event) => {
            if (this.zoomOpen) {
                this.hideZoom();
                return;
            }
            const cardEl = cardOf(event.target);
            if (this.lastPointerType !== 'mouse' && cardEl !== null && !cardEl.classList.contains('mj-selectable')) {
                this.showZoom(cardEl);
            }
        }, true);

        // 拡大用シートの先読みは、初期表示が終わってからアイドル時間に行う
        const preload = () => {
            for (const sheet of Object.values(SHEETS.zoom.sheets)) {
                const image = new Image();
                image.onload = () => {
                    this.zoomReady[sheet.file] = true;
                };
                image.src = this.bga.images.getImgUrl(sheet.file);
            }
        };
        if (typeof requestIdleCallback === 'function') {
            requestIdleCallback(preload);
        } else {
            setTimeout(preload, 2000);
        }
    }

    /**
     * @param {HTMLElement} cardEl
     * @param {number} delay ミリ秒
     */
    scheduleZoom(cardEl, delay) {
        this.cancelZoomTimer();
        this.zoomTimer = setTimeout(() => this.showZoom(cardEl), delay);
    }

    cancelZoomTimer() {
        if (this.zoomTimer !== null) {
            clearTimeout(this.zoomTimer);
            this.zoomTimer = null;
        }
    }

    /**
     * @param {HTMLElement} cardEl
     */
    showZoom(cardEl) {
        this.cancelZoomTimer();
        const key = cardEl.dataset.mjKey;
        const zoom = document.getElementById('mj-zoom');
        const image = document.getElementById('mj-zoom-img');
        const text = document.getElementById('mj-zoom-text');
        if (key === undefined || zoom === null || image === null || text === null) {
            return;
        }
        // 拡大用シートの読み込みが終わっていなければ、通常のシートで代用する（009 エッジケース）
        const zoomPos = cardPosition(key, 'zoom');
        const zoomSheet = /** @type {{ file: string, cols: number, rows: number }} */ (/** @type {any} */ (SHEETS.zoom.sheets)[zoomPos.sheet]);
        const useZoom = this.zoomReady[zoomSheet.file] === true;
        const pos = useZoom ? zoomPos : cardPosition(key, 'base');
        const sheet = useZoom ? zoomSheet : /** @type {{ file: string, cols: number, rows: number }} */ (/** @type {any} */ (SHEETS.base.sheets)[pos.sheet]);
        image.style.backgroundImage = `url(${this.bga.images.getImgUrl(sheet.file)})`;
        image.style.setProperty('--mj-cols', String(sheet.cols));
        image.style.setProperty('--mj-rows', String(sheet.rows));
        image.style.setProperty('--mj-col', String(pos.col));
        image.style.setProperty('--mj-row', String(pos.row));
        text.innerHTML = this.getCardTooltipHtml(int(key.slice(0, 2)));

        zoom.hidden = false;
        zoom.setAttribute('aria-hidden', 'false');
        const rect = cardEl.getBoundingClientRect();
        const zoomRect = zoom.getBoundingClientRect();
        const left = Math.min(Math.max(8, rect.left + rect.width / 2 - zoomRect.width / 2), window.innerWidth - zoomRect.width - 8);
        const top = Math.min(Math.max(8, rect.top + rect.height / 2 - zoomRect.height / 2), Math.max(8, window.innerHeight - zoomRect.height - 8));
        zoom.style.left = `${left}px`;
        zoom.style.top = `${top}px`;
        this.zoomOpen = true;
    }

    hideZoom() {
        this.cancelZoomTimer();
        const zoom = document.getElementById('mj-zoom');
        if (zoom !== null) {
            zoom.hidden = true;
            zoom.setAttribute('aria-hidden', 'true');
        }
        this.zoomOpen = false;
    }

    // ---- 通知 -------------------------------------------------------------

    setupNotifications() {
        this.bga.notifications.setupPromiseNotifications({});
    }

    /**
     * 統計の一部を差分で更新する（通知の途中の見た目用。countersUpdate が最終的な値で上書きする）。
     *
     * @param {number} playerId
     * @param {Partial<Record<'deckCount' | 'facedownCount' | 'acquiredCount' | 'prepCount', number>>} delta
     */
    bumpStats(playerId, delta) {
        const s = this.stats[playerId];
        if (s === undefined) {
            return;
        }
        for (const [key, value] of Object.entries(delta)) {
            const k = /** @type {'deckCount' | 'facedownCount' | 'acquiredCount' | 'prepCount'} */ (key);
            s[k] = Math.max(0, s[k] + value);
        }
        this.syncPiles(playerId);
        this.refreshStats(playerId);
    }

    /**
     * 山札・裏向きの山の枚数表示を stats に合わせる。
     *
     * @param {number} playerId
     */
    syncPiles(playerId) {
        const s = this.stats[playerId];
        const area = this.areas[playerId];
        if (s === undefined || area === undefined) {
            return;
        }
        if (area.deck.getCardNumber() !== s.deckCount) {
            area.deck.setCardNumber(s.deckCount);
        }
        if (area.facedown.getCardNumber() !== s.facedownCount) {
            area.facedown.setCardNumber(s.facedownCount);
        }
    }

    /**
     * @param {{ round: number }} args
     */
    async notif_roundStarted(args) {
        this.gamedatas.round = args.round;
        this.chosen.clear();
        this.exploreOrder = [];
        this.exploreIndex = -1;
        for (const s of Object.values(this.stats)) {
            s.isHalved = false;
            s.output = null;
        }
        this.refreshAllStats();
    }

    /** 選択済みの印だけを出す（内容は載らない。007 N3） */
    async notif_prepMarked(/** @type {{ player_id: number }} */ args) {
        this.chosen.add(int(args.player_id));
        this.refreshStats(int(args.player_id));
    }

    /** 自分の他のタブの同期用（I-04 の状態ハンドラが表示する）。盤面には出さない */
    async notif_prepChoiceRecorded() {}

    async notif_prepRevealed() {
        this.chosen.clear();
        this.refreshAllStats();
    }

    /** 開示された選択。ログに出るだけで、盤面は cardDrawn などで更新する */
    async notif_choiceRevealed() {}

    async notif_prepFinishedCleared(/** @type {{ player_ids: number[] }} */ args) {
        for (const id of args.player_ids.map(int)) {
            if (this.stats[id] !== undefined) {
                this.stats[id].prepFinished = false;
            }
        }
    }

    /**
     * 山札の上から引いたカードを準備場に置く（N6）。
     *
     * @param {{ player_id: number, card: any, order: number }} args
     */
    async notif_cardDrawn(args) {
        const playerId = int(args.player_id);
        const area = this.areas[playerId];
        if (area === undefined) {
            return;
        }
        this.bumpStats(playerId, { deckCount: -1, prepCount: 1 });
        await area.prep.addCard(this.toCard(args.card, { order: int(args.order) }), { fromElement: area.deck.getElement() });
    }

    /**
     * 046 の付加カード（N14）。本体の直後に置く。
     *
     * @param {{ player_id: number, host_card_id: number, card: any }} args
     */
    async notif_attachedCardDrawn(args) {
        const playerId = int(args.player_id);
        const area = this.areas[playerId];
        if (area === undefined) {
            return;
        }
        const hostId = int(args.host_card_id);
        const host = this.locate(hostId);
        this.bumpStats(playerId, { deckCount: -1 });
        if (host !== null && host.card.hasAttached !== true) {
            await this.updateCard(hostId, { hasAttached: true });
        }
        const hostIndex = area.prep.getCards().findIndex((/** @type {MjCard} */ c) => c.id === hostId);
        const lastAttached = area.prep.getCards().reduce((/** @type {number} */ acc, /** @type {MjCard} */ c, /** @type {number} */ i) => (c.attachedTo === hostId ? i : acc), hostIndex);
        await area.prep.addCard(this.toCard(args.card, { attachedTo: hostId }), { index: lastAttached + 1, fromElement: area.deck.getElement() });
    }

    /** ストップしたプレイヤーが山札の上から伏せた 1 枚（中身は誰にも見えない。N7） */
    async notif_faceDownSet(/** @type {{ player_id: number }} */ args) {
        this.bumpStats(int(args.player_id), { deckCount: -1, facedownCount: 1 });
    }

    /** 1 人だけがドローしたカードを横向きにする（N8） */
    async notif_cardSideways(/** @type {{ player_id: number, card_id: number }} */ args) {
        const s = this.stats[int(args.player_id)];
        if (s !== undefined) {
            s.isHalved = true;
        }
        await this.updateCard(int(args.card_id), { sideways: true });
    }

    /**
     * 準備場から山札へ戻す（N12）。
     *
     * @param {{ player_id: number, card_id: number, attached_id?: number | null }} args
     */
    async notif_cardReturned(args) {
        const playerId = int(args.player_id);
        const area = this.areas[playerId];
        const ids = [int(args.card_id), ...(args.attached_id === null || args.attached_id === undefined ? [] : [int(args.attached_id)])];
        let removedPrep = 0;
        for (const id of ids) {
            const found = this.locate(id);
            if (found !== null && area !== undefined) {
                await found.stock.removeCard(found.card, { slideTo: area.deck.getElement() });
                removedPrep += found.stock === area.prep && found.card.attachedTo === undefined ? 1 : 0;
            }
        }
        this.bumpStats(playerId, { deckCount: ids.length, prepCount: -removedPrep });
    }

    async notif_deckShuffled(/** @type {{ player_id: number }} */ args) {
        await this.areas[int(args.player_id)]?.deck.shuffle();
    }

    /**
     * ゲームから除外（N20）。除外されたカードは消す。
     *
     * @param {{ player_id: number, card: any, attached_card?: any, from: string }} args
     */
    async notif_cardsRemoved(args) {
        const playerId = int(args.player_id);
        const cards = [args.card, args.attached_card].filter((c) => c !== null && c !== undefined);
        for (const raw of cards) {
            const found = this.locate(int(raw.id));
            if (found !== null) {
                await found.stock.removeCard(found.card, { fadeOut: true });
            }
        }
        const delta = /** @type {Record<string, Partial<Record<'deckCount' | 'facedownCount' | 'acquiredCount' | 'prepCount', number>>>} */ ({
            prep: { prepCount: -1 }, deck: { deckCount: -1 }, facedown: { facedownCount: -1 }, acquired: { acquiredCount: -1 },
        });
        this.bumpStats(playerId, delta[args.from] ?? {});
    }

    /**
     * 探査場の列にカードを足す（N21）。
     *
     * @param {{ cost: number, slot: number, card: any }} args
     */
    async notif_rowCardAdded(args) {
        await this.addRowCard(int(args.cost), int(args.slot), args.card);
    }

    /**
     * @param {{ cost: number, cards: { slot: number, card: any }[] }} args
     */
    async notif_rowRefilled(args) {
        const cost = int(args.cost);
        for (const entry of args.cards) {
            await this.addRowCard(cost, int(entry.slot), entry.card);
        }
    }

    /**
     * @param {number} cost
     * @param {number} slot
     * @param {any} raw
     */
    async addRowCard(cost, slot, raw) {
        const stock = this.rowStocks[cost];
        if (stock === undefined) {
            return;
        }
        if (!stock.getSlotsIds().includes(slot)) {
            stock.addSlotsIds([slot]); // 011 で規定枚数を超えるとき
        }
        this.pileCounts[cost] = Math.max(0, (this.pileCounts[cost] ?? 0) - 1);
        this.exploreDecks[cost].setCardNumber(this.pileCounts[cost]);
        this.updatePileMarks();
        await stock.addCard(this.toCard(raw, { slot }), { fromElement: this.exploreDecks[cost].getElement() });
    }

    /**
     * 036 の入れ替え（N17）。列のカードは山札へ、持ち主の山札の上のカードが列へ。
     *
     * @param {{ cost: number, slot: number, card: any, row_card_id: number }} args
     */
    async notif_wormholeSwapped(args) {
        const found = this.locate(int(args.row_card_id));
        if (found !== null) {
            await found.stock.removeCard(found.card, { fadeOut: true });
        }
        await this.rowStocks[int(args.cost)]?.addCard(this.toCard(args.card, { slot: int(args.slot) }));
    }

    /**
     * 探査での獲得（N25）。列・未確認帯域・探査山札の上から獲得カードへ飛ぶ。
     *
     * @param {{ player_id: number, card: any, from: string, cost?: number }} args
     */
    async notif_cardAcquired(args) {
        const playerId = int(args.player_id);
        const area = this.areas[playerId];
        if (area === undefined) {
            return;
        }
        const card = this.toCard(args.card);
        const cost = int(args.cost ?? 0);
        const fromDeck = args.from === 'deck' && this.exploreDecks[cost] !== undefined;
        if (fromDeck) {
            this.pileCounts[cost] = Math.max(0, (this.pileCounts[cost] ?? 0) - 1);
            this.exploreDecks[cost].setCardNumber(this.pileCounts[cost]);
            this.updatePileMarks();
        }
        this.bumpStats(playerId, { acquiredCount: 1 });
        await area.acquired.addCard(card, fromDeck ? { fromElement: this.exploreDecks[cost].getElement() } : {});
    }

    /** お守りの移動（N28） */
    async notif_amuletMoved(/** @type {{ player_id: number, card: any }} */ args) {
        const to = int(args.player_id);
        const from = this.charmHolder;
        this.charmHolder = to;
        await this.areas[to]?.charm.addCard(this.toCard(args.card));
        for (const id of [from, to]) {
            if (id !== null) {
                this.refreshStats(id);
            }
        }
    }

    /**
     * 帰還フェイズで全カードが山札へ戻る（N29）。
     *
     * @param {{ gathered: Record<string, number[]>, counts: Record<string, number> }} args
     */
    async notif_cardsGatheredToDecks(args) {
        const moves = [];
        for (const [pid, ids] of Object.entries(args.gathered)) {
            const area = this.areas[int(pid)];
            for (const id of ids.map(int)) {
                const found = this.locate(id);
                if (found !== null && area !== undefined) {
                    moves.push(found.stock.removeCard(found.card, { slideTo: area.deck.getElement() }));
                }
            }
        }
        await Promise.all(moves);
        for (const [pid, count] of Object.entries(args.counts)) {
            const s = this.stats[int(pid)];
            if (s !== undefined) {
                Object.assign(s, { deckCount: int(count), facedownCount: 0, acquiredCount: 0, prepCount: 0, outputNow: 0, output: null, isHalved: false });
                this.syncPiles(int(pid));
            }
        }
        this.refreshAllStats();
    }

    /**
     * 件数・探査出力の更新（N33）。変更があった者だけが来る。
     *
     * @param {{ players?: Record<string, Partial<MjStats>>, piles?: Record<string, number> }} args
     */
    async notif_countersUpdate(args) {
        for (const [pid, values] of Object.entries(args.players ?? {})) {
            Object.assign(this.gamedatas.players[pid] ?? {}, values); // I-05 由来: gamedatas も最新に保つ
            const s = this.stats[int(pid)];
            if (s === undefined) {
                continue;
            }
            for (const key of /** @type {const} */ (['deckCount', 'facedownCount', 'acquiredCount', 'prepCount', 'outputNow'])) {
                if (values[key] !== undefined) {
                    s[key] = int(values[key]);
                }
            }
            this.syncPiles(int(pid));
            this.refreshStats(int(pid));
        }
        for (const [cost, count] of Object.entries(args.piles ?? {})) {
            this.pileCounts[int(cost)] = int(count);
            this.exploreDecks[int(cost)]?.setCardNumber(int(count));
        }
        this.updatePileMarks();
    }

    /** 名声の合計（N34）。オプション有効時にプレイヤーにだけ届く */
    async notif_fameUpdate(/** @type {{ fame: Record<string, number> }} */ args) {
        for (const [pid, fame] of Object.entries(args.fame)) {
            if (this.stats[int(pid)] !== undefined) {
                this.stats[int(pid)].fame = int(fame);
                this.refreshStats(int(pid));
            }
        }
    }

    /** 最終得点（N35）。終了時は全員に公開する */
    async notif_finalScore(/** @type {{ player_id: number, fame: number }} */ args) {
        this.gameEnded = true;
        const s = this.stats[int(args.player_id)];
        if (s !== undefined) {
            s.fame = int(args.fame);
        }
        this.refreshAllStats();
    }

    async notif_exploreStarted() {
        this.exploreIndex = -1;
        this.refreshAllStats();
    }

    /** @param {{ player_id: number, output: number, halved: boolean }} args */
    async notif_exploreOutputDecided(args) {
        const s = this.stats[int(args.player_id)];
        if (s !== undefined) {
            s.output = int(args.output);
            s.isHalved = args.halved === true;
            this.refreshStats(int(args.player_id));
        }
    }

    /** @param {{ order: number[] }} args */
    async notif_exploreOrderDecided(args) {
        this.exploreOrder = args.order.map(int);
        this.refreshAllStats();
    }

    /** @param {{ player_id: number, index: number }} args */
    async notif_exploreTurnStarted(args) {
        this.exploreIndex = int(args.index);
        this.refreshAllStats();
    }

    async notif_explorePassed() {}

    // ログだけで盤面に反映しない通知（効果の UI は I-09 以降）
    async notif_effectSuppressed() {}

    async notif_podDecided() {}

    async notif_podKept() {}

    async notif_copySourceChosen() {}

    async notif_wormholeRevealed() {}

    async notif_laserSightLocked() {}

    async notif_deckLooked() {}

    async notif_nextChoiceRecorded() {}

    async notif_beaconPlaced() {}

    async notif_gameEndTriggered() {}
}
