import '../css/live.css';

(function () {
  'use strict';
  var bootstrap = document.getElementById('homehq-data');
  if (!bootstrap) return;
  var initial = JSON.parse(bootstrap.textContent), demo = window.DATA;
  var D = window.DATA = {
    live: true,
    family: { id: 'family', name: 'Household', color: '#111111', rgb: '17 17 17', ink: '#FFFFFF' },
    people: initial.people.map(function (p) {
      var rgb = p.color.slice(1).match(/../g).map(function (x) { return parseInt(x, 16); });
      return Object.assign({}, p, { rgb: rgb.join(' '), ink: '#FFFFFF', status: 'Calendar' });
    }),
    routines: demo.routines, stores: [{ id: 'grocer', name: 'Groceries' }, { id: 'costco', name: 'Bulk store' }],
    catOrder: demo.catOrder, catalog: demo.catalog, usuals: demo.usuals,
    stars: {}, week: {}, busyExtras: [], events: [], photos: [], weather: null
  };
  // crypto.randomUUID requires HTTPS; the NAS can be LAN-only HTTP.
  D._gid = function () {
    return 'g-' + Array.from(crypto.getRandomValues(new Uint8Array(16)), function (n) { return n.toString(16).padStart(2, '0'); }).join('');
  };
  D.ALL = D.people.map(function (p) { return p.id; });

  function eventDate(value, allDay) {
    if (!allDay) return new Date(value);
    var parts = value.split('-');
    return new Date(+parts[0], +parts[1] - 1, +parts[2]);
  }
  function sources(data) {
    D.events = data.calendar ? data.calendar.events.map(function (e) {
      return Object.assign({}, e, { start: eventDate(e.start, e.allDay), end: eventDate(e.end, e.allDay) });
    }) : [];
    D.calendar = data.calendar;
    D.weather = data.weather;
    D.photos = data.photos || [];
    D.updated = data.updated;
    D.errors = data.errors;
  }
  sources(initial);
  D.chores = initial.lists.chores;
  D.groceries = initial.lists.groceries;
  var revision = initial.lists.revision;

  window.HOMEHQ_CONNECT = function (App) {
    var notice = document.createElement('details');
    notice.className = 'live-notice';
    document.getElementById('stage').appendChild(notice);
    var saving = false, pending = null, failed = false, status = '', applying = false, timer;

    function announce() {
      var errors = D.errors.slice();
      if (status) errors.unshift(status);
      notice.hidden = !errors.length;
      notice.innerHTML = '<summary>' + App.esc(errors[0] || '') +
        (errors.length > 1 ? ' (+' + (errors.length - 1) + ')' : '') + '</summary>' +
        errors.map(function (e) { return '<p>' + App.esc(e) + '</p>'; }).join('') +
        (failed ? '<button class="btn" data-reload>Reload from NAS</button>' : '');
      var reload = notice.querySelector('[data-reload]');
      if (reload) reload.onclick = function () {
        if (confirm('Discard unsaved local changes and reload the NAS copy?')) location.reload();
      };
    }
    async function request(options) {
      var response = await fetch('api.php', Object.assign({}, options, {
        headers: { 'X-HomeHQ': '1', 'Content-Type': 'application/json' },
        signal: AbortSignal.timeout(30000)
      }));
      var data = await response.json();
      if (!response.ok) throw new Error(data.error || 'NAS request failed (' + response.status + ').');
      return data;
    }
    async function save() {
      if (saving || !pending || failed) return;
      saving = true;
      var snapshot = pending;
      pending = null;
      status = 'Saving lists to NAS...'; announce();
      try {
        var result = await request({ method: 'POST', body: JSON.stringify(Object.assign({ revision: revision }, snapshot)) });
        revision = result.revision;
        status = '';
      } catch (error) {
        failed = true;
        status = 'SAVE NOT CONFIRMED: ' + error.message + ' Reload to recover the NAS copy.';
        console.error('Home HQ list save failed:', error);
      } finally {
        saving = false; announce();
        if (pending && !failed) save();
      }
    }
    App.on('data', function () {
      if (applying || failed) return;
      pending = JSON.parse(JSON.stringify({ chores: D.chores, groceries: D.groceries }));
      clearTimeout(timer);
      timer = setTimeout(save, 200);
    });
    async function refresh() {
      if (saving || pending || failed || document.querySelector('input:focus, .sheet-layer.is-open, .toast')) return;
      try {
        var data = await request();
        if (saving || pending || failed || document.querySelector('input:focus, .sheet-layer.is-open, .toast')) return;
        var before = D.photos.map(function (p) { return p.seed; }).join(',');
        sources(data);
        if (revision !== data.lists.revision) {
          D.chores = data.lists.chores; D.groceries = data.lists.groceries;
          revision = data.lists.revision;
        }
        applying = true;
        try {
          App.emit('data');
          if (before !== D.photos.map(function (p) { return p.seed; }).join(',')) App.emit('photos');
          App.rerender();
        } finally { applying = false; }
        status = '';
      } catch (error) {
        status = 'NAS refresh failed; showing the previous data. ' + error.message;
        console.error('Home HQ refresh failed:', error);
      }
      announce();
    }
    App.photoError = function () {
      status = 'A photo could not load. Check the NAS photo permissions and PHP image extensions.';
      announce();
    };
    App.addChore = function (routine) {
      App.sheet('<form class="sheet-pad"><h2 class="serif sheet-h">Add a daily chore</h2>' +
        '<label class="label">Chore <input class="live-input" name="title" required maxlength="160"></label>' +
        '<label class="label">Who <select class="live-input" name="who">' +
        D.people.concat([{ id: 'any', name: 'Anyone' }]).map(function (p) {
          return '<option value="' + p.id + '">' + App.esc(p.name) + '</option>';
        }).join('') + '</select></label><button class="btn" type="submit">Add chore</button></form>', {
        onMount: function (el, close) {
          el.querySelector('form').onsubmit = function (event) {
            event.preventDefault();
            var form = event.target, title = form.elements.title.value.trim();
            if (!title) return;
            D.chores.push({ id: D._gid(), title: title, who: form.elements.who.value, r: routine, emoji: '✓', stars: 0, done: false });
            close(); App.rerender(); App.emit('data');
          };
        }
      });
    };
    App.removeChore = function (id) {
      var chore = D.chores.find(function (c) { return c.id === id; });
      if (chore && confirm('Delete daily chore "' + chore.title + '"?')) {
        D.chores = D.chores.filter(function (c) { return c.id !== id; });
        App.rerender(); App.emit('data');
      }
    };
    App.on('ready', function () {
      document.querySelectorAll('[data-go="control"], [data-go="agents"]').forEach(function (el) { el.hidden = true; });
      document.getElementById('railClock').setAttribute('aria-label', 'Current time');
      announce();
      setInterval(refresh, 60000);
    });
    window.addEventListener('beforeunload', function (e) {
      if (saving || pending || failed) { e.preventDefault(); e.returnValue = ''; }
    });
  };
})();
