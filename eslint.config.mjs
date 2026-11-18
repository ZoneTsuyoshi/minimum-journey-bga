// J1・D1: JavaScript の lint と JSONC の構文検査（仕様書 004 第 2.5・2.8 節）。実行: npm run lint:js
import js from '@eslint/js';
import json from '@eslint/json';
import globals from 'globals';
import { defineConfig, globalIgnores } from 'eslint/config';

// BGA が提供するグローバル（仕様書 004 第 2.5 節）。型チェック側は tools/bga/bga-framework.d.ts が宣言する
const bgaGlobals = {
  _: 'readonly',
  $: 'readonly',
  dojo: 'readonly',
  ebg: 'readonly',
  define: 'readonly',
  gameui: 'readonly',
  getLibUrl: 'readonly',
  importDojoLibs: 'readonly',
  importEsmLib: 'readonly',
  g_gamethemeurl: 'readonly',
  g_replayFrom: 'readonly',
  g_archive_mode: 'readonly',
  g_tutorialwritten: 'readonly',
};

export default defineConfig([
  globalIgnores(['node_modules/', 'vendor/', 'data/', '.worktrees/', 'build/', 'src/img/', 'tools/bga/']),

  {
    files: ['**/*.{js,mjs}'],
    extends: [js.configs.recommended],
    rules: {
      eqeqeq: 'error',
      'no-var': 'error',
      'prefer-const': 'error',
      'no-shadow': 'error',
      'no-unused-vars': ['error', { argsIgnorePattern: '^_', varsIgnorePattern: '^_' }],
    },
  },

  // BGA のゲーム本体（ブラウザ上で動く ES モジュール）
  {
    files: ['src/modules/js/**/*.js'],
    languageOptions: {
      ecmaVersion: 'latest',
      sourceType: 'module',
      globals: { ...globals.browser, ...bgaGlobals },
    },
    rules: {
      // dojo は非推奨（仕様書 003 第 1.1 節）。初期生成コードが使う可能性があるため警告にとどめる
      'no-restricted-globals': ['warn', { name: 'dojo', message: 'dojo は非推奨です。this.bga の API を使ってください。' }],
    },
  },

  // ローカルのプレビュー（ブラウザ側。BGA のグローバルを模す）
  {
    files: ['scripts/preview/**/*.js'],
    languageOptions: {
      ecmaVersion: 'latest',
      sourceType: 'module',
      globals: { ...globals.browser, ...bgaGlobals },
    },
  },

  // Node スクリプトと設定ファイル
  {
    files: ['scripts/**/*.mjs', '*.config.mjs'],
    languageOptions: { globals: globals.node },
  },

  // shoot.mjs は page.evaluate の中でブラウザ側のコードを書く
  {
    files: ['scripts/preview/shoot.mjs'],
    languageOptions: { globals: { ...globals.node, document: 'readonly' } },
  },

  // JSONC（gameinfos.jsonc など）。構文と重複キーのみ検査する。末尾カンマは許可しない
  {
    files: ['src/**/*.{json,jsonc}'],
    plugins: { json },
    language: 'json/jsonc',
    extends: [json.configs.recommended],
  },
]);
