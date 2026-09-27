/* global myIapsnj, jQuery */
(function ($) {
    'use strict';

    var nonce   = myIapsnj.nonce;
    var ajaxUrl = myIapsnj.ajaxUrl;
    var i18n    = myIapsnj.i18n;

    // =========================================================================
    // Utilities
    // =========================================================================

    function showNotice($el, msg, type) {
        type = type || 'success';
        $el.removeClass('fcrm-notice-success fcrm-notice-error fcrm-notice-info')
            .addClass('fcrm-notice-' + type)
            .text(msg)
            .slideDown(200);
        if (type === 'success') {
            setTimeout(function () { $el.slideUp(400); }, 4000);
        }
    }

    function setBtn($btn, label, disabled) {
        if (!$btn.data('orig-label')) { $btn.data('orig-label', $btn.text()); }
        $btn.prop('disabled', disabled !== false).text(label);
    }

    function resetBtn($btn) {
        $btn.prop('disabled', false).text($btn.data('orig-label') || $btn.text());
    }

    function escHtml(str) {
        return String(str === null || str === undefined ? '' : str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function post(action, data) {
        data = data || {};
        data.action = 'my_iapsnj_' + action;
        data.nonce  = nonce;
        return $.post(ajaxUrl, data);
    }

    function get(action, data) {
        data = data || {};
        data.action = 'my_iapsnj_' + action;
        data.nonce  = nonce;
        return $.get(ajaxUrl, data);
    }

    function errMsg(resp) {
        if (resp && resp.data) {
            if (typeof resp.data === 'string') { return resp.data; }
            if (resp.data.message) { return resp.data.message; }
        }
        return i18n.error;
    }

    function link(url, text) {
        if (!url) { return escHtml(text); }
        return '<a href="' + escHtml(url) + '" target="_blank" rel="noopener">' + escHtml(text) + '</a>';
    }

    /**
     * Render an array of plain objects as a table. columns: [{key, label, render?}]
     */
    function renderTable(rows, columns, emptyText) {
        if (!rows || !rows.length) {
            return '<p class="fcrm-placeholder">' + escHtml(emptyText || i18n.noRows) + '</p>';
        }
        var html = '<div class="fcrm-table-wrap"><table class="widefat striped fcrm-report-table"><thead><tr>';
        columns.forEach(function (c) { html += '<th>' + escHtml(c.label) + '</th>'; });
        html += '</tr></thead><tbody>';
        rows.forEach(function (r) {
            html += '<tr>';
            columns.forEach(function (c) {
                html += '<td>' + (c.render ? c.render(r) : escHtml(r[c.key])) + '</td>';
            });
            html += '</tr>';
        });
        html += '</tbody></table></div>';
        return html;
    }

    // =========================================================================
    // Profile Mirror (field mapping)
    // =========================================================================

    var $tbody      = $('#fcrm-mapping-rows');
    var $mapNotice  = $('#fcrm-mapping-notice');
    var rowTemplate = document.getElementById('fcrm-row-template');

    $('#fcrm-add-row').on('click', function () {
        if (!rowTemplate) { return; }
        var clone = document.importNode(rowTemplate.content, true);
        var newId = 'map_' + Math.random().toString(36).substr(2, 8);
        var $row  = $(clone).find('tr');
        $row.attr('data-id', newId);
        $row.find('select, input').each(function () {
            if ($(this).attr('name')) {
                $(this).attr('name', $(this).attr('name').replace('__TEMPLATE__', newId));
            }
        });
        $tbody.append($row);
        wireRow($row);
    });

    $tbody.on('click', '.fcrm-remove-row', function () {
        if (!confirm(i18n.confirmDelete)) { return; }
        var $row = $(this).closest('tr');
        $row.next('.fcrm-value-map-row').remove();
        $row.remove();
    });

    function wireRow($row) {
        $row.find('.fcrm-wp-field, .fcrm-fcrm-field').on('change', function () {
            autoDetectType($row);
            updateHints($row);
            updateValueMapRow($row);
        });
        $row.find('.fcrm-field-type').on('change', function () {
            toggleDateFormat($row);
            toggleValueMapRow($row);
        });
        autoDetectType($row);
        updateHints($row);
        if ($row.find('.fcrm-field-type').val() === 'select') { toggleValueMapRow($row); }
    }

    function updateHints($row) {
        var $f = $row.find('.fcrm-fcrm-field option:selected');
        var $w = $row.find('.fcrm-wp-field option:selected');
        $row.find('.fcrm-fcrm-hint').text($f.data('type-label') ? $f.data('source-label') + ': ' + $f.data('type-label') : '');
        $row.find('.fcrm-wp-hint').text($w.data('type-label') ? $w.data('source-label') + ': ' + $w.data('type-label') : '');
    }

    function autoDetectType($row) {
        var wpType   = $row.find('.fcrm-wp-field option:selected').data('type') || '';
        var fcrmType = $row.find('.fcrm-fcrm-field option:selected').data('type') || '';
        var detected = fcrmType || wpType || 'text';
        var $type    = $row.find('.fcrm-field-type');
        if ($type.val() !== detected && $type.find('option[value="' + detected + '"]').length) {
            $type.val(detected);
        }
        if ($type.val() === 'date') {
            var $fmt  = $row.find('.fcrm-date-format-wp');
            var wpFmt = $row.find('.fcrm-wp-field option:selected').data('date-format') || '';
            if (!$fmt.val() || $fmt.val() === 'm/d/Y') { $fmt.val(wpFmt || myIapsnj.dateFormat || 'm/d/Y'); }
        }
        toggleDateFormat($row);
        toggleValueMapRow($row);
    }

    function toggleDateFormat($row) {
        $row.find('.fcrm-date-format-wrap').toggle($row.find('.fcrm-field-type').val() === 'date');
    }

    function toggleValueMapRow($row) {
        var isSelect = $row.find('.fcrm-field-type').val() === 'select';
        var $vm = $row.next('.fcrm-value-map-row');
        if (isSelect) {
            if (!$vm.length) {
                $vm = $('<tr class="fcrm-value-map-row"><td colspan="5"><div class="fcrm-value-map-container">' +
                    '<strong style="display:block;margin-bottom:6px">Value Mapping <small style="font-weight:normal;color:#666">(optional – WordPress option value ↔ FluentCRM option value)</small></strong>' +
                    '<table class="fcrm-value-map-table"><thead><tr><th>WordPress Value</th><th style="padding-left:16px">FluentCRM Value</th></tr></thead><tbody class="fcrm-vm-tbody"></tbody></table>' +
                    '</div></td></tr>');
                $row.after($vm);
            }
            $vm.show();
            populateValueMap($row, $vm);
        } else {
            $vm.hide();
        }
    }

    function updateValueMapRow($row) {
        var $vm = $row.next('.fcrm-value-map-row');
        if ($vm.length && $vm.is(':visible')) { populateValueMap($row, $vm); }
    }

    function parseJsonAttr(raw) {
        try { return JSON.parse(typeof raw === 'string' ? raw : JSON.stringify(raw)); } catch (e) { return []; }
    }

    function populateValueMap($row, $vm) {
        var $body = $vm.find('.fcrm-vm-tbody').empty();
        var wpOptions   = parseJsonAttr($row.find('.fcrm-wp-field option:selected').data('options') || '[]');
        var fcrmOptions = parseJsonAttr($row.find('.fcrm-fcrm-field option:selected').data('options') || '[]');
        var saved = {};
        try { saved = JSON.parse($row.find('.fcrm-value-map-json').val() || '{}'); } catch (e) {}

        if (!wpOptions.length && !fcrmOptions.length) {
            $body.append('<tr><td colspan="2" style="color:#888;font-style:italic">No option lists detected; values pass through unchanged.</td></tr>');
            return;
        }
        var source = wpOptions.length ? wpOptions : fcrmOptions;
        source.forEach(function (opt) {
            var wpVal = opt.value, mappedTo = saved[wpVal] !== undefined ? saved[wpVal] : '';
            var $input;
            if (fcrmOptions.length) {
                $input = $('<select class="fcrm-vm-select">').attr('data-wp-value', wpVal);
                $input.append('<option value="">— same as WP —</option>');
                fcrmOptions.forEach(function (fo) {
                    var $o = $('<option>').val(fo.value).text(fo.label || fo.value);
                    if (mappedTo === fo.value) { $o.prop('selected', true); }
                    $input.append($o);
                });
            } else {
                $input = $('<input type="text" class="regular-text fcrm-vm-select" placeholder="FluentCRM value">').attr('data-wp-value', wpVal).val(mappedTo);
            }
            $body.append($('<tr>').append($('<td style="padding:4px 8px">').text((opt.label || opt.value) + ' (' + wpVal + ')')).append($('<td style="padding:4px 8px 4px 16px">').append($input)));
        });
    }

    $tbody.find('.fcrm-mapping-row').each(function () { wireRow($(this)); });

    $('#fcrm-save-mappings').on('click', function () {
        var $btn = $(this), mappings = {}, hasError = false;
        $tbody.find('.fcrm-mapping-row').each(function () {
            var $row = $(this), rowId = $row.data('id');
            var wpUid = $row.find('.fcrm-wp-field').val(), fcrmUid = $row.find('.fcrm-fcrm-field').val();
            if (!fcrmUid) { $row.addClass('fcrm-row-error'); hasError = true; return; }
            $row.removeClass('fcrm-row-error');
            if (!wpUid) { return; }
            var valueMap = {};
            $row.next('.fcrm-value-map-row').find('.fcrm-vm-select').each(function () {
                var w = $(this).attr('data-wp-value'), f = $(this).val();
                if (w !== undefined && w !== '' && f !== undefined && f !== '') { valueMap[String(w)] = String(f); }
            });
            $row.find('.fcrm-value-map-json').val(JSON.stringify(valueMap));
            mappings[rowId] = {
                id: rowId,
                wp_uid: wpUid,
                fcrm_uid: fcrmUid,
                field_type: $row.find('.fcrm-field-type').val(),
                enabled: $row.find('.fcrm-enabled').is(':checked') ? 1 : 0,
                date_format_wp: $row.find('.fcrm-date-format-wp').val() || 'm/d/Y',
                value_map: valueMap
            };
        });
        if (hasError) { showNotice($mapNotice, 'Please select a FluentCRM field for every row (highlighted in red).', 'error'); return; }
        setBtn($btn, i18n.saving, true);
        post('save_mappings', { mappings: mappings })
            .done(function (resp) { showNotice($mapNotice, resp.success ? i18n.saved + ' (' + resp.data.count + ' mappings)' : errMsg(resp), resp.success ? 'success' : 'error'); })
            .fail(function () { showNotice($mapNotice, i18n.error, 'error'); })
            .always(function () { resetBtn($btn); });
    });

    // ---- Sample data preview -------------------------------------------------

    function wireSearch($input, $suggs, action, onPick) {
        var timer;
        $input.on('input', function () {
            clearTimeout(timer);
            var q = $(this).val().trim();
            onPick(null);
            if (q.length < 2) { $suggs.hide().empty(); return; }
            timer = setTimeout(function () {
                post(action, { query: q }).done(function (resp) {
                    $suggs.empty();
                    if (!resp.success || !resp.data.length) { $suggs.hide(); return; }
                    resp.data.forEach(function (u) {
                        $('<div class="fcrm-user-suggestion-item"></div>').text(u.label).data('item', u).appendTo($suggs);
                    });
                    $suggs.show();
                });
            }, 300);
        });
        $suggs.on('click', '.fcrm-user-suggestion-item', function () {
            var item = $(this).data('item');
            $input.val($(this).text());
            $suggs.hide().empty();
            onPick(item);
        });
        $(document).on('click', function (e) {
            if (!$(e.target).closest('.fcrm-user-search-wrap').length) { $suggs.hide(); }
        });
    }

    var previewUserId = 0;
    if ($('#fcrm-preview-user-input').length) {
        wireSearch($('#fcrm-preview-user-input'), $('#fcrm-user-suggestions'), 'search_users', function (item) {
            previewUserId = item ? item.id : 0;
            $('#fcrm-preview-load').prop('disabled', !previewUserId);
        });
    }
    $('#fcrm-preview-load').on('click', function () {
        if (!previewUserId) { return; }
        var $out = $('#fcrm-preview-results').show().html('<p>' + i18n.loading + '</p>');
        post('sample_data', { user_id: previewUserId }).done(function (resp) {
            if (!resp.success) { $out.html('<p class="fcrm-error">' + escHtml(errMsg(resp)) + '</p>'); return; }
            var d = resp.data;
            var html = '<div class="fcrm-preview-user-info"><strong>' + escHtml(d.user.display_name) + '</strong> &lt;' + escHtml(d.user.email) + '&gt; <span class="fcrm-preview-uid">#' + d.user.id + '</span></div>';
            html += renderTable(d.rows, [
                { key: 'fcrm_label', label: 'FluentCRM field' },
                { key: 'fcrm_value', label: 'CRM value', render: function (r) { return escHtml(r.fcrm_value || '—'); } },
                { key: 'wp_label', label: 'WordPress field' },
                { key: 'wp_value', label: 'WP value', render: function (r) { return escHtml(r.wp_value || '—'); } },
                { key: 'match', label: 'In sync?', render: function (r) { return r.match ? '<span class="fcrm-match-yes">&#10003;</span>' : '<span class="fcrm-match-no">&#10007;</span>'; } }
            ], 'No active mappings.');
            $out.html(html);
        }).fail(function () { $out.html('<p class="fcrm-error">' + i18n.error + '</p>'); });
    });

    // =========================================================================
    // Sync & Settings
    // =========================================================================

    $('#fcrm-bulk-fcrm-to-wp').on('click', function () {
        var $wrap = $('#fcrm-bulk-progress').show(), $bar = $('#fcrm-progress-bar').css('width', '0%'), $status = $('#fcrm-bulk-status').text(i18n.syncing);
        var offset = 0, total = 0, synced = 0, errors = 0;
        function page() {
            post('bulk_sync', { per_page: 50, offset: offset }).done(function (resp) {
                if (!resp.success) { $status.text(errMsg(resp)); return; }
                var d = resp.data;
                total = d.total || total; synced += d.success || 0; errors += (d.errors || []).length; offset = d.next_offset;
                $bar.css('width', (total ? Math.min(100, Math.round(Math.min(offset, total) / total * 100)) : 100) + '%');
                $status.text(i18n.syncing + ' ' + Math.min(offset, total) + ' / ' + total);
                if (d.has_more) { page(); } else { $status.text(i18n.syncDone + ' ' + synced + ' contacts mirrored' + (errors ? ', ' + errors + ' error(s).' : '.')); $bar.css('width', '100%'); }
            }).fail(function () { $status.text(i18n.error); });
        }
        page();
    });

    $('#fcrm-settings-form').on('submit', function (e) {
        e.preventDefault();
        var $btn = $(this).find('[type="submit"]'), $notice = $('#fcrm-settings-notice'), data = {};
        $(this).find('input, select, textarea').each(function () {
            var name = $(this).attr('name');
            if (!name) { return; }
            if ($(this).is(':checkbox')) { data[name] = $(this).is(':checked') ? 1 : 0; } else { data[name] = $(this).val(); }
        });
        setBtn($btn, i18n.saving, true);
        post('save_settings', data)
            .done(function (resp) { showNotice($notice, resp.success ? i18n.saved : errMsg(resp), resp.success ? 'success' : 'error'); })
            .fail(function () { showNotice($notice, i18n.error, 'error'); })
            .always(function () { resetBtn($btn); });
    });

    // ---- Application fields builder -------------------------------------------
    // Two tables: active rows (#fcrm-fields-rows, shown at checkout, in order)
    // and inactive rows (#fcrm-fields-inactive: hidden fields, then FluentCRM
    // fields the form does not use yet). Ticking / unticking Show or dragging
    // moves a row. An active row can be indented one level under the field
    // above it (a "child"): the checkout then shows it only while that
    // field's answer matches. A parent moves together with its children.

    var $fieldRows = $('#fcrm-fields-rows');
    var $inactiveRows = $('#fcrm-fields-inactive');
    var fieldRowTemplate = document.getElementById('fcrm-field-row-template');
    var ROW = 'tr.fcrm-field-row:not(.fcrm-sort-helper)';
    var INDENT_PX = 36; // horizontal drag that indents / outdents a row

    function isSection($r) { return $r.find('.fcrm-field-type').val() === 'section'; }
    function isChild($r) { return $r.hasClass('fcrm-field-child'); }
    function isShownRow($r) { return $r.closest('#fcrm-fields-rows').length > 0; }
    function isOn($r) { return $r.find('.fcrm-field-enabled').is(':checked'); }

    // The children right below a top-level row.
    function childrenOf($r) {
        var kids = [];
        if (isChild($r)) { return $(kids); }
        $r.nextAll(ROW).each(function () {
            if (!isChild($(this))) { return false; }
            kids.push(this);
        });
        return $(kids);
    }

    function blockOf($r) { return $r.add(childrenOf($r)); }

    function rowLabel($r) {
        var $l = $r.find('.fcrm-field-label');
        return $.trim($l.val()) || '(' + ($l.attr('placeholder') || '') + ')';
    }

    function rowOptions($r) {
        return $.map(String($r.find('.fcrm-field-options').val() || '').split(/\r\n|\r|\n/), function (o) {
            o = $.trim(o);
            return o === '' ? null : o;
        });
    }

    // A top-level row can be indented under the row right above it, unless
    // either is a section heading or it has children of its own (one level).
    function canIndent($r, $above) {
        if (!isShownRow($r) || isSection($r) || isChild($r) || childrenOf($r).length) { return false; }
        return $above.length > 0 && !isSection($above);
    }

    function setChild($r, on) {
        $r.toggleClass('fcrm-field-child', !!on);
        if (!on) {
            $r.find('.fcrm-field-parent').val('');
            $r.find('.fcrm-field-condition').hide();
        }
    }

    // "Show only when <parent> is …": a choice of the parent's options for
    // a dropdown / radio, "is ticked" for a tick box, else "has an answer".
    function fillCondition($child, $parent) {
        var $box = $child.find('.fcrm-field-condition');
        var $sel = $box.find('.fcrm-cond-values');
        var type = $parent.find('.fcrm-field-type').val();
        var opts = rowOptions($parent);
        var picked = $sel.data('ready') ? ($sel.val() || []) : parseJsonAttr($sel.attr('data-selected') || '[]');
        $sel.data('ready', true);
        $box.find('.fcrm-cond-parent').text(rowLabel($parent));
        if ((type === 'select' || type === 'radio') && opts.length) {
            var html = '';
            $.each(opts, function (i, o) {
                html += '<option value="' + escHtml(o) + '"' + ($.inArray(o, picked) > -1 ? ' selected' : '') + '>' + escHtml(o) + '</option>';
            });
            $box.find('.fcrm-cond-rule').text(i18n.condIs);
            $sel.html(html).attr('size', Math.min(6, Math.max(2, opts.length))).show();
            $box.find('.fcrm-cond-hint').show();
        } else {
            $box.find('.fcrm-cond-rule').text(type === 'checkbox' ? i18n.condTicked : i18n.condAnswered);
            $sel.empty().hide();
            $box.find('.fcrm-cond-hint').hide();
        }
        $box.show();
    }

    // Children take the nearest top-level row above them (no heading in
    // between) as parent; anything else is outdented. Inactive rows never
    // have a parent.
    function refreshConditions() {
        var $top = null;
        $fieldRows.children(ROW).each(function () {
            var $r = $(this);
            if (isSection($r)) { setChild($r, false); $top = null; return; }
            if (isChild($r)) {
                if (!$top) { setChild($r, false); $top = $r; return; }
                $r.find('.fcrm-field-parent').val($top.attr('data-key'));
                fillCondition($r, $top);
                return;
            }
            $top = $r;
        });
        $inactiveRows.children(ROW).each(function () { setChild($(this), false); });
        $('#fcrm-fields-rows, #fcrm-fields-inactive').children(ROW).each(function () {
            var $r = $(this);
            $r.find('.fcrm-field-indent').prop('disabled', !canIndent($r, $r.prevAll(ROW).first()));
            $r.find('.fcrm-field-outdent').prop('disabled', !isChild($r));
        });
    }

    function rowTarget($r) { return $r.find('.fcrm-field-target').val() || 'none'; }
    function isCrmTarget(t) { return t.indexOf('custom:') === 0 || t.indexOf('default:') === 0; }

    // A FluentCRM field some row writes to leaves "Other FluentCRM fields"
    // (and comes back when no row does), and no other row can pick it.
    function syncCrmTargets() {
        var $rows = $('#fcrm-fields-rows, #fcrm-fields-inactive').children(ROW);
        var used = {};
        $rows.each(function () {
            var $r = $(this), t = rowTarget($r);
            if (isSection($r) || ($r.hasClass('fcrm-field-auto') && !isOn($r)) || !isCrmTarget(t)) { return; }
            (used[t] = used[t] || []).push(this);
        });
        var anyOffered = false;
        $rows.filter('.fcrm-field-auto').each(function () {
            var $r = $(this);
            if (isOn($r)) { $r.removeClass('fcrm-field-crm-used').show(); return; }
            var taken = !!used[rowTarget($r)];
            $r.toggleClass('fcrm-field-crm-used', taken).toggle(!taken);
            anyOffered = anyOffered || !taken;
        });
        $inactiveRows.find('tr.fcrm-fields-subhead').toggle(anyOffered);
        $rows.each(function () {
            var self = this;
            $(this).find('.fcrm-field-target option').each(function () {
                var others = $.grep(used[this.value] || [], function (el) { return el !== self; });
                // Never disable the row's own choice: jQuery would read it as empty.
                $(this).prop('disabled', others.length > 0 && !this.selected);
            });
        });
    }

    function renumberFieldRows() {
        $('#fcrm-fields-rows, #fcrm-fields-inactive').children(ROW).each(function (i) {
            $(this).find('.fcrm-field-order').val(i + 1);
        });
        $fieldRows.find('tr.fcrm-fields-empty').toggle($fieldRows.children(ROW).length === 0);
        refreshConditions();
        syncCrmTargets();
    }

    // Options, CRM target and Required follow the type and Show.
    function syncFieldRow($row) {
        var type    = $row.find('.fcrm-field-type').val();
        var section = type === 'section';
        var has     = type === 'select' || type === 'radio';
        var shown   = isOn($row);
        var $req    = $row.find('.fcrm-field-required');
        var $label  = $row.find('.fcrm-field-label');
        var $help   = $row.find('.fcrm-field-help');
        $row.toggleClass('enabled', shown).toggleClass('fcrm-field-section', section);
        $row.find('.fcrm-field-options').toggle(has);
        $row.find('.fcrm-field-no-options').toggle(!has);
        $row.find('.fcrm-field-target').toggle(!section);
        $row.find('.fcrm-field-no-target').toggle(section);
        if (section) { $row.find('.fcrm-field-target').val('none'); }
        if (!shown || section) { $req.prop('checked', false); }
        $req.prop('disabled', !shown || section);
        $label.attr('placeholder', section ? $label.data('placeholder-section') : $label.data('placeholder-field'));
        $help.attr('placeholder', section ? $help.data('placeholder-section') : '');
    }

    // Hide a row (and its children, which go with it, outdented). A row
    // dropped into Inactive by drag keeps its place ($keepPlace).
    function moveToInactive($row, $kids, keepPlace) {
        setChild($row, false);
        if (keepPlace) {
            // already where it was dropped
        } else if ($row.hasClass('fcrm-field-auto') && $inactiveRows.find('tr.fcrm-fields-subhead').length) {
            $inactiveRows.find('tr.fcrm-fields-subhead').after($row); // back to the CRM list
        } else {
            $inactiveRows.prepend($row);
        }
        $row.find('.fcrm-field-enabled').prop('checked', false);
        syncFieldRow($row);
        if ($kids && $kids.length) {
            $row.after($kids);
            $kids.each(function () {
                var $k = $(this);
                setChild($k, false);
                $k.find('.fcrm-field-enabled').prop('checked', false);
                syncFieldRow($k);
            });
        }
    }

    function addFieldRow(type) {
        if (!fieldRowTemplate) { return; }
        var clone = document.importNode(fieldRowTemplate.content, true);
        var newId = 'new_' + Math.random().toString(36).substr(2, 8);
        var $row  = $(clone).find('tr');
        $row.attr('data-key', newId);
        $row.find('input, select, textarea').each(function () {
            if ($(this).attr('name')) { $(this).attr('name', $(this).attr('name').replace('__TEMPLATE__', newId)); }
        });
        $row.find('.fcrm-field-type').val(type);
        $fieldRows.find('tr.fcrm-fields-empty').before($row);
        syncFieldRow($row);
        renumberFieldRows();
        $row.find('.fcrm-field-label').focus();
    }

    $('#fcrm-add-field').on('click', function () { addFieldRow('text'); });
    $('#fcrm-add-section').on('click', function () { addFieldRow('section'); });

    $('#fcrm-fields-table, #fcrm-fields-inactive-table').on('click', '.fcrm-field-remove', function () {
        var $tr = $(this).closest('tr');
        childrenOf($tr).each(function () { setChild($(this), false); });
        $tr.remove();
        renumberFieldRows();
    }).on('click', '.fcrm-field-up', function () {
        // A child moves among its siblings; a parent moves with its children
        // above the whole block (parent + children) above it.
        var $tr = $(this).closest('tr'), $prev = $tr.prevAll(ROW).first();
        if (isChild($tr)) {
            if ($prev.length && isChild($prev)) { $prev.before($tr); }
        } else {
            while ($prev.length && isChild($prev)) { $prev = $prev.prevAll(ROW).first(); }
            if ($prev.length) { $prev.before(blockOf($tr)); }
        }
        renumberFieldRows();
    }).on('click', '.fcrm-field-down', function () {
        var $tr = $(this).closest('tr');
        if (isChild($tr)) {
            var $n = $tr.nextAll(ROW).first();
            if ($n.length && isChild($n)) { $n.after($tr); }
        } else {
            var $block = blockOf($tr), $next = $block.last().nextAll(ROW).first();
            if ($next.length) { blockOf($next).last().after($block); }
        }
        renumberFieldRows();
    }).on('click', '.fcrm-field-indent', function () {
        var $tr = $(this).closest('tr');
        if (canIndent($tr, $tr.prevAll(ROW).first())) { setChild($tr, true); renumberFieldRows(); }
    }).on('click', '.fcrm-field-outdent', function () {
        setChild($(this).closest('tr'), false);
        renumberFieldRows();
    }).on('change', '.fcrm-field-type', function () {
        syncFieldRow($(this).closest('tr'));
        renumberFieldRows();
    }).on('change', '.fcrm-field-target', function () {
        syncCrmTargets();
    }).on('input change', '.fcrm-field-label, .fcrm-field-options', function () {
        refreshConditions(); // a parent's label / options feed its children's condition
    }).on('change', '.fcrm-field-enabled', function () {
        var $row = $(this).closest('tr');
        if ($(this).is(':checked')) {
            setChild($row, false);
            syncFieldRow($row);
            $fieldRows.find('tr.fcrm-fields-empty').before($row); // to the end of the active list
        } else {
            moveToInactive($row, childrenOf($row));
        }
        renumberFieldRows();
    });

    // Drag and drop (jquery-ui-sortable, bundled with WordPress): reorder by
    // the handle; dropping into Active shows a field, into Inactive hides it.
    // Dragged right (under a field) a row is indented = conditional; dragged
    // left it is outdented. A parent is dragged together with its children.
    if ($.fn.sortable && $fieldRows.length && $inactiveRows.length) {
        var drag = null;
        $('#fcrm-fields-rows, #fcrm-fields-inactive').sortable({
            items: '> tr.fcrm-field-row',
            handle: '.fcrm-drag-handle',
            connectWith: '#fcrm-fields-rows, #fcrm-fields-inactive',
            cursor: 'grabbing',
            tolerance: 'pointer',
            placeholder: 'fcrm-sort-placeholder',
            forcePlaceholderSize: true,
            // Table rows lose their column widths once lifted out of the table.
            helper: function (e, $tr) {
                var $helper = $tr.clone();
                $helper.children().each(function (i) { $(this).width($tr.children().eq(i).width()); });
                $helper.find('select').each(function (i) { $(this).val($tr.find('select').eq(i).val()); });
                return $helper.addClass('fcrm-sort-helper');
            },
            start: function (e, ui) {
                var $kids = childrenOf(ui.item);
                drag = { kids: $kids.detach(), wasChild: isChild(ui.item), indent: isChild(ui.item) };
                ui.placeholder.html('<td colspan="9"></td>').height(ui.item.outerHeight());
                if (drag.kids.length) { $(this).sortable('refreshPositions'); }
            },
            sort: function (e, ui) {
                if (!drag) { return; }
                var dx     = ui.position.left - ui.originalPosition.left;
                var $above = ui.placeholder.prevAll(ROW).not(ui.item).filter(':visible').first();
                var $below = ui.placeholder.nextAll(ROW).not(ui.item).filter(':visible').first();
                var ok     = ui.placeholder.closest('#fcrm-fields-rows').length > 0 && !isSection(ui.item) &&
                    !drag.kids.length && $above.length > 0 && !isSection($above);
                if (!ok) {
                    drag.indent = false;
                } else if (dx > INDENT_PX) {
                    drag.indent = true;
                } else if (dx < -INDENT_PX) {
                    drag.indent = false;
                } else {
                    // No sideways move: keep what it was, or join the group it is dropped into.
                    drag.indent = drag.wasChild || (isChild($above) && isChild($below));
                }
                ui.placeholder.toggleClass('fcrm-sort-placeholder-child', drag.indent);
            },
            stop: function (e, ui) {
                var $row  = ui.item;
                var shown = isShownRow($row);
                var kids  = drag ? drag.kids : $();
                $row.find('.fcrm-field-enabled').prop('checked', shown);
                setChild($row, shown && drag && drag.indent);
                syncFieldRow($row);
                if (kids.length) {
                    if (shown) {
                        $row.after(kids);
                        // Dropped inside another parent's group: land after the group.
                        var $after = kids.last().nextAll(ROW).first(), $end = null;
                        while ($after.length && isChild($after)) { $end = $after; $after = $after.nextAll(ROW).first(); }
                        if ($end) { $end.after($row.add(kids)); }
                    } else {
                        moveToInactive($row, kids, true);
                    }
                }
                $fieldRows.append($fieldRows.find('tr.fcrm-fields-empty')); // keep "no active fields" last
                drag = null;
                renumberFieldRows();
            }
        });
    }

    renumberFieldRows();

    $('#fcrm-checkout-fields-form').on('submit', function (e) {
        e.preventDefault();
        var $btn = $(this).find('[type="submit"]'), $notice = $('#fcrm-settings-notice'), data = {};
        renumberFieldRows();
        $(this).find('input, select, textarea').each(function () {
            var name = $(this).attr('name');
            if (!name) { return; }
            // FluentCRM fields left hidden are not saved (they are offered
            // again next time): leave them out so a long CRM field list
            // cannot push the request past PHP's max_input_vars.
            var $tr = $(this).closest('tr.fcrm-field-auto');
            if ($tr.length && !$tr.find('.fcrm-field-enabled').is(':checked')) { return; }
            if ($(this).is(':checkbox')) { if ($(this).is(':checked')) { data[name] = 1; } }
            else { data[name] = $(this).val(); } // disabled selects (built-in type) are sent too: jQuery reads them
        });
        setBtn($btn, i18n.saving, true);
        $.post(ajaxUrl, $.extend({ action: 'my_iapsnj_save_checkout_fields', nonce: nonce }, data))
            .done(function (resp) {
                showNotice($notice, resp.success ? i18n.saved + ' ' + resp.data.count + ' application field(s) shown on this form.' : errMsg(resp), resp.success ? 'success' : 'error');
                if (resp.success) { setTimeout(function () { window.location.href = window.location.pathname + window.location.search + '#application-fields'; window.location.reload(); }, 1200); }
            })
            .fail(function () { showNotice($notice, i18n.error, 'error'); })
            .always(function () { resetBtn($btn); });
    });

    $('#fcrm-import-field-options').on('click', function () {
        var $btn = $(this), $notice = $('#fcrm-settings-notice');
        setBtn($btn, i18n.loading, true);
        post('import_field_options', { form: $btn.data('form') || '' })
            .done(function (resp) {
                showNotice($notice, resp.success ? resp.data.message : errMsg(resp), resp.success ? 'success' : 'error');
                if (resp.success && resp.data.count > 0) { setTimeout(function () { window.location.href = window.location.pathname + window.location.search + '#application-fields'; window.location.reload(); }, 1500); }
            })
            .fail(function () { showNotice($notice, i18n.error, 'error'); })
            .always(function () { resetBtn($btn); });
    });

    // ---- Checkout Builder: forms and level assignment -------------------------

    $('#fcrm-checkout-assign-form').on('submit', function (e) {
        e.preventDefault();
        var $btn = $(this).find('[type="submit"]'), $notice = $('#fcrm-settings-notice'), data = {};
        $(this).find('select').each(function () { data[$(this).attr('name')] = $(this).val(); });
        setBtn($btn, i18n.saving, true);
        post('checkout_forms_assign', data)
            .done(function (resp) {
                showNotice($notice, resp.success ? i18n.saved : errMsg(resp), resp.success ? 'success' : 'error');
                if (resp.success) { setTimeout(function () { window.location.reload(); }, 800); }
            })
            .fail(function () { showNotice($notice, i18n.error, 'error'); })
            .always(function () { resetBtn($btn); });
    });

    $('#fcrm-form-create').on('click', function () {
        var name = window.prompt(i18n.formName, '');
        if (name === null) { return; }
        var $btn = $(this), $notice = $('#fcrm-settings-notice');
        setBtn($btn, i18n.saving, true);
        post('checkout_form_create', { name: name })
            .done(function (resp) {
                if (resp.success) { window.location.href = resp.data.url; return; }
                showNotice($notice, errMsg(resp), 'error');
            })
            .fail(function () { showNotice($notice, i18n.error, 'error'); })
            .always(function () { resetBtn($btn); });
    });

    $(document).on('click', '.fcrm-form-duplicate', function () {
        var $btn = $(this), $notice = $('#fcrm-settings-notice');
        if ($('#fcrm-checkout-fields-form').length && !window.confirm(i18n.confirmDuplicate)) { return; }
        setBtn($btn, i18n.saving, true);
        post('checkout_form_duplicate', { form: $btn.data('form') })
            .done(function (resp) {
                if (resp.success) { window.location.href = resp.data.url; return; }
                showNotice($notice, errMsg(resp), 'error');
            })
            .fail(function () { showNotice($notice, i18n.error, 'error'); })
            .always(function () { resetBtn($btn); });
    });

    $(document).on('click', '.fcrm-form-delete', function () {
        var $btn = $(this), $notice = $('#fcrm-settings-notice');
        if (!window.confirm(i18n.confirmDeleteForm.replace('%s', $btn.data('name')))) { return; }
        setBtn($btn, i18n.saving, true);
        post('checkout_form_delete', { form: $btn.data('form') })
            .done(function (resp) {
                if (resp.success) { $btn.closest('tr').remove(); window.location.reload(); return; }
                showNotice($notice, errMsg(resp), 'error');
            })
            .fail(function () { showNotice($notice, i18n.error, 'error'); })
            .always(function () { resetBtn($btn); });
    });

    $('#fcrm-apply-offline-labels').on('click', function () {
        var $btn = $(this), $notice = $('#fcrm-settings-notice');
        setBtn($btn, i18n.saving, true);
        post('apply_offline_labels', { label: $('#fcrm-offline-label').val(), instructions: $('#fcrm-offline-instructions').val() })
            .done(function (resp) { showNotice($notice, resp.success ? resp.data.message : errMsg(resp), resp.success ? 'success' : 'error'); })
            .fail(function () { showNotice($notice, i18n.error, 'error'); })
            .always(function () { resetBtn($btn); });
    });

    $('.fcrm-run-expiry').on('click', function () {
        var $btn = $(this), dry = $btn.data('dry') === 1 || $btn.data('dry') === '1', $out = $('#fcrm-expiry-result').html('<p>' + i18n.loading + '</p>');
        if (!dry && !window.confirm('Apply expirations now? Lapsed members lose the Member-Active tag and their role; members in good standing get them.')) { $out.empty(); return; }
        setBtn($btn, i18n.loading, true);
        post('run_expiry', { dry: dry ? 1 : 0 })
            .done(function (resp) {
                if (!resp.success) { $out.html('<p class="fcrm-error">' + escHtml(errMsg(resp)) + '</p>'); return; }
                var d = resp.data, html = '<p>' + (d.dry ? 'Preview' : 'Applied') + ': ' + d.active + ' active, ' + d.expired + ' expired · to expire now: ' + d.to_expire + ', to activate: ' + d.to_activate
                    + (d.dry ? '' : ' · done: ' + d.expired_now + ' expired, ' + d.activated + ' activated, ' + d.roles_changed + ' roles changed') + '</p>';
                if (d.samples && d.samples.length) { html += '<ul style="margin-left:18px">' + d.samples.map(function (s) { return '<li>' + escHtml(s) + '</li>'; }).join('') + '</ul>'; }
                $out.html(html);
            })
            .fail(function () { $out.html('<p class="fcrm-error">' + i18n.error + '</p>'); })
            .always(function () { resetBtn($btn); });
    });

    $('#fcrm-ensure-schema').on('click', function () {
        var $btn = $(this), $out = $('#fcrm-schema-result').html('<p>' + i18n.loading + '</p>');
        setBtn($btn, i18n.loading, true);
        post('ensure_schema', { years: $('#fcrm-schema-years').val() })
            .done(function (resp) {
                if (!resp.success) { $out.html('<p class="fcrm-error">' + escHtml(errMsg(resp)) + '</p>'); return; }
                var d = resp.data;
                $out.html('<p>Tags created: <code>' + escHtml(d.tags_created.length ? d.tags_created.join(', ') : 'none (all present)') + '</code><br>Fields created: <code>' + escHtml(d.fields_created.length ? d.fields_created.join(', ') : 'none (all present)') + '</code></p>');
            })
            .fail(function () { $out.html('<p class="fcrm-error">' + i18n.error + '</p>'); })
            .always(function () { resetBtn($btn); });
    });

    // =========================================================================
    // Membership Products
    // =========================================================================

    $('#fcrm-products-form').on('change', 'select[name$="[member_type]"]', function () {
        var $duration = $(this).closest('tr').find('input[name$="[duration]"]');
        var lifetime = $(this).val() === 'Lifetime';
        $duration.prop('disabled', lifetime);
        if (!lifetime && !$duration.val()) { $duration.val(1); }
    }).on('change', 'input[type="checkbox"]', function () {
        $(this).closest('tr').toggleClass('enabled', $(this).is(':checked'));
    }).on('submit', function (e) {
        e.preventDefault();
        var $btn = $(this).find('[type="submit"]'), $notice = $('#fcrm-products-notice');
        var data = {};
        $(this).find('input, select').each(function () {
            var name = $(this).attr('name');
            if (!name) { return; }
            if ($(this).is(':checkbox')) { if ($(this).is(':checked')) { data[name] = 1; } }
            else if (!$(this).prop('disabled')) { data[name] = $(this).val(); }
        });
        setBtn($btn, i18n.saving, true);
        $.post(ajaxUrl, $.extend({ action: 'my_iapsnj_save_products', nonce: nonce }, data))
            .done(function (resp) {
                if (!resp.success) { showNotice($notice, errMsg(resp), 'error'); return; }
                var msg = i18n.saved + ' ' + resp.data.count + ' membership product(s) active.';
                if (resp.data.warnings && resp.data.warnings.length) { showNotice($notice, msg + ' ' + resp.data.warnings.join(' '), 'info'); }
                else { showNotice($notice, msg); }
                setTimeout(function () { window.location.reload(); }, 1200);
            })
            .fail(function () { showNotice($notice, i18n.error, 'error'); })
            .always(function () { resetBtn($btn); });
    });

    // =========================================================================
    // Pending Checks
    // =========================================================================

    var checksRows = [];
    var $checksTable = $('#fcrm-checks-table');

    function fmtCents(cents) {
        return '$' + (cents / 100).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function loadChecks() {
        if (!$checksTable.length) { return; }
        $checksTable.html('<p class="fcrm-placeholder">' + i18n.loading + '</p>');
        $('#fcrm-checks-status').text('');
        get('checks_list', { membership_only: $('#fcrm-checks-membership-only').is(':checked') ? 1 : 0 }).done(function (resp) {
            if (!resp.success) { $checksTable.html('<p class="fcrm-error">' + escHtml(errMsg(resp)) + '</p>'); return; }
            checksRows = resp.data || [];
            renderChecks();
        }).fail(function () { $checksTable.html('<p class="fcrm-error">' + i18n.error + '</p>'); });
    }

    function renderChecks() {
        if (!checksRows.length) {
            $checksTable.html('<p class="fcrm-success">No checks pending. &#10003;</p>');
            updateSelection();
            return;
        }
        var html = '<div class="fcrm-table-wrap"><table class="widefat striped fcrm-checks"><thead><tr>' +
            '<th><input type="checkbox" id="fcrm-checks-all"></th><th>Order</th><th>Placed</th><th>Age</th><th>Member</th><th>Member #</th><th>Items</th><th>Total</th><th>Check #</th><th></th></tr></thead><tbody>';
        var total = 0;
        checksRows.forEach(function (r) {
            total += r.total_cents;
            html += '<tr data-id="' + r.id + '" data-cents="' + r.total_cents + '" class="' + (r.age_days >= 30 ? 'fcrm-aging' : '') + '">' +
                '<td><input type="checkbox" class="fcrm-check-select"></td>' +
                '<td>' + link(r.admin_url, '#' + r.id) + (r.source === 'manual_check' ? ' <span class="fcrm-badge">manual</span>' : '') + (r.is_membership ? '' : ' <span class="fcrm-badge fcrm-badge-warn">not membership</span>') + '</td>' +
                '<td>' + escHtml(r.date) + '</td>' +
                '<td>' + r.age_days + 'd</td>' +
                '<td>' + (r.crm_url ? link(r.crm_url, r.customer_name || r.email) : escHtml(r.customer_name || r.email)) + '<br><small class="fcrm-muted">' + escHtml(r.email) + '</small></td>' +
                '<td>' + escHtml(r.member_number || '—') + '</td>' +
                '<td>' + escHtml((r.items || []).join('; ')) + ((r.application || []).length ? '<br><small class="fcrm-muted">' + escHtml(r.application.join(' · ')) + '</small>' : '') + '</td>' +
                '<td class="fcrm-num">' + escHtml(r.total) + '</td>' +
                '<td><input type="text" class="fcrm-check-number small-text" placeholder="#" style="width:90px"></td>' +
                '<td class="fcrm-row-result"></td></tr>';
        });
        html += '</tbody></table></div>';
        $checksTable.html(html);
        $('#fcrm-checks-status').text(checksRows.length + ' pending · ' + fmtCents(total) + ' outstanding');
        updateSelection();
    }

    function updateSelection() {
        var count = 0, cents = 0;
        $checksTable.find('.fcrm-check-select:checked').each(function () {
            count++;
            cents += parseInt($(this).closest('tr').data('cents'), 10) || 0;
        });
        $('#fcrm-selected-count').text(count);
        $('#fcrm-selected-total').text(fmtCents(cents));
        $('#fcrm-mark-paid').prop('disabled', count === 0);
        var expected = parseFloat(String($('#fcrm-deposit-expected').val()).replace(/[^0-9.]/g, ''));
        var $match = $('#fcrm-deposit-match');
        if (!isNaN(expected) && count > 0) {
            var diff = Math.round(expected * 100) - cents;
            $match.text(diff === 0 ? '✓ matches deposit slip' : (diff > 0 ? 'slip is ' + fmtCents(diff) + ' more' : 'slip is ' + fmtCents(-diff) + ' less'))
                  .attr('class', diff === 0 ? 'fcrm-match-yes' : 'fcrm-match-no');
        } else {
            $match.text('').attr('class', '');
        }
    }

    $checksTable.on('change', '#fcrm-checks-all', function () {
        $checksTable.find('.fcrm-check-select').prop('checked', $(this).is(':checked'));
        updateSelection();
    }).on('change', '.fcrm-check-select', updateSelection);
    $('#fcrm-deposit-expected').on('input', updateSelection);
    $('#fcrm-checks-reload').on('click', loadChecks);
    $('#fcrm-checks-membership-only').on('change', loadChecks);

    $('#fcrm-mark-paid').on('click', function () {
        var ids = [], numbers = {};
        $checksTable.find('.fcrm-check-select:checked').each(function () {
            var $tr = $(this).closest('tr'), id = $tr.data('id');
            ids.push(id);
            numbers[id] = $tr.find('.fcrm-check-number').val();
        });
        if (!ids.length || !confirm(i18n.confirmPaid)) { return; }
        var $btn = $(this), $notice = $('#fcrm-checks-notice'), $res = $('#fcrm-mark-paid-results').empty();
        setBtn($btn, i18n.saving, true);
        post('checks_mark_paid', { order_ids: ids, check_numbers: numbers, deposit_date: $('#fcrm-deposit-date').val() })
            .done(function (resp) {
                if (!resp.success) { showNotice($notice, errMsg(resp), 'error'); return; }
                var d = resp.data;
                (d.results || []).forEach(function (r) {
                    var $tr = $checksTable.find('tr[data-id="' + r.order_id + '"]');
                    $tr.find('.fcrm-row-result').html(r.ok ? '<span class="fcrm-match-yes">&#10003; ' + escHtml(r.message) + '</span>' : '<span class="fcrm-match-no">&#10007; ' + escHtml(r.message) + '</span>');
                    if (r.ok) { $tr.addClass('fcrm-resolved').find('.fcrm-check-select').prop('checked', false).prop('disabled', true); }
                });
                $res.html('<p><strong>' + d.paid + '</strong> marked paid, <strong>' + d.failed + '</strong> failed. Batch total: <strong>' + escHtml(d.batch_total) + '</strong> (deposit ' + escHtml($('#fcrm-deposit-date').val()) + ').</p>');
                showNotice($notice, d.paid + ' order(s) marked paid. Batch total ' + d.batch_total + '.', d.failed ? 'info' : 'success');
                updateSelection();
                setTimeout(loadChecks, 2500);
            })
            .fail(function () { showNotice($notice, i18n.error, 'error'); })
            .always(function () { resetBtn($btn); });
    });

    loadChecks();

    // ---- Record a check --------------------------------------------------------

    if ($('#fcrm-rc-member-input').length) {
        wireSearch($('#fcrm-rc-member-input'), $('#fcrm-rc-suggestions'), 'search_members', function (item) {
            $('#fcrm-rc-subscriber-id').val(item ? item.subscriber_id : 0);
            $('#fcrm-rc-user-id').val(item ? item.user_id : 0);
            $('#fcrm-rc-member-summary').text(item ? (item.member_type ? item.member_type + ' · paid through ' + (item.paid_through || '—') : 'No membership state yet') : '');
        });
    }

    $('#fcrm-rc-submit').on('click', function () {
        var $btn = $(this), $out = $('#fcrm-rc-result');
        var subscriberId = parseInt($('#fcrm-rc-subscriber-id').val(), 10) || 0;
        var userId       = parseInt($('#fcrm-rc-user-id').val(), 10) || 0;
        var email        = $('#fcrm-rc-email').val();
        if (!subscriberId && !userId && !email) { $out.html('<p class="fcrm-error">' + escHtml(i18n.selectMember) + '</p>'); return; }
        if (!$('#fcrm-rc-variation').val()) { $out.html('<p class="fcrm-error">Select a membership product.</p>'); return; }
        if (!confirm(i18n.confirmRecord)) { return; }
        setBtn($btn, i18n.saving, true);
        $out.html('<p>' + i18n.loading + '</p>');
        post('record_check', {
            subscriber_id: subscriberId, user_id: userId, email: email,
            first_name: $('#fcrm-rc-first').val(), last_name: $('#fcrm-rc-last').val(),
            variation_id: $('#fcrm-rc-variation').val(),
            check_number: $('#fcrm-rc-check-number').val(),
            received_date: $('#fcrm-rc-received').val(),
            deposit_date: $('#fcrm-rc-deposit').val(),
            note: $('#fcrm-rc-note').val()
        }).done(function (resp) {
            if (!resp.success) { $out.html('<p class="fcrm-error">&#10007; ' + escHtml(errMsg(resp)) + '</p>'); return; }
            var d = resp.data, a = d.applied;
            $out.html('<div class="fcrm-notice fcrm-notice-success" style="display:block">&#10003; Order ' + link(d.order_url, '#' + d.order_id) + ' created and paid (' + escHtml(d.total) + ') for ' + escHtml(d.email) + '.' +
                (a ? ' Member type <strong>' + escHtml(a.member_type) + '</strong>, paid through <strong>' + escHtml(a.paid_through || 'n/a') + '</strong>, tags: ' + escHtml((a.tags_added || []).join(', ') || 'none new') + '.' : ' ' + escHtml(d.message)) + '</div>');
            $('#fcrm-rc-check-number, #fcrm-rc-note, #fcrm-rc-member-input, #fcrm-rc-email, #fcrm-rc-first, #fcrm-rc-last').val('');
            $('#fcrm-rc-subscriber-id, #fcrm-rc-user-id').val(0);
            $('#fcrm-rc-member-summary').text('');
            loadChecks();
        }).fail(function () { $out.html('<p class="fcrm-error">' + i18n.error + '</p>'); })
          .always(function () { resetBtn($btn); });
    });

    // =========================================================================
    // Reports
    // =========================================================================

    var reportColumns = {
        'open-applications': [
            { key: 'date', label: 'Started' },
            { key: 'age_days', label: 'Age', render: function (r) { return r.age_days + 'd'; } },
            { key: 'kind', label: 'Kind' },
            { key: 'status', label: 'Status' },
            { key: 'name', label: 'Name', render: function (r) { return r.crm_url ? link(r.crm_url, r.name || r.email) : escHtml(r.name || r.email); } },
            { key: 'email', label: 'Email' },
            { key: 'product', label: 'Product' },
            { key: 'application', label: 'Application', render: function (r) { return escHtml((r.application || []).join(' · ') || '—'); } },
            { key: 'order_id', label: 'Order', render: function (r) { return r.order_id ? link(r.order_url, '#' + r.order_id) : '—'; } }
        ],
        'orders-without-application': [
            { key: 'order_id', label: 'Order', render: function (r) { return link(r.admin_url, '#' + r.order_id); } },
            { key: 'date', label: 'Paid' },
            { key: 'name', label: 'Name' },
            { key: 'email', label: 'Email' },
            { key: 'total', label: 'Total' },
            { key: 'payment', label: 'Method' },
            { key: 'applied', label: 'CRM updated', render: function (r) { return r.applied ? '&#10003;' : '&#10007;'; } }
        ],
        'aging': [
            { key: 'id', label: 'Order', render: function (r) { return link(r.admin_url, '#' + r.id); } },
            { key: 'date', label: 'Placed' },
            { key: 'age_days', label: 'Pending', render: function (r) { return r.age_days + ' days'; } },
            { key: 'customer_name', label: 'Member', render: function (r) { return r.crm_url ? link(r.crm_url, r.customer_name || r.email) : escHtml(r.customer_name || r.email); } },
            { key: 'email', label: 'Email' },
            { key: 'member_number', label: 'Member #' },
            { key: 'total', label: 'Total' }
        ],
        'users-without-contact': [
            { key: 'user_id', label: 'User', render: function (r) { return link(r.edit_url, '#' + r.user_id + ' ' + r.login); } },
            { key: 'name', label: 'Name' },
            { key: 'email', label: 'Email' },
            { key: 'registered', label: 'Registered' }
        ],
        'contacts-missing-user': [
            { key: 'subscriber_id', label: 'Contact', render: function (r) { return link(r.crm_url, '#' + r.subscriber_id); } },
            { key: 'name', label: 'Name' },
            { key: 'email', label: 'Email' },
            { key: 'user_id', label: 'Missing user id' }
        ]
    };

    $('.fcrm-report-load').on('click', function () {
        var $btn = $(this), report = $btn.data('report'), $target = $($btn.data('target')), paged = !!$btn.data('paged');
        var days = $btn.data('days') ? parseInt($($btn.data('days')).val(), 10) || 0 : 0;
        var offset = parseInt($btn.data('next-offset'), 10) || 0;
        setBtn($btn, i18n.loading, true);
        if (!offset) { $target.html('<p>' + i18n.loading + '</p>'); }
        get('report', { report: report, days: days, offset: offset }).done(function (resp) {
            if (!resp.success) { $target.html('<p class="fcrm-error">' + escHtml(errMsg(resp)) + '</p>'); return; }
            var items = resp.data.items || [];
            var html = renderTable(items, reportColumns[report], 'Nothing found. &#10003;');
            if (paged) {
                var scanned = resp.data.next_offset || 0;
                html = '<p class="fcrm-muted">Scanned ' + scanned + (resp.data.total_users ? ' of ' + resp.data.total_users : '') + ' · ' + items.length + ' orphan(s) in this batch.</p>' + html;
                if (resp.data.has_more) { $btn.data('next-offset', resp.data.next_offset); $btn.data('orig-label', 'Load next batch'); }
                else { $btn.data('next-offset', 0); }
            } else {
                html = '<p class="fcrm-muted">' + items.length + ' row(s).</p>' + html;
            }
            if (paged && offset) { $target.append(html); } else { $target.html(html); }
        }).fail(function () { $target.html('<p class="fcrm-error">' + i18n.error + '</p>'); })
          .always(function () { resetBtn($btn); });
    });

    // =========================================================================
    // Migration
    // =========================================================================

    function migrationArgs() {
        return {
            level_map: $('#fcrm-mig-level-map').val(),
            from_year: $('#fcrm-mig-from-year').val(),
            order_statuses: $('#fcrm-mig-order-statuses').val(),
            order_tz: $('#fcrm-mig-order-tz').val(),
            address_mode: $('#fcrm-mig-address-mode').val(),
            pmpro_fresh_days: $('#fcrm-mig-fresh-days').val(),
            include_zero: $('#fcrm-mig-include-zero').is(':checked') ? 1 : 0,
            create_contacts: $('#fcrm-mig-create-contacts').is(':checked') ? 1 : 0,
            expected: $('#fcrm-mig-expected').val()
        };
    }

    function mergeCounts(a, b) {
        Object.keys(b || {}).forEach(function (k) {
            if (b[k] !== null && typeof b[k] === 'object') { a[k] = mergeCounts(a[k] || {}, b[k]); }
            else if (typeof b[k] === 'number') { a[k] = (a[k] || 0) + b[k]; }
            else { a[k] = b[k]; }
        });
        return a;
    }

    function renderReport(agg) {
        var html = '<div class="fcrm-section fcrm-mig-report"><h3>' + escHtml(agg.step) + (agg.dry_run ? ' — DRY RUN (nothing written)' : ' — APPLIED') + '</h3>';
        var countRows = Object.keys(agg.counts || {}).map(function (k) {
            var v = agg.counts[k];
            return { metric: k, value: (v !== null && typeof v === 'object') ? JSON.stringify(v) : String(v) };
        });
        html += renderTable(countRows, [{ key: 'metric', label: 'Metric' }, { key: 'value', label: 'Value' }], 'No counts.');
        Object.keys(agg.sections || {}).forEach(function (title) {
            var table = agg.sections[title];
            html += '<h4>' + escHtml(title) + '</h4>';
            if (Array.isArray(table)) {
                if (!table.length) { html += '<p class="fcrm-muted">none</p>'; return; }
                var cols = Object.keys(table[0]).map(function (k) { return { key: k, label: k, render: function (r) { return escHtml(r[k] !== null && typeof r[k] === 'object' ? JSON.stringify(r[k]) : r[k]); } }; });
                html += renderTable(table, cols);
            } else {
                var rows = Object.keys(table).map(function (k) { return { metric: k, value: typeof table[k] === 'object' ? JSON.stringify(table[k]) : String(table[k]) }; });
                html += renderTable(rows, [{ key: 'metric', label: '' }, { key: 'value', label: '' }]);
            }
        });
        if (agg.rows && agg.rows.length) {
            html += '<h4>Sample rows (' + agg.rows.length + ')</h4>';
            var rcols = Object.keys(agg.rows[0]).map(function (k) { return { key: k, label: k, render: function (r) { return escHtml(r[k] !== null && typeof r[k] === 'object' ? JSON.stringify(r[k]) : r[k]); } }; });
            html += renderTable(agg.rows, rcols);
        }
        (agg.warnings || []).forEach(function (w) { html += '<p class="fcrm-warn">&#9888; ' + escHtml(w) + '</p>'; });
        (agg.errors || []).forEach(function (e) { html += '<p class="fcrm-error">&#10007; ' + escHtml(e) + '</p>'; });
        html += '</div>';
        return html;
    }

    $('.fcrm-mig-run').on('click', function () {
        var $btn = $(this), step = $btn.data('step'), dry = String($btn.data('dry')) === '1';
        if (!dry && !confirm(i18n.confirmApply)) { return; }
        var $row = $btn.closest('tr'), $prog = $row.find('.fcrm-mig-progress'), $out = $('#fcrm-mig-report');
        var agg = null, offset = 0, limit = 100;
        $('.fcrm-mig-run').prop('disabled', true);
        $prog.text(i18n.loading);
        function page() {
            var data = $.extend({ step: step, dry_run: dry ? 1 : 0, offset: offset, limit: limit }, migrationArgs());
            post('migration_run', data).done(function (resp) {
                if (!resp.success) { $prog.html('<span class="fcrm-error">' + escHtml(errMsg(resp)) + '</span>'); $('.fcrm-mig-run').prop('disabled', false); return; }
                var r = resp.data;
                if (!agg) { agg = r; } else {
                    agg.processed += r.processed; agg.counts = mergeCounts(agg.counts || {}, r.counts || {});
                    Object.keys(r.sections || {}).forEach(function (t) {
                        if (!agg.sections[t]) { agg.sections[t] = r.sections[t]; }
                        else if (Array.isArray(r.sections[t])) { agg.sections[t] = agg.sections[t].concat(r.sections[t]); }
                        else { agg.sections[t] = mergeCounts(agg.sections[t], r.sections[t]); }
                    });
                    agg.rows = (agg.rows || []).concat(r.rows || []).slice(0, 300);
                    agg.warnings = (agg.warnings || []).concat(r.warnings || []).filter(function (v, i, a) { return a.indexOf(v) === i; });
                    agg.errors = (agg.errors || []).concat(r.errors || []);
                }
                offset = r.next_offset;
                $prog.text((r.total ? Math.min(offset, r.total) + ' / ' + r.total : agg.processed + ' processed') + (r.has_more ? '…' : ' — done'));
                if (r.has_more) { page(); } else {
                    $out.html(renderReport(agg));
                    $('.fcrm-mig-run').prop('disabled', false);
                    $('html, body').animate({ scrollTop: $out.offset().top - 40 }, 300);
                }
            }).fail(function () { $prog.html('<span class="fcrm-error">' + i18n.error + '</span>'); $('.fcrm-mig-run').prop('disabled', false); });
        }
        page();
    });

    $('#fcrm-export-orders').on('click', function () {
        var $btn = $(this), $out = $('#fcrm-export-result').html('<p>' + i18n.loading + '</p>');
        setBtn($btn, i18n.loading, true);
        post('export_orders').done(function (resp) {
            if (!resp.success) { $out.html('<p class="fcrm-error">' + escHtml(errMsg(resp)) + '</p>'); return; }
            $out.html('<p>&#10003; ' + resp.data.rows + ' orders exported. <a class="button button-primary" href="' + escHtml(resp.data.url) + '">Download CSV</a> <span class="fcrm-muted">Store it with the treasurer\'s records; the folder is not web-readable.</span></p>');
        }).fail(function () { $out.html('<p class="fcrm-error">' + i18n.error + '</p>'); })
          .always(function () { resetBtn($btn); });
    });

    // =========================================================================
    // Notes Search
    // =========================================================================

    var $notesForm = $('#my-iapsnj-notes-search-form'), $notesQuery = $('#my-iapsnj-notes-query'), $notesBtn = $('#my-iapsnj-notes-search-btn');
    var $notesResults = $('#my-iapsnj-notes-results'), $notesNotice = $('#my-iapsnj-notes-notice'), $notesMore = $('#my-iapsnj-notes-load-more');
    var notesPage = 1, notesLastQuery = '', notesTags = [];

    if ($notesForm.length) {
        post('get_tags').done(function (res) { if (res.success) { notesTags = res.data; } });
        $notesForm.on('submit', function (e) {
            e.preventDefault();
            notesPage = 1; notesLastQuery = $.trim($notesQuery.val());
            $notesResults.empty(); $notesMore.hide();
            runNotesSearch(false);
        });
        $notesMore.on('click', function () { notesPage++; runNotesSearch(true); });
    }

    function runNotesSearch(append) {
        if (!notesLastQuery) { showNotice($notesNotice, 'Please enter a search term.', 'error'); return; }
        setBtn($notesBtn, i18n.loading, true);
        post('search_notes', { query: notesLastQuery, page: notesPage }).done(function (res) {
            if (!res.success) { showNotice($notesNotice, errMsg(res), 'error'); return; }
            var data = res.data;
            if (!append && !data.results.length) {
                $notesResults.html('<p class="notes-no-results">No notes found matching “' + escHtml(notesLastQuery) + '”.</p>');
                $notesMore.hide();
                return;
            }
            data.results.forEach(function (note) { $notesResults.append(buildNoteRow(note)); });
            $notesMore.toggle(data.has_more);
            if (!append) { showNotice($notesNotice, data.total + ' result' + (data.total !== 1 ? 's' : '') + ' found.', 'info'); }
        }).fail(function () { showNotice($notesNotice, i18n.error, 'error'); })
          .always(function () { resetBtn($notesBtn); });
    }

    function buildNoteRow(note) {
        var $row = $('<div class="notes-result-row">');
        var $hdr = $('<div class="notes-result-header">');
        $hdr.append($('<span class="notes-contact-name">').text(note.contact_name || note.email));
        $hdr.append($('<span class="notes-contact-email">').text(' ‹' + note.email + '›'));
        if (note.note_date) { $hdr.append($('<span class="notes-date">').text(note.note_date)); }
        $row.append($hdr);
        if (note.note_title) { $row.append($('<div class="notes-title">').text(note.note_title)); }
        $row.append($('<div class="notes-content">').html(note.note_content || ''));

        var $area = $('<div class="notes-tag-area">'), $sel = $('<select class="notes-tag-select">');
        $sel.append($('<option value="">').text('— Assign Tag —'));
        notesTags.forEach(function (tag) { $sel.append($('<option>').val(tag.id).text(tag.title)); });
        var $assign = $('<button type="button" class="button button-secondary">').text('Assign Tag'), $fb = $('<span class="notes-tag-feedback">');
        $assign.on('click', function () {
            var tagId = $sel.val();
            if (!tagId) { $fb.attr('class', 'notes-tag-feedback notes-tag-error').text('Select a tag first.'); return; }
            $assign.prop('disabled', true).text('Assigning…');
            post('assign_tag', { subscriber_id: note.subscriber_id, tag_id: tagId }).done(function (res) {
                if (res.success) { $fb.attr('class', 'notes-tag-feedback notes-tag-success').text('Tag assigned!'); setTimeout(function () { $fb.text(''); }, 3000); }
                else { $fb.attr('class', 'notes-tag-feedback notes-tag-error').text(errMsg(res)); }
            }).fail(function () { $fb.attr('class', 'notes-tag-feedback notes-tag-error').text(i18n.error); })
              .always(function () { $assign.prop('disabled', false).text('Assign Tag'); });
        });
        $area.append($sel).append($assign).append($fb);
        $row.append($area);
        return $row;
    }

}(jQuery));
