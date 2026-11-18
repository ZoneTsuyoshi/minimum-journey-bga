// BGA の API を模して Game.js をブラウザで表示するプレビュー（実行方法は README.md）。
// URL の引数: fixture（固定データの名前）、theme（light / dark）、spectator=1、animations=1、touch=0/1

/** この幅（px）未満をモバイル表示にする。BGA の実際の境目は未確認なのでここで仮置きしている */
const MOBILE_MAX_WIDTH = 980;

const params = new URLSearchParams(location.search);
const fixtureName = params.get('fixture') ?? '4p-prep-late';
const theme = params.get('theme') === 'dark' ? 'dark' : 'light';
const spectator = params.get('spectator') === '1';
const animations = params.get('animations') === '1';

const root = document.documentElement;
root.dataset.theme = theme;

/** @param {unknown} error */
function showError(error) {
  const message = error instanceof Error ? `${error.message}\n${error.stack ?? ''}` : String(error);
  const bar = /** @type {HTMLElement} */ (document.getElementById('pv-error'));
  bar.hidden = false;
  bar.textContent += `${message}\n`;
  root.dataset.error = `${root.dataset.error ?? ''}${message}\n`;
}
window.addEventListener('error', (event) => showError(event.error ?? event.message));
window.addEventListener('unhandledrejection', (event) => showError(event.reason));

/** @param {string} text */
const escapeHtml = (text) => text.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c] ?? c);

// ---- body のクラス（BGA と同じ名前）----
const touchParam = params.get('touch');
const isTouch = touchParam === null ? navigator.maxTouchPoints > 0 || 'ontouchstart' in window : touchParam === '1';
function updateBodyClasses() {
  const mobile = window.innerWidth < MOBILE_MAX_WIDTH;
  document.body.classList.toggle('mobile_version', mobile);
  document.body.classList.toggle('desktop_version', !mobile);
  document.body.classList.toggle('touch-device', isTouch);
  document.body.classList.toggle('notouch-device', !isTouch);
}
updateBodyClasses();
window.addEventListener('resize', updateBodyClasses);

// ---- グローバル（BGA が提供するもの）----
Object.assign(window, {
  _: (/** @type {string} */ text) => text,
  importEsmLib: (/** @type {string} */ name, /** @type {string} */ version) =>
    import(/* @vite-ignore */ `https://x.boardgamearena.net/data/game-libs/${name}/${version}/dist/${name}.esm.js`),
});

/** @type {{ me: number, players: Record<string, { name: string, color: string }>, state: { name: string, active: boolean, args: any, actplayer?: number } | null, replay?: { name: string, args: any }[] }} */
let meta;
/** @type {Record<string, any>} */
const registered = {};
/** @type {{ name: string, args: unknown }[]} */
const actions = [];
/** @type {any[]} */
window.__mjActions = actions;

/** @param {number | string} id */
function playerNameHtml(id) {
  const p = meta.players[String(id)];
  return p === undefined ? String(id) : `<span class="pv-player-name" style="font-weight:bold;color:#${p.color}">${escapeHtml(p.name)}</span>`;
}

const titleEl = /** @type {HTMLElement} */ (document.getElementById('pv-title'));
const buttonsEl = /** @type {HTMLElement} */ (document.getElementById('pv-buttons'));
const gameArea = /** @type {HTMLElement} */ (document.getElementById('pv-gamearea'));
const panelsEl = /** @type {HTMLElement} */ (document.getElementById('pv-panels'));

