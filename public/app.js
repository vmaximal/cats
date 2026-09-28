const state = {
  cats: [],
  all: [],
  litters: [],
  litterDetails: [],
  filters: { sex: '', min_age: '', max_age: '' }
};

const $ = (sel) => document.querySelector(sel);
const $$ = (sel) => Array.from(document.querySelectorAll(sel));
const field = (form, name) => form.querySelector('[name="' + name + '"]');

function esc(value) {
  return String(value ?? '').replace(/[&<>"']/g, (ch) => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]
  ));
}

function ageText(age) {
  age = Number(age) || 0;

  if (age === 0) {
    return 'котёнок';
  }

  const last = age % 10;
  const hundred = age % 100;

  if (last === 1 && hundred !== 11) {
    return age + ' год';
  }

  if ([2, 3, 4].includes(last) && ![12, 13, 14].includes(hundred)) {
    return age + ' года';
  }

  return age + ' лет';
}

function sexBadge(sex) {
  return '<span class="sex ' + esc(sex) + '">' + (sex === 'F' ? '♀ самка' : '♂ самец') + '</span>';
}

function pill(cat) {
  return '<span class="pill" data-cat="' + cat.id + '">'
    + '<span class="dot ' + esc(cat.sex) + '"></span>' + esc(cat.name) + '</span>';
}

function litterName(detail) {
  const litter = detail.litter;
  const title = litter.name || 'Помёт #' + litter.id;
  return detail.mother ? title + ' · мать ' + detail.mother.name : title;
}

async function api(method, path, body) {
  const response = await fetch(path, {
    method,
    headers: body ? { 'Content-Type': 'application/json' } : {},
    body: body ? JSON.stringify(body) : undefined
  });

  const data = await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new Error(data.error || 'сервер не понял запрос');
  }

  return data;
}

function toast(text, bad) {
  const el = document.createElement('div');
  el.className = 'toast' + (bad ? ' bad' : '');
  el.textContent = text;
  $('#toasts').appendChild(el);
  setTimeout(() => el.remove(), 3200);
}

function fail(error) {
  toast(error.message, true);
}

async function loadCats() {
  const query = new URLSearchParams();

  Object.entries(state.filters).forEach(([key, value]) => {
    if (value !== '') {
      query.set(key, value);
    }
  });

  const [shown, all] = await Promise.all([
    api('GET', '/api/cats' + (query.toString() ? '?' + query : '')),
    api('GET', '/api/cats')
  ]);

  state.cats = shown.cats;
  state.all = all.cats;
  $('#catsCount').textContent = shown.cats.length;
  $('#catsEmpty').hidden = shown.cats.length > 0;
  $('#cats').innerHTML = shown.cats.map(catCard).join('');
}

async function loadLitters() {
  const data = await api('GET', '/api/litters');
  const details = await Promise.all(data.litters.map((litter) => api('GET', '/api/litters/' + litter.id)));

  state.litters = data.litters;
  state.litterDetails = details;
  $('#littersCount').textContent = data.litters.length;
  $('#littersEmpty').hidden = details.length > 0;
  $('#litters').innerHTML = details.map(litterCard).join('');
}

function refresh() {
  return Promise.all([loadCats(), loadLitters()]);
}

function catCard(cat) {
  return '<article class="card" data-cat="' + cat.id + '">'
    + '<div class="card-head">'
    + '<div>'
    + '<div class="card-name">' + esc(cat.name) + '</div>'
    + '<div class="card-age">' + ageText(cat.age) + '</div>'
    + '</div>'
    + sexBadge(cat.sex)
    + '</div>'
    + (cat.breed ? '<div class="card-extra"><span>порода:</span> ' + esc(cat.breed) + '</div>' : '')
    + '<div class="card-actions">'
    + '<button class="btn small" data-edit="' + cat.id + '">изменить</button>'
    + '<button class="btn small danger" data-del="' + cat.id + '">удалить</button>'
    + '</div>'
    + '</article>';
}

