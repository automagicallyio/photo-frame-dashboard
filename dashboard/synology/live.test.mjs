import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import vm from 'node:vm';
import { webcrypto } from 'node:crypto';
import { setTimeout as delay } from 'node:timers/promises';

const source = readFileSync(new URL('../js/live.js', import.meta.url), 'utf8').replace(/^import .+;\n/, '');
const scripts = name => readFileSync(new URL('../js/' + name, import.meta.url), 'utf8');
function fixture() {
  return {
    people: [{ id: 'household', name: 'Test household', color: '#2D5BFF' }],
    calendar: { events: [
      { id: 'a', title: 'All day', start: '2026-10-15', end: '2026-10-16', allDay: true, people: ['household'] }
    ] },
    photos: [], weather: null, errors: ['Weather: test unavailable'], updated: new Date().toISOString(),
    lists: { revision: 0, chores: [], groceries: [] }
  };
}
function setup(initial = fixture()) {
  const intervals = [], events = {}, calls = [];
  const notice = { querySelector: () => null };
  const stage = { appendChild() {}, style: { setProperty() {} } };
  const document = {
    getElementById: id => id === 'homehq-data' ? { textContent: JSON.stringify(initial) } : id === 'stage' ? stage : { setAttribute() {} },
    createElement: () => notice,
    querySelector: () => null, querySelectorAll: () => [],
    addEventListener() {}
  };
  const window = { DATA: { routines: [], stores: [], catOrder: {}, catalog: [], usuals: [] }, addEventListener() {} };
  const context = vm.createContext({ window, document, console: { error() {} }, crypto: webcrypto, Uint8Array,
    AbortSignal, setTimeout, clearTimeout, setInterval: fn => intervals.push(fn), Date,
    fetch: async (url, options) => { calls.push([url, options]); return { ok: true, json: async () => ({ revision: calls.length }) }; }
  });
  vm.runInContext(source, context);
  const App = {
    on: (name, fn) => (events[name] ||= []).push(fn),
    emit: name => (events[name] || []).forEach(fn => fn()),
    esc: String, rerender() {}
  };
  window.HOMEHQ_CONNECT(App);
  return { context, window, document, App, notice, calls, intervals };
}
test('default demo is untouched without a NAS bootstrap', () => {
  const DATA = { demo: true };
  vm.runInNewContext(source, { window: { DATA }, document: { getElementById: () => null } });
  assert.deepEqual(DATA, { demo: true });
});
test('live mode replaces mock family and data; all-day dates stay local', () => {
  const { window } = setup();
  const D = window.DATA;
  assert.equal(D.live, true);
  assert.equal(D.people[0].name, 'Test household');
  assert.equal(D.events[0].start.getDate(), 15);
  assert.equal(D.events[0].start.getHours(), 0);
  assert.equal(D.photos.length, 0);
  assert.equal(D.devices, undefined);
  assert.match(D._gid(), /^g-[a-f0-9]{32}$/);
});
test('list updates persist with revision and refresh does not trigger a save', async () => {
  const { window, App, calls, intervals, context } = setup();
  App.emit('ready');
  window.DATA.groceries.push({ id: 'g1', name: 'Test' });
  App.emit('data');
  await delay(250);
  assert.equal(calls.length, 1);
  assert.equal(JSON.parse(calls[0][1].body).revision, 0);
  window.DATA.groceries[0].qty = 2;
  App.emit('data');
  await delay(250);
  assert.equal(JSON.parse(calls[1][1].body).revision, 1);
  context.fetch = async () => ({ ok: true, json: async () => ({ ...fixture(), lists: { revision: 5, chores: [], groceries: [] } }) });
  await intervals[0]();
  await delay(250);
  assert.equal(calls.length, 2);
  assert.equal(window.DATA.groceries.length, 0);
});
test('conflict is visible and prevents subsequent overwrites', async () => {
  const state = setup();
  state.context.fetch = async () => ({ ok: false, status: 409, json: async () => ({ error: 'Conflict; reload.' }) });
  state.App.emit('data');
  await delay(250);
  assert.match(state.notice.innerHTML, /SAVE NOT CONFIRMED.*Conflict/);
  state.App.emit('data');
  await delay(250);
  assert.equal(state.notice.hidden, false);
});
test('small live albums do not crash pair or collage layouts', () => {
  const state = setup();
  const App = state.App;
  const D = state.window.DATA;
  const context = vm.createContext({ window: state.window, App, setTimeout, clearInterval, setInterval, document: state.document });
  vm.runInContext(scripts('slideshow.js'), context);
  D.photos = [{ seed: 'one', o: 'l', dateKind: 'unknown', url: 'photo.php?id=one' }];
  const slide = new App.Slideshow({}, { layout: 'pair' }).pick();
  assert.equal(slide.layout, 'cover');
  const collage = new App.Slideshow({}, { layout: 'collage' }).pick();
  assert.equal(collage.layout, 'cover');
  assert.equal(App.photoSrc(D.photos[0]), 'photo.php?id=one');
});
test('live shell starts on real time, disables fake views, and keeps forecast condition labels', () => {
  const state = setup();
  const element = { dataset: {}, classList: { contains: () => false }, style: { setProperty() {} }, addEventListener() {} };
  state.document.querySelector = () => element;
  state.window.innerWidth = 1280;
  state.window.innerHeight = 800;
  state.context.location = { hash: '', search: '' };
  state.context.history = { replaceState() {} };
  state.context.setTimeout = () => 0;
  vm.runInContext(scripts('app.js'), state.context);
  const App = state.window.App;
  App.register('home', { show() {} });
  App.register('control', { show() { throw new Error('Fake controls must not render.'); } });
  App.start();
  assert.ok(Math.abs(+App.now() - Date.now()) < 1000);
  assert.equal(App.views.control, undefined);
  App.go('control');
  assert.equal(App.current, 'home');
  state.window.DATA.weather = {
    name: 'Test city', hi: 15, lo: 6,
    hours: [{ time: Date.now() - 1800000, t: 12, p: 0, c: 'cloud', label: 'Overcast' }]
  };
  assert.equal(App.weatherNow().label, 'Overcast');
  assert.equal(App.hourly(1)[0].label, 'Now');
});
