(function () {
	var table = document.getElementById('kas-mappings');
	var addBtn = document.getElementById('kas-add-mapping');
	var template = document.getElementById('kas-mapping-template');
	var formIdInput = document.getElementById('kas_form_id');
	var loadBtn = document.getElementById('kas-load-form');
	var statusEl = document.getElementById('kas-form-status');
	var stepMapping = document.getElementById('kas-step-mapping');
	var mappingBody = document.getElementById('kas-mapping-body');
	var mappingHint = document.getElementById('kas-mapping-hint');
	var fieldList = document.getElementById('kas-field-list');
	var fieldListUl = document.getElementById('kas-field-list-ul');

	if (!table || !addBtn || !template || !formIdInput) {
		return;
	}

	var tbody = table.querySelector('tbody');
	var needsKey = { attribute: true, acf: true, meta: true };
	var attributes = (window.kasAdmin && kasAdmin.attributes) || {};
	var fields = (window.kasAdmin && kasAdmin.fields) || {};

	function nextIndex() {
		var max = -1;
		tbody.querySelectorAll('.kas-mapping-row').forEach(function (row) {
			var select = row.querySelector('.kas-source');
			if (!select || !select.name) return;
			var match = select.name.match(/mappings\[(\d+)\]/);
			if (match) {
				max = Math.max(max, parseInt(match[1], 10));
			}
		});
		return max + 1;
	}

	function refreshSourceKey(row) {
		var source = row.querySelector('.kas-source');
		var keyCell = row.cells[1];
		if (!source || !keyCell) return;

		var type = source.value;
		var name = source.name.replace('[source]', '[source_key]');
		var current = '';
		var existing = keyCell.querySelector('.kas-source-key');
		if (existing) current = existing.value || '';

		keyCell.innerHTML = '';

		if (type === 'attribute' && Object.keys(attributes).length) {
			var select = document.createElement('select');
			select.name = name;
			select.className = 'kas-source-key';

			var empty = document.createElement('option');
			empty.value = '';
			empty.textContent = 'Velg attributt…';
			select.appendChild(empty);

			Object.keys(attributes).forEach(function (tax) {
				var opt = document.createElement('option');
				opt.value = tax;
				opt.textContent = attributes[tax];
				if (tax === current) opt.selected = true;
				select.appendChild(opt);
			});

			keyCell.appendChild(select);
			return;
		}

		var input = document.createElement('input');
		input.type = 'text';
		input.name = name;
		input.className = 'kas-source-key regular-text';
		input.value = needsKey[type] ? current : '';
		input.disabled = !needsKey[type];
		input.placeholder = needsKey[type] ? 'pa_materiale / felt_navn' : '—';
		keyCell.appendChild(input);
	}

	function buildFieldSelect(name, selected) {
		var select = document.createElement('select');
		select.name = name;
		select.className = 'kas-field-id';

		var empty = document.createElement('option');
		empty.value = '';
		empty.textContent = 'Velg felt…';
		select.appendChild(empty);

		Object.keys(fields).forEach(function (fid) {
			var opt = document.createElement('option');
			opt.value = fid;
			opt.textContent = fields[fid];
			if (String(fid) === String(selected)) opt.selected = true;
			select.appendChild(opt);
		});

		return select;
	}

	function refreshFieldId(row) {
		var fieldCell = row.cells[2];
		var source = row.querySelector('.kas-source');
		if (!fieldCell || !source) return;

		var name = source.name.replace('[source]', '[field_id]');
		var current = '';
		var existing = fieldCell.querySelector('.kas-field-id');
		if (existing) current = existing.value || '';

		fieldCell.innerHTML = '';

		if (Object.keys(fields).length) {
			fieldCell.appendChild(buildFieldSelect(name, current));
			return;
		}

		var input = document.createElement('input');
		input.type = 'text';
		input.name = name;
		input.className = 'kas-field-id small-text';
		input.value = current;
		input.placeholder = '1';
		fieldCell.appendChild(input);
	}

	function refreshAllFieldIds() {
		tbody.querySelectorAll('.kas-mapping-row').forEach(refreshFieldId);

		if (template.content) {
			var tplRow = template.content.querySelector('.kas-mapping-row');
			if (tplRow) refreshFieldId(tplRow);
		}
	}

	function updateFieldList() {
		if (!fieldList || !fieldListUl) return;

		fieldListUl.innerHTML = '';
		Object.keys(fields).forEach(function (fid) {
			var li = document.createElement('li');
			var code = document.createElement('code');
			code.textContent = fid;
			li.appendChild(code);
			li.appendChild(document.createTextNode(' – ' + fields[fid]));
			fieldListUl.appendChild(li);
		});

		fieldList.hidden = !Object.keys(fields).length;
	}

	function setMappingReady(ready) {
		if (stepMapping) stepMapping.classList.toggle('is-ready', ready);
		if (mappingBody) mappingBody.hidden = !ready;
		if (mappingHint) {
			mappingHint.textContent = ready
				? 'Koble produktdata til feltene i skjemaet. Bruk hengelåsen for skrivebeskyttelse.'
				: 'Trykk «Last felter på nytt» for å hente feltene fra Gravity Forms.';
		}
	}

	function setStatus(message, isError) {
		if (!statusEl) return;
		statusEl.textContent = message || '';
		statusEl.classList.toggle('is-error', !!isError);
	}

	function loadForm() {
		var formId = formIdInput.value;
		if (!formId || formId === '0') {
			setStatus('Mangler skjema-ID.', true);
			return;
		}

		if (loadBtn) loadBtn.disabled = true;
		setStatus('Laster felter…');

		var body = new FormData();
		body.append('action', 'kas_load_form_fields');
		body.append('nonce', kasAdmin.nonce);
		body.append('form_id', formId);

		fetch(kasAdmin.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		})
			.then(function (res) { return res.json(); })
			.then(function (json) {
				if (loadBtn) loadBtn.disabled = false;

				if (!json || !json.success) {
					var msg = json && json.data && json.data.message ? json.data.message : 'Kunne ikke laste skjema.';
					setStatus(msg, true);
					return;
				}

				fields = json.data.fields || {};
				updateFieldList();
				refreshAllFieldIds();
				setMappingReady(Object.keys(fields).length > 0);
				setStatus('Felter lastet.');
			})
			.catch(function () {
				if (loadBtn) loadBtn.disabled = false;
				setStatus('Noe gikk galt. Prøv igjen.', true);
			});
	}

	if (loadBtn) {
		loadBtn.addEventListener('click', loadForm);
	}

	addBtn.addEventListener('click', function () {
		var html = template.innerHTML.replace(/__INDEX__/g, String(nextIndex()));
		var wrap = document.createElement('tbody');
		wrap.innerHTML = html.trim();
		var row = wrap.querySelector('tr');
		if (row) {
			tbody.appendChild(row);
			refreshSourceKey(row);
			refreshFieldId(row);
		}
	});

	function findLockToggle(el) {
		while (el && el !== tbody) {
			if (el.classList && el.classList.contains('kas-lock-toggle')) {
				return el;
			}
			el = el.parentNode;
		}
		return null;
	}

	function setLockState(btn, locked) {
		var row = btn.closest ? btn.closest('tr') : null;
		if (!row) {
			row = btn.parentNode;
			while (row && row.tagName !== 'TR') row = row.parentNode;
		}
		var input = row ? row.querySelector('.kas-locked-input') : null;
		if (!input) return;

		input.value = locked ? '1' : '0';
		if (locked) {
			btn.classList.add('is-locked');
		} else {
			btn.classList.remove('is-locked');
		}
		btn.setAttribute('aria-pressed', locked ? 'true' : 'false');
		btn.title = locked ? 'Feltet er låst' : 'Feltet er åpent';
	}

	tbody.addEventListener('click', function (event) {
		var lockBtn = findLockToggle(event.target);
		if (lockBtn) {
			event.preventDefault();
			event.stopPropagation();
			setLockState(lockBtn, !lockBtn.classList.contains('is-locked'));
			return;
		}

		var removeBtn = event.target.classList.contains('kas-remove-mapping')
			? event.target
			: (event.target.closest ? event.target.closest('.kas-remove-mapping') : null);

		if (removeBtn) {
			var row = removeBtn.closest ? removeBtn.closest('tr') : null;
			if (!row) {
				row = removeBtn.parentNode;
				while (row && row.tagName !== 'TR') row = row.parentNode;
			}
			if (row && tbody.querySelectorAll('.kas-mapping-row').length > 1) {
				row.remove();
			}
		}
	});

	tbody.addEventListener('change', function (event) {
		if (event.target.classList.contains('kas-source')) {
			refreshSourceKey(event.target.closest('tr'));
		}
	});

	function syncModeFields() {
		document.querySelectorAll('.kas-mode-select').forEach(function (select) {
			var targetId = select.getAttribute('data-kas-target');
			var target = targetId ? document.getElementById(targetId) : null;
			if (!target) return;
			target.hidden = select.value !== 'custom';
		});
	}

	function updateGfPreview() {
		var preview = document.getElementById('kas-gf-preview');
		var titleSelect = document.getElementById('kas_title_mode');
		var descSelect = document.getElementById('kas_description_mode');
		var attsInput = document.getElementById('kas_gf_atts');
		if (!preview || !titleSelect || !descSelect || !attsInput || !formIdInput) return;

		var extra = (attsInput.value || '').replace(/\b(title|description)\s*=\s*(['"]).*?\2/gi, '');
		extra = extra.replace(/\s+/g, ' ').trim();

		var title = titleSelect.value === 'default' ? 'true' : 'false';
		var desc = descSelect.value === 'default' ? 'true' : 'false';
		var shortcode = '[gravityform id="' + formIdInput.value + '" title="' + title + '" description="' + desc + '"';
		if (extra) shortcode += ' ' + extra;
		shortcode += ']';
		preview.textContent = shortcode;
	}

	document.querySelectorAll('.kas-mode-select').forEach(function (select) {
		select.addEventListener('change', function () {
			syncModeFields();
			updateGfPreview();
		});
	});

	var gfAtts = document.getElementById('kas_gf_atts');
	if (gfAtts) {
		gfAtts.addEventListener('input', updateGfPreview);
	}

	syncModeFields();
	updateGfPreview();
})();