function litterCard(detail) {
  const litter = detail.litter;
  const used = new Set(detail.sires.map((sire) => sire.id));
  const candidates = state.all.filter((cat) => cat.sex === 'M' && !used.has(cat.id));

  const sires = detail.sires.length
    ? detail.sires.map((sire) => '<span class="pill locked">'
        + '<span class="dot ' + esc(sire.sex) + '"></span>'
        + '<span data-cat="' + sire.id + '">' + esc(sire.name) + '</span>'
        + '<button class="x" data-unsire="' + sire.id + '" data-litter="' + litter.id + '" title="убрать отца">✕</button>'
        + '</span>').join('')
    : '<span class="muted">не указаны</span>';

  const picker = candidates.length
    ? '<div class="row tight picker">'
      + '<select data-sires-for="' + litter.id + '">'
      + candidates.map((cat) => '<option value="' + cat.id + '">' + esc(cat.name) + '</option>').join('')
      + '</select>'
      + '<button class="btn small" data-addsire="' + litter.id + '">добавить</button>'
      + '</div>'
    : '';

  return '<article class="card">'
    + '<div class="card-head">'
    + '<div>'
    + '<div class="card-name">' + esc(litter.name || 'Помёт #' + litter.id) + '</div>'
    + '<div class="card-age">мать: ' + (detail.mother
        ? '<span data-cat="' + detail.mother.id + '">' + esc(detail.mother.name) + '</span>'
        : '—') + '</div>'
    + '</div>'
    + '<span class="badge">' + detail.kittens.length + ' ' + (detail.kittens.length === 1 ? 'котёнок' : 'котят') + '</span>'
    + '</div>'
    + '<div class="block"><h3>отцы</h3><div class="pills">' + sires + '</div>' + picker + '</div>'
    + '<div class="block"><h3>котята</h3><div class="pills">'
    + (detail.kittens.length ? detail.kittens.map(pill).join('') : '<span class="muted">пока нет</span>')
    + '</div></div>'
    + '<div class="card-actions">'
    + '<button class="btn small danger" data-dellitter="' + litter.id + '">удалить помёт</button>'
    + '</div>'
    + '</article>';
}

function openModal(title, body, foot) {
  $('#modalTitle').textContent = title;
  $('#modalBody').innerHTML = body;
  $('#modalFoot').innerHTML = foot || '';
  $('#modal').hidden = false;
}

function closeModal() {
  $('#modal').hidden = true;
  $('#modalBody').innerHTML = '';
  $('#modalFoot').innerHTML = '';
}

function sexSelect(sex) {
  if (sex) {
    return '<option value="F"' + (sex === 'F' ? ' selected' : '') + '>самка</option>'
      + '<option value="M"' + (sex === 'M' ? ' selected' : '') + '>самец</option>';
  }

  return '<option value="" selected>выбери</option>'
    + '<option value="F">самка</option>'
    + '<option value="M">самец</option>';
}

function catForm(cat) {
  const editing = cat !== null && cat !== undefined;
  const options = ['<option value="">без помёта</option>']
    .concat(state.litterDetails.map((detail) => '<option value="' + detail.litter.id + '"'
      + (editing && cat.litter_id === detail.litter.id ? ' selected' : '') + '>'
      + esc(litterName(detail)) + '</option>'))
    .join('');

  openModal(
    editing ? 'Изменить кошку' : 'Новая кошка',
    '<form class="form" id="catForm" data-id="' + (editing ? cat.id : '') + '">'
    + '<div class="form-row">'
    + '<div class="field"><label for="cfName">кличка</label>'
    + '<input id="cfName" name="name" maxlength="60" required value="' + esc(editing ? cat.name : '') + '"></div>'
    + '<div class="field"><label for="cfSex">пол</label>'
    + '<select id="cfSex" name="sex" required>' + sexSelect(editing ? cat.sex : '') + '</select></div>'
    + '</div>'
    + '<div class="form-row">'
    + '<div class="field narrow"><label for="cfAge">возраст, лет</label>'
    + '<input id="cfAge" name="age" type="number" min="0" max="30" required value="' + (editing ? cat.age : 0) + '"></div>'
    + '<div class="field"><label for="cfBreed">порода</label>'
    + '<input id="cfBreed" name="breed" maxlength="80" value="' + esc(editing ? cat.breed : '') + '" placeholder="не указана"></div>'
    + '</div>'
    + '<div class="field"><label for="cfLitter">помёт</label>'
    + '<select id="cfLitter" name="litter_id">' + options + '</select></div>'
    + '</form>',
    '<button class="btn" data-close>отмена</button>'
    + '<button class="btn primary" id="saveCat">сохранить</button>'
  );

  markRequired($('#catForm'));
  $('#cfName').focus();
}

function markRequired(form) {
  let marked = 0;

  form.querySelectorAll('[required]').forEach((input) => {
    if (input.disabled) {
      return;
    }

    const label = form.querySelector('label[for="' + input.id + '"]');

    if (label) {
      label.insertAdjacentHTML('beforeend', ' <span class="req">*</span>');
    }

    marked++;
  });

  if (marked) {
    form.insertAdjacentHTML('afterbegin', '<p class="muted hint">* — обязательные поля</p>');
  }
}