const bga = {
  states: {
    register(/** @type {string} */ name, /** @type {any} */ handler) {
      registered[name] = handler;
    },
  },
  gameArea: {
    getElement: () => gameArea,
    addLastTurnBanner(/** @type {string} */ message) {
      this.removeLastTurnBanner();
      const banner = document.createElement('div');
      banner.id = 'pv-lastturn';
      banner.className = 'pv-lastturn';
      banner.textContent = message;
      gameArea.insertAdjacentElement('afterbegin', banner);
    },
    removeLastTurnBanner() {
      document.getElementById('pv-lastturn')?.remove();
    },
  },
  players: {
    getCurrentPlayerId: () => (spectator ? 9999999 : meta.me),
    isCurrentPlayerSpectator: () => spectator,
    getFormattedPlayerName: (/** @type {number} */ id) => playerNameHtml(id),
    getPlayerAvatarUrl: () => 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 1 1%22%3E%3Crect width=%221%22 height=%221%22 fill=%22%23888%22/%3E%3C/svg%3E',
  },
  playerPanels: {
    getElement: (/** @type {number} */ id) => /** @type {HTMLElement} */ (document.getElementById(`pv-panel-body-${id}`)),
  },
  statusBar: {
    setTitle(/** @type {string} */ text) {
      const others = Object.keys(meta.players).map(Number).filter((id) => id !== meta.me);
      const actId = meta.state?.actplayer ?? (meta.state?.active === true ? meta.me : (others[0] ?? meta.me));
      const you = `<span style="font-weight:bold;color:#${meta.players[String(meta.me)]?.color ?? '000'}">You</span>`;
      titleEl.innerHTML = text.replaceAll('${you}', you).replaceAll('${actplayer}', playerNameHtml(actId));
    },
    addActionButton(
      /** @type {string} */ label,
      /** @type {(event: Event) => unknown} */ callback,
      /** @type {{ color?: string, disabled?: boolean, tooltip?: string, id?: string }} */ options = {},
    ) {
      const color = options.color ?? 'primary';
      const button = document.createElement('button');
      button.type = 'button';
      button.className = `pv-button pv-button-${color}`;
      button.innerHTML = label;
      if (options.id !== undefined) {
        button.id = options.id;
      }
      if (options.tooltip !== undefined) {
        button.title = options.tooltip;
      }
      if (options.disabled === true) {
        button.disabled = true;
        button.classList.add('pv-disabled');
      }
      button.addEventListener('click', (event) => {
        Promise.resolve()
          .then(() => callback(event))
          .catch(showError);
      });
      buttonsEl.appendChild(button);
      return button;
    },
    removeActionButtons() {
      buttonsEl.replaceChildren();
    },
  },
  actions: {
    performAction(/** @type {string} */ name, /** @type {unknown} */ args = {}) {
      console.log('performAction', name, args);
      actions.push({ name, args });
      return Promise.resolve();
    },
  },
  notifications: { setupPromiseNotifications() {} },
  images: {
    getImgUrl: (/** @type {string} */ file) => `/src/img/${file}`,
    dontPreloadImages() {},
  },
  gameui: {
    bgaAnimationsActive: () => animations,
    // 実際のツールチップは BGA の部品。プレビューでは title で代用する
    addTooltip(/** @type {string} */ id, /** @type {string} */ help) {
      document.getElementById(id)?.setAttribute('title', help);
    },
  },
};

async function main() {
  if (!/^[\w-]+$/.test(fixtureName)) {
    throw new Error(`fixture の名前が不正です: ${fixtureName}`);
  }
  const response = await fetch(`/scripts/preview/fixtures/${fixtureName}.json`);
  if (!response.ok) {
    throw new Error(`固定データを読めません: ${fixtureName}（${response.status}）`);
  }
  const fixture = await response.json();
  meta = fixture.preview;
  const gamedatas = fixture.gamedatas;
  if (gamedatas.cardInfo === undefined) {
    gamedatas.cardInfo = await (await fetch('/scripts/preview/fixtures/card-info.json')).json();
  }
  const state = meta.state;
  if (state !== null) {
    // Game.setup が gamedatas.gamestate.args.chosen を読む
    gamedatas.gamestate = { name: state.name, args: state.args };
  }

  // プレイヤーパネル: 自分を先頭に、席順で続ける（観戦者は席順のまま）
  const order = /** @type {number[]} */ (gamedatas.playerOrder);
  const myIndex = order.indexOf(meta.me);
  const panelOrder = spectator || myIndex < 0 ? order : [...order.slice(myIndex), ...order.slice(0, myIndex)];
  for (const id of panelOrder) {
    panelsEl.insertAdjacentHTML('beforeend', `<div class="pv-panel" id="pv-panel-${id}">
      <div class="pv-panel-name">${playerNameHtml(id)}</div>
      <div id="pv-panel-body-${id}" class="pv-panel-body"></div>
    </div>`);
  }

  const { Game } = await import('/src/modules/js/Game.js');
  const game = new Game(bga);
  game.setup(gamedatas);
  for (const { name, args } of meta.replay ?? []) {
    await game[`notif_${name}`]?.(args);
  }
  if (state !== null) {
    const handler = registered[state.name];
    if (handler === undefined) {
      throw new Error(`状態 ${state.name} は登録されていません`);
    }
    const active = !spectator && state.active;
    await handler.onEnteringState(state.args, active);
  }
  window.__mjGame = game;

  await document.fonts.ready;
  await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
  root.dataset.ready = '1';
}

main().catch(showError);