function clearErrors(form) {
  form.querySelectorAll('.field.invalid').forEach((wrap) => wrap.classList.remove('invalid'));
  form.querySelectorAll('.err').forEach((hint) => hint.remove());
}

function showError(input, message) {
  const wrap = input.closest('.field');
  const hint = document.createElement('small');

  wrap.classList.add('invalid');
  hint.className = 'err';
  hint.textContent = message;
  wrap.appendChild(hint);
}

function checkForm(form) {
  const problems = [];

  form.querySelectorAll('[required]').forEach((input) => {
    const value = input.value.trim();

    if (input.disabled || value === '') {
      if (!input.disabled) {
        problems.push([input, 'это поле обязательное']);
      }

      return;
    }

    if (input.type === 'number' && (!Number.isInteger(Number(value)) || Number(value) < 0)) {
      problems.push([input, 'нужно целое число от 0']);
    }
  });

  clearErrors(form);

  if (!problems.length) {
    return true;
  }

  problems.forEach(([input, message]) => showError(input, message));
  problems[0][0].focus();
  toast('Заполни обязательные поля', true);

  return false;
}

async function submitCat() {
  const form = $('#catForm');

  if (!checkForm(form)) {
    return;
  }

  const id = form.dataset.id;
  const litterId = field(form, 'litter_id').value;

  const payload = {
    name: field(form, 'name').value.trim(),
    sex: field(form, 'sex').value,
    age: Number(field(form, 'age').value),
    breed: field(form, 'breed').value.trim(),
    litter_id: litterId === '' ? null : Number(litterId)
  };

  try {
    if (id) {
      await api('PUT', '/api/cats/' + id, payload);
    } else {
      await api('POST', '/api/cats', payload);
    }

    closeModal();
    toast(id ? 'Кошка обновлена' : 'Кошка добавлена');
    await refresh();
  } catch (error) {
    fail(error);
  }
}

function litterForm() {
  const mothers = state.all.filter((cat) => cat.sex === 'F');
  const options = mothers.length
    ? mothers.map((cat) => '<option value="' + cat.id + '">' + esc(cat.name) + ' · ' + ageText(cat.age) + '</option>').join('')
    : '<option value="">сначала добавь самку</option>';

  openModal(
    'Новый помёт',
    '<form class="form" id="litterForm">'
    + '<div class="field"><label for="lfMother">мать</label>'
    + '<select id="lfMother" name="mother_id" required' + (mothers.length ? '' : ' disabled') + '>' + options + '</select></div>'
    + '<div class="field"><label for="lfName">название</label>'
    + '<input id="lfName" name="name" maxlength="60" placeholder="помёт от 12.05"></div>'
    + '<p class="muted">Отцов добавишь потом — у одного помёта их может быть несколько.</p>'
    + '</form>',
    '<button class="btn" data-close>отмена</button>'
    + '<button class="btn primary" id="saveLitter"'
    + (mothers.length ? '' : ' disabled') + '>создать</button>'
  );

  markRequired($('#litterForm'));
}

async function submitLitter() {
  const form = $('#litterForm');

  if (!checkForm(form)) {
    return;
  }

  const name = field(form, 'name').value.trim();

  try {
    await api('POST', '/api/litters', {
      mother_id: Number(field(form, 'mother_id').value),
      name: name === '' ? null : name
    });

    closeModal();
    toast('Помёт создан, добавь отцов');
    switchTab('litters');
    await refresh();
  } catch (error) {
    fail(error);
  }
}

async function openCat(id) {
  try {
    const { cat, mother, sires, kittens } = await api('GET', '/api/cats/' + id);

    openModal(
      cat.name,
      '<div class="form">'
      + '<div class="row">' + sexBadge(cat.sex)
      + '<span class="muted">' + ageText(cat.age) + '</span>'
      + (cat.breed ? '<span class="muted">· ' + esc(cat.breed) + '</span>' : '')
      + '</div>'
      + '<div class="block"><h3>мать</h3>' + (mother ? pill(mother) : '<span class="muted">неизвестна</span>') + '</div>'
      + '<div class="block"><h3>отцы</h3><div class="pills">'
      + (sires.length ? sires.map(pill).join('') : '<span class="muted">не указаны</span>')
      + '</div></div>'
      + '<div class="block"><h3>котята</h3><div class="pills">'
      + (kittens.length ? kittens.map(pill).join('') : '<span class="muted">пока нет</span>')
      + '</div></div>'
      + '</div>',
      '<button class="btn danger" id="delCat">удалить</button>'
      + '<button class="btn" data-close>закрыть</button>'
      + '<button class="btn primary" id="editCat">изменить</button>'
    );

    $('#editCat').onclick = () => catForm(cat);
    $('#delCat').onclick = () => removeCat(cat);
  } catch (error) {
    fail(error);
  }
}

async function removeCat(cat) {
  const message = 'Удалить кошку «' + cat.name + '»?'
    + (cat.litter_id ? ' Помёт тоже удалится, котята останутся без него.' : '');

  if (!confirm(message)) {
    return;
  }

  try {
    await api('DELETE', '/api/cats/' + cat.id);
    closeModal();
    toast('Кошка удалена');
    await refresh();
  } catch (error) {
    fail(error);
  }
}

async function removeLitter(id) {
  if (!confirm('Удалить помёт? Котята останутся, но потеряют связь с помётом.')) {
    return;
  }

  try {
    await api('DELETE', '/api/litters/' + id);
    toast('Помёт удалён');
    await refresh();
  } catch (error) {
    fail(error);
  }
}

async function addSire(litterId) {
  const select = document.querySelector('[data-sires-for="' + litterId + '"]');

  try {
    await api('POST', '/api/litters/' + litterId + '/sires', { sire_id: Number(select.value) });
    toast('Отец добавлен');
    await refresh();
  } catch (error) {
    fail(error);
  }
}

async function removeSire(litterId, sireId) {
  try {
    await api('DELETE', '/api/litters/' + litterId + '/sires/' + sireId);
    toast('Отец убран');
    await refresh();
  } catch (error) {
    fail(error);
  }
}

function switchTab(name) {
  $$('.tab').forEach((tab) => tab.classList.toggle('active', tab.dataset.tab === name));
  $$('.panel').forEach((panel) => { panel.hidden = panel.dataset.panel !== name; });
}

function applyFilters() {
  state.filters = {
    sex: $('#fSex').value,
    min_age: $('#fMinAge').value,
    max_age: $('#fMaxAge').value
  };

  clearTimeout(applyFilters.timer);
  applyFilters.timer = setTimeout(() => loadCats().catch(fail), 250);
}

document.addEventListener('click', (event) => {
  const target = event.target.closest('[data-close], [data-tab], [data-cat], [data-edit], [data-del],'
    + ' [data-addsire], [data-unsire], [data-dellitter], #addCat, #addLitter, #saveCat, #saveLitter, #fReset');

  if (!target) {
    return;
  }

  if (target.matches('[data-close]')) {
    return closeModal();
  }

  if (target.dataset.cat) {
    return openCat(Number(target.dataset.cat));
  }

  if (target.dataset.edit) {
    return catForm(state.all.find((cat) => cat.id === Number(target.dataset.edit)));
  }

  if (target.dataset.del) {
    return removeCat(state.all.find((cat) => cat.id === Number(target.dataset.del)));
  }

  if (target.dataset.addsire) {
    return addSire(Number(target.dataset.addsire));
  }

  if (target.dataset.unsire) {
    return removeSire(Number(target.dataset.litter), Number(target.dataset.unsire));
  }

  if (target.dataset.dellitter) {
    return removeLitter(Number(target.dataset.dellitter));
  }

  if (target.dataset.tab) {
    return switchTab(target.dataset.tab);
  }

  if (target.id === 'addCat') {
    return ready.then(() => catForm(null));
  }

  if (target.id === 'addLitter') {
    return ready.then(() => litterForm());
  }

  if (target.id === 'saveCat') {
    return submitCat();
  }

  if (target.id === 'saveLitter') {
    return submitLitter();
  }

  if (target.id === 'fReset') {
    $('#fSex').value = '';
    $('#fMinAge').value = '';
    $('#fMaxAge').value = '';
    return applyFilters();
  }
});

document.addEventListener('change', (event) => {
  if (event.target.id === 'fSex') {
    applyFilters();
  }
});

document.addEventListener('input', (event) => {
  if (event.target.id === 'fMinAge' || event.target.id === 'fMaxAge') {
    applyFilters();
  }

  const wrap = event.target.closest('.field.invalid');

  if (wrap) {
    wrap.classList.remove('invalid');

    const hint = wrap.querySelector('.err');
    if (hint) {
      hint.remove();
    }
  }
});

document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape' && !$('#modal').hidden) {
    closeModal();
  }
});

switchTab('cats');

const ready = refresh().catch(fail);
