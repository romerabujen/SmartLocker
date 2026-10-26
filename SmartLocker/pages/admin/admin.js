document.addEventListener('DOMContentLoaded', () => {
  const toast = document.querySelector('[data-toast]');
  const showToast = (message) => {
    toast.textContent = message;
    toast.classList.add('visible');
    window.setTimeout(() => toast.classList.remove('visible'), 2600);
  };
  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[character]));
  const formatDate = (value) => value ? new Date(value.replace(' ', 'T')).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : '—';
  const buildingTabsElement = document.querySelector('[data-building-tabs]');
  const assignedModal = document.querySelector('[data-assigned-modal]');
  const assignedLockersElement = document.querySelector('[data-assigned-lockers]');
  const seeMoreButton = document.querySelector('[data-see-more]');
  const reportFilters = document.querySelector('[data-report-filters]');
  const localDateString = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
  const renderBreakdown = (element, rows, labelKey = 'status') => {
    element.innerHTML = rows.length ? rows.map((row) => `<div class="analytics-row"><span>${escapeHtml(String(row[labelKey] || 'Unknown').replaceAll('_', ' '))}</span><strong>${Number(row.total || 0).toLocaleString()}</strong></div>`).join('') : '<p class="empty">No data for this period.</p>';
  };
  const formatNumber = (value) => Number(value || 0).toLocaleString();
  let insights = null;
  let lockers = [];
  let selectedBuilding = '';
  let visibleLockerCount = 10;

  const postAction = async (data) => {
    const response = await fetch('../../php/admin_action.php', { method: 'POST', body: data });
    const result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message || 'Update failed.');
    showToast(result.message);
    await loadData();
  };

  const loadInsights = async () => {
    const query = new URLSearchParams(new FormData(reportFilters));
    const response = await fetch(`../../php/admin_insights.php?${query.toString()}`);
    const result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message || 'Unable to generate report.');
    insights = result;
    document.querySelector('[data-report-period]').textContent = `Reporting period: ${result.from} to ${result.to}. Snapshot totals are current; activity metrics use this date range.`;
    document.querySelector('[data-audit-period]').textContent = `Showing administrator actions from ${result.from} to ${result.to} (up to 250 latest records).`;
    const accessRate = result.access.total ? Math.round(result.access.successful * 100 / result.access.total) : 0;
    const metricRows = [
      ['Registered students', result.counts.students],
      ['Total lockers', result.counts.lockers],
      ['Active assignments', result.counts.active_assignments],
      ['Reservations in range', result.counts.reservations],
      ['Access attempts', result.counts.access_attempts],
      ['Successful access', `${accessRate}%`],
      ['Maintenance reports', result.counts.maintenance_reports],
      ['Paid transactions', result.counts.paid_transactions],
      ['Revenue collected', `₱${formatNumber(result.counts.revenue_pesos)}`],
    ];
    document.querySelector('[data-report-metrics]').innerHTML = metricRows.map(([label, value]) => `<div class="report-metric"><span>${escapeHtml(label)}</span><strong>${escapeHtml(value)}</strong></div>`).join('');
    renderBreakdown(document.querySelector('[data-locker-analytics]'), result.locker_status);
    renderBreakdown(document.querySelector('[data-reservation-analytics]'), result.reservation_status);
    const accessRows = [
      { status: 'Attempts', total: result.access.total },
      { status: 'Successful', total: result.access.successful },
      { status: 'Denied', total: result.access.denied },
      { status: 'Login attempts', total: result.logins.total },
      { status: 'Failed logins', total: result.logins.failed },
    ];
    renderBreakdown(document.querySelector('[data-access-analytics]'), accessRows);
    renderBreakdown(document.querySelector('[data-maintenance-analytics]'), [
      { status: 'Reported', total: result.maintenance.total },
      { status: 'Unresolved', total: result.maintenance.unresolved },
      { status: 'Resolved or closed', total: result.maintenance.resolved },
    ]);
    document.querySelector('[data-top-lockers]').innerHTML = result.top_lockers.length
      ? result.top_lockers.map((locker) => `<tr><td>${escapeHtml(locker.locker_number)}</td><td>${escapeHtml([locker.building, locker.floor].filter(Boolean).join(' / '))}</td><td>${formatNumber(locker.attempts)}</td><td>${formatNumber(locker.successful)}</td></tr>`).join('')
      : '<tr><td colspan="4" class="empty">No access activity for this period.</td></tr>';
    document.querySelector('[data-audit-logs]').innerHTML = result.audit_logs.length
      ? result.audit_logs.map((entry) => `<tr><td>${formatDate(entry.occurred_at)}</td><td>Admin #${formatNumber(entry.admin_id)}</td><td>${escapeHtml(entry.action.replaceAll('_', ' '))}</td><td>${escapeHtml(entry.target_type)}${entry.target_id ? ` #${escapeHtml(entry.target_id)}` : ''}</td><td>${escapeHtml(entry.details)}</td><td>${escapeHtml(entry.ip_address || '—')}</td></tr>`).join('')
      : '<tr><td colspan="6" class="empty">No administrator actions recorded for this period.</td></tr>';
  };

  const loadData = async () => {
    const response = await fetch('../../php/admin_data.php');
    const result = await response.json();
    if (!response.ok || !result.success) {
      window.location.href = '../login/login.html';
      return;
    }

    Object.entries(result.counts).forEach(([key, value]) => {
      document.querySelector(`[data-count="${key}"]`).textContent = value;
    });
    lockers = result.lockers;
    renderLockers();
    renderAssignedLockers(result.assigned_lockers || []);

    document.querySelector('[data-reservations]').innerHTML = result.reservations.length ? result.reservations.map((reservation) => {
      const paid = reservation.payment_status === 'paid';
      const hasPayment = Boolean(reservation.payment_status);
      const term = { '1_week': '1 Week', '1_month': '1 Month', '3_months': '3 Months' }[reservation.duration] || reservation.duration || '';
      const actions = reservation.status !== 'pending'
        ? `<span class="status ${escapeHtml(reservation.status)}">${escapeHtml(reservation.status)}</span>`
        : hasPayment && !paid
          ? `<span class="status pending">Payment ${escapeHtml(reservation.payment_status)}</span>`
          : `${paid ? `<span>${escapeHtml(term)} · ₱${escapeHtml(reservation.amount_pesos)}</span>` : `<select data-reservation-duration="${reservation.id}" aria-label="Reservation duration"><option value="">Duration</option><option value="1_week">1 Week</option><option value="1_month">1 Month</option><option value="3_months">3 Months</option></select>`}<button data-reservation-id="${reservation.id}" data-reservation-status="approved">Approve</button>${!paid || reservation.payment_mode === 'simulation' ? `<button class="danger" data-reservation-id="${reservation.id}" data-reservation-status="rejected">Reject</button>` : ''}`;
      return `<article class="queue-item"><div><strong>${escapeHtml(reservation.locker_number)} · ${escapeHtml(reservation.first_name)} ${escapeHtml(reservation.last_name)}</strong><p>${escapeHtml(reservation.student_id)} · Requested ${formatDate(reservation.requested_at)}${reservation.ends_at ? ` · ${formatDate(reservation.starts_at)} to ${formatDate(reservation.ends_at)}` : ''}</p></div><div class="queue-actions">${actions}</div></article>`;
    }).join('') : '<p class="empty">No reservation history yet.</p>';

    document.querySelector('[data-reports]').innerHTML = result.reports.length ? result.reports.map((report) => `<article class="queue-item"><div><strong>${escapeHtml(report.locker_number)} · ${escapeHtml(report.issue_type)} <span class="priority ${report.priority}">${escapeHtml(report.priority)}</span></strong><p>${escapeHtml(report.description)}</p></div><button data-report-id="${report.id}">Resolve</button></article>`).join('') : '<p class="empty">No open maintenance reports.</p>';

    document.querySelector('[data-activity]').innerHTML = result.access_logs.length ? result.access_logs.map((log) => `<tr><td>${formatDate(log.occurred_at)}</td><td>${escapeHtml(log.locker_number)}</td><td>${escapeHtml(log.student_id || 'System')}</td><td>${escapeHtml(log.event_type)}</td><td><span class="result ${log.was_successful ? 'success' : 'failed'}">${log.was_successful ? 'Success' : 'Denied'}</span></td></tr>`).join('') : '<tr><td colspan="5" class="empty">No access activity recorded.</td></tr>';
    document.querySelector('[data-users]').innerHTML = result.recent_users.length ? result.recent_users.map((user) => `<div class="student-row"><span class="student-avatar">${escapeHtml((user.first_name?.[0] || '') + (user.last_name?.[0] || ''))}</span><div><strong>${escapeHtml(user.first_name)} ${escapeHtml(user.last_name)}</strong><small>${escapeHtml(user.student_id)} · ${escapeHtml(user.email)}</small></div></div>`).join('') : '<p class="empty">No student accounts yet.</p>';
    try {
      await loadInsights();
    } catch (error) {
      showToast(error.message);
    }
  };

  const openPrintView = (title, content) => {
    const printWindow = window.open('', '_blank');
    if (!printWindow) {
      showToast('Allow pop-ups to print or save this document as PDF.');
      return;
    }
    printWindow.document.write(`<!doctype html><html lang="en"><head><meta charset="utf-8"><title>${escapeHtml(title)}</title><style>
      body{font:14px Arial,sans-serif;color:#17202a;margin:28px}h1{font-size:22px;margin:0 0 6px}
      p{color:#59636e;margin:0 0 18px}.report-metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin:14px 0}
      .report-metric,.analytics-grid>div,.report-top-lockers{border:1px solid #d9dfdc;border-radius:6px;padding:10px}
      .report-metric span,.report-metric strong{display:block}.report-metric span{font-size:11px;color:#59636e}
      .report-metric strong{margin-top:5px;font-size:17px}.analytics-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
      .analytics-grid h3,.report-top-lockers h3{font-size:14px;margin:0 0 8px}.analytics-row{display:flex;justify-content:space-between;padding:4px 0}
      table{width:100%;border-collapse:collapse}th,td{text-align:left;border-bottom:1px solid #d9dfdc;padding:7px;font-size:11px;overflow-wrap:anywhere}
      th{background:#f1f4f2}@media print{body{margin:12mm}.report-metric,.analytics-grid>div,.report-top-lockers{break-inside:avoid}}
      </style></head><body><h1>${escapeHtml(title)}</h1><p>SmartLocker administration · ${escapeHtml(insights?.from || '')} to ${escapeHtml(insights?.to || '')} · Generated ${escapeHtml(new Date().toLocaleString())}</p>${content}</body></html>`);
    printWindow.document.close();
    window.setTimeout(() => {
      printWindow.focus();
      printWindow.print();
    }, 300);
  };

  const downloadPdf = (pages, filename) => {
    const escapePdfText = (value) => String(value ?? '')
      .replace(/₱/g, 'PHP ')
      .normalize('NFKD')
      .replace(/[\u0300-\u036f]/g, '')
      .replace(/[^\x20-\x7E]/g, '?')
      .replace(/([\\()])/g, '\\$1');
    const text = (value, x, y, size = 9, color = '0.09 0.13 0.16', font = 'F1') =>
      `BT /${font} ${size} Tf ${color} rg 1 0 0 1 ${x} ${792 - y} Tm (${escapePdfText(value)}) Tj ET`;
    const line = (x1, y1, x2, y2, color = '0.84 0.87 0.85') =>
      `${color} RG 0.6 w ${x1} ${792 - y1} m ${x2} ${792 - y2} l S`;
    const pagesWithFooters = pages.map((pageContent, index) => [
      pageContent,
      line(48, 758, 564, 758),
      text('SmartLocker Administration', 48, 775, 7, '0.35 0.39 0.42'),
      text(`${index + 1}/${pages.length}`, 548, 775, 7, '0.35 0.39 0.42'),
    ].join('\n'));
    const objects = [];
    const addObject = (value) => {
      objects.push(value);
      return objects.length;
    };
    const catalogId = addObject('');
    const pagesId = addObject('');
    const fontId = addObject('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>');
    const boldFontId = addObject('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>');
    const pageIds = [];
    pagesWithFooters.forEach((pageContent) => {
      const pageId = objects.length + 1;
      const contentId = pageId + 1;
      addObject(`<< /Type /Page /Parent ${pagesId} 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 ${fontId} 0 R /F2 ${boldFontId} 0 R >> >> /Contents ${contentId} 0 R >>`);
      addObject(`<< /Length ${pageContent.length} >>\nstream\n${pageContent}\nendstream`);
      pageIds.push(pageId);
    });
    objects[catalogId - 1] = `<< /Type /Catalog /Pages ${pagesId} 0 R >>`;
    objects[pagesId - 1] = `<< /Type /Pages /Kids [${pageIds.map((id) => `${id} 0 R`).join(' ')}] /Count ${pageIds.length} >>`;

    let pdf = '%PDF-1.4\n';
    const offsets = [0];
    objects.forEach((object, index) => {
      offsets.push(pdf.length);
      pdf += `${index + 1} 0 obj\n${object}\nendobj\n`;
    });
    const xrefOffset = pdf.length;
    pdf += `xref\n0 ${objects.length + 1}\n0000000000 65535 f \n`;
    offsets.slice(1).forEach((offset) => {
      pdf += `${String(offset).padStart(10, '0')} 00000 n \n`;
    });
    pdf += `trailer\n<< /Size ${objects.length + 1} /Root ${catalogId} 0 R >>\nstartxref\n${xrefOffset}\n%%EOF`;

    const url = URL.createObjectURL(new Blob([pdf], { type: 'application/pdf' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.append(link);
    link.click();
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
  };

  const buildReportPdf = () => {
    const commands = [];
    const line = (x1, y1, x2, y2) =>
      commands.push(`0.84 0.87 0.85 RG 0.6 w ${x1} ${792 - y1} m ${x2} ${792 - y2} l S`);
    const drawText = (value, x, y, size = 9, color, font = 'F1') => {
      const safeText = String(value ?? '').replace(/₱/g, 'PHP ').normalize('NFKD')
        .replace(/[\u0300-\u036f]/g, '').replace(/[^\x20-\x7E]/g, '?').replace(/([\\()])/g, '\\$1');
      commands.push(`BT /${font} ${size} Tf ${color || '0.09 0.13 0.16'} rg 1 0 0 1 ${x} ${792 - y} Tm (${safeText}) Tj ET`);
    };
    const rect = (x, y, width, height) => {
      commands.push(`0.84 0.87 0.85 RG 0.65 w ${x} ${792 - y - height} ${width} ${height} re S`);
    };
    const drawPanel = (title, rows, x, y, width) => {
      const height = 30 + Math.max(rows.length, 1) * 14;
      rect(x, y, width, height);
      drawText(title, x + 8, y + 14, 9, '0.09 0.13 0.16', 'F2');
      if (rows.length) {
        rows.forEach((row, index) => {
          const rowY = y + 29 + index * 14;
          drawText(row[0], x + 8, rowY, 8, '0.22 0.26 0.29');
          drawText(row[1], x + width - 52, rowY, 8, '0.09 0.13 0.16', 'F2');
        });
      } else {
        drawText('No data for this period.', x + 8, y + 31, 8, '0.35 0.39 0.42');
      }
      return height;
    };
    const accessRate = insights.access.total ? Math.round(insights.access.successful * 100 / insights.access.total) : 0;
    const metrics = [
      ['Registered students', formatNumber(insights.counts.students)],
      ['Total lockers', formatNumber(insights.counts.lockers)],
      ['Active assignments', formatNumber(insights.counts.active_assignments)],
      ['Reservations in range', formatNumber(insights.counts.reservations)],
      ['Access attempts', formatNumber(insights.counts.access_attempts)],
      ['Successful access', `${accessRate}%`],
      ['Maintenance reports', formatNumber(insights.counts.maintenance_reports)],
      ['Paid transactions', formatNumber(insights.counts.paid_transactions)],
      ['Revenue collected', `PHP ${formatNumber(insights.counts.revenue_pesos)}`],
    ];

    drawText('SmartLocker Operations Report', 58, 52, 16, '0.09 0.13 0.16', 'F2');
    drawText(`SmartLocker administration | ${insights.from} to ${insights.to} | Generated ${new Date().toLocaleString()}`, 58, 69, 8, '0.35 0.39 0.42');
    const cardWidth = 164;
    const cardHeight = 40;
    metrics.forEach(([label, value], index) => {
      const column = index % 3;
      const row = Math.floor(index / 3);
      const x = 58 + column * 171;
      const y = 82 + row * 45;
      rect(x, y, cardWidth, cardHeight);
      drawText(label, x + 7, y + 14, 7, '0.35 0.39 0.42');
      drawText(value, x + 7, y + 30, 10, '0.09 0.13 0.16', 'F2');
    });

    const leftX = 58;
    const rightX = 315;
    const panelWidth = 239;
    const firstY = 226;
    const firstLeftHeight = drawPanel('Locker availability', insights.locker_status.map((row) => [row.status, formatNumber(row.total)]), leftX, firstY, panelWidth);
    const firstRightHeight = drawPanel('Reservations', insights.reservation_status.map((row) => [row.status, formatNumber(row.total)]), rightX, firstY, panelWidth);
    const secondY = firstY + Math.max(firstLeftHeight, firstRightHeight) + 7;
    const accessRows = [
      ['Attempts', formatNumber(insights.access.total)],
      ['Successful', formatNumber(insights.access.successful)],
      ['Denied', formatNumber(insights.access.denied)],
      ['Login attempts', formatNumber(insights.logins.total)],
      ['Failed logins', formatNumber(insights.logins.failed)],
    ];
    const maintenanceRows = [
      ['Reported', formatNumber(insights.maintenance.total)],
      ['Unresolved', formatNumber(insights.maintenance.unresolved)],
      ['Resolved or closed', formatNumber(insights.maintenance.resolved)],
    ];
    const secondLeftHeight = drawPanel('Access activity', accessRows, leftX, secondY, panelWidth);
    const secondRightHeight = drawPanel('Maintenance', maintenanceRows, rightX, secondY, panelWidth);
    let tableY = secondY + Math.max(secondLeftHeight, secondRightHeight) + 7;
    rect(58, tableY, 496, 27 + Math.max(insights.top_lockers.length, 1) * 14);
    drawText('Most accessed lockers', 66, tableY + 14, 9, '0.09 0.13 0.16', 'F2');
    const columns = [66, 150, 265, 395];
    const headers = ['Locker', 'Location', 'Access attempts', 'Successful'];
    headers.forEach((header, index) => drawText(header, columns[index], tableY + 26, 6, '0.35 0.39 0.42', 'F2'));
    line(66, tableY + 31, 546, tableY + 31);
    const lockerRows = insights.top_lockers.length
      ? insights.top_lockers.map((locker) => [
        locker.locker_number,
        [locker.building, locker.floor].filter(Boolean).join(' / '),
        formatNumber(locker.attempts),
        formatNumber(locker.successful),
      ])
      : [['No access activity for this period.', '', '', '']];
    lockerRows.forEach((row, index) => {
      const y = tableY + 44 + index * 14;
      row.forEach((value, column) => drawText(value, columns[column], y, 7, '0.09 0.13 0.16'));
      line(66, y + 4, 546, y + 4);
    });
    return [commands.join('\n')];
  };

  const buildAuditPdf = () => {
    const pages = [];
    const pageWidth = 496;
    const normalize = (value) => String(value ?? '').replace(/₱/g, 'PHP ').normalize('NFKD').replace(/[\u0300-\u036f]/g, '').replace(/[^\x20-\x7E]/g, '?');
    const wrap = (value, limit = 95) => {
      const words = normalize(value).split(/\s+/);
      const lines = [];
      let current = '';
      words.forEach((word) => {
        if (current && `${current} ${word}`.length > limit) {
          lines.push(current);
          current = word;
        } else {
          current = current ? `${current} ${word}` : word;
        }
      });
      if (current) lines.push(current);
      return lines.length ? lines : [''];
    };
    let commands = [];
    let y = 86;
    const startPage = (pageIndex) => {
      commands = [];
      commands.push(`BT /F1 16 Tf 0.09 0.13 0.16 rg 1 0 0 1 58 740 Tm (SmartLocker Administrator Audit Log) Tj ET`);
      commands.push(`BT /F1 8 Tf 0.35 0.39 0.42 rg 1 0 0 1 58 723 Tm (${normalize(`SmartLocker administration | ${insights.from} to ${insights.to} | Generated ${new Date().toLocaleString()}`).replace(/([\\()])/g, '\\$1')}) Tj ET`);
      commands.push(`0.84 0.87 0.85 RG 0.65 w 58 690 ${pageWidth} 24 re S`);
      commands.push(`BT /F2 8 Tf 0.09 0.13 0.16 rg 1 0 0 1 66 698 Tm (Administrator actions | ${insights.audit_logs.length} records, maximum 250) Tj ET`);
      y = 118;
    };
    const finishPage = () => pages.push(commands.join('\n'));
    startPage(0);
    if (!insights.audit_logs.length) {
      commands.push(`BT /F1 9 Tf 0.35 0.39 0.42 rg 1 0 0 1 66 ${792 - y} Tm (No administrator actions were recorded for this period.) Tj ET`);
    } else {
      insights.audit_logs.forEach((entry) => {
        const details = wrap(`Details: ${entry.details}`, 91);
        const targetLine = `${formatDate(entry.occurred_at)} | Admin #${entry.admin_id} | ${entry.action.replaceAll('_', ' ')} | ${entry.target_type}${entry.target_id ? ` #${entry.target_id}` : ''}`;
        const height = 39 + details.length * 11;
        if (y + height > 744) {
          finishPage();
          startPage(pages.length);
        }
        commands.push(`0.84 0.87 0.85 RG 0.65 w 58 ${792 - y - height} ${pageWidth} ${height} re S`);
        const safeTarget = normalize(targetLine).replace(/([\\()])/g, '\\$1');
        commands.push(`BT /F2 8 Tf 0.09 0.13 0.16 rg 1 0 0 1 66 ${792 - y - 14} Tm (${safeTarget}) Tj ET`);
        const safeIp = normalize(`IP: ${entry.ip_address || 'Not recorded'}`).replace(/([\\()])/g, '\\$1');
        commands.push(`BT /F1 7 Tf 0.35 0.39 0.42 rg 1 0 0 1 66 ${792 - y - 26} Tm (${safeIp}) Tj ET`);
        details.forEach((detail, index) => {
          const safeDetail = detail.replace(/([\\()])/g, '\\$1');
          commands.push(`BT /F1 7 Tf 0.22 0.26 0.29 rg 1 0 0 1 66 ${792 - y - 38 - index * 11} Tm (${safeDetail}) Tj ET`);
        });
        y += height + 7;
      });
    }
    finishPage();
    return pages;
  };

  const downloadReportPdf = () => {
    downloadPdf(buildReportPdf(), `smartlocker-report-${insights.from}-to-${insights.to}.pdf`);
  };

  const downloadAuditPdf = () => {
    downloadPdf(buildAuditPdf(), `smartlocker-audit-log-${insights.from}-to-${insights.to}.pdf`);
  };

  const renderAssignedLockers = (assignments) => {
    assignedLockersElement.innerHTML = assignments.length ? assignments.map((assignment) => `<tr><td><strong>${escapeHtml(assignment.locker_number)}</strong><small class="table-subtext">${escapeHtml([assignment.building, assignment.floor, assignment.area].filter(Boolean).join(' / '))}</small></td><td><strong>${escapeHtml(assignment.first_name)} ${escapeHtml(assignment.last_name)}</strong><small class="table-subtext">${escapeHtml(assignment.student_id)}</small></td><td>${formatDate(assignment.assigned_at)}</td><td>${formatDate(assignment.expires_at)}</td><td class="assigned-actions"><button type="button" data-unassign-id="${assignment.id}">Unassign</button>${assignment.locker_status === 'maintenance' ? `<button type="button" class="maintenance-button" data-complete-maintenance-id="${assignment.locker_id}">Maintenance done</button>` : `<button type="button" class="maintenance-button" data-maintain-locker-id="${assignment.locker_id}">Maintenance</button>`}</td></tr>`).join('') : '<tr><td colspan="5" class="empty">No assigned lockers.</td></tr>';
  };

  const renderLockers = () => {
    const buildings = [...new Set(lockers.map((locker) => locker.building).filter(Boolean))].sort();
    if (!selectedBuilding || !buildings.includes(selectedBuilding)) selectedBuilding = buildings[0] || '';
    buildingTabsElement.innerHTML = buildings.map((building) => `<button class="building-tab ${building === selectedBuilding ? 'active' : ''}" data-building="${escapeHtml(building)}" type="button" role="tab" aria-selected="${building === selectedBuilding}">${escapeHtml(building)}</button>`).join('');
    const visibleLockers = lockers.filter((locker) => locker.building === selectedBuilding).slice(0, visibleLockerCount);
    document.querySelector('[data-lockers]').innerHTML = visibleLockers.length ? visibleLockers.map((locker) => `
      <tr><td><strong>${escapeHtml(locker.locker_number)}</strong></td><td>${escapeHtml([locker.building, locker.floor, locker.area].filter(Boolean).join(' / '))}</td><td>${escapeHtml(locker.size)}</td><td><span class="device ${locker.device_status === 'online' ? 'online' : ''}">${escapeHtml(locker.device_status || 'Unpaired')}</span></td><td><span class="status ${escapeHtml(locker.status)}">${escapeHtml(locker.status)}</span></td><td class="locker-actions"><select data-locker-id="${locker.id}" aria-label="Update locker status"><option value="">Change status</option><option value="available">Available</option><option value="occupied">Occupied</option><option value="maintenance">Maintenance</option><option value="offline">Offline</option></select><button type="button" class="edit-locker" data-edit-locker='${escapeHtml(JSON.stringify(locker))}'>Edit</button><button type="button" class="delete-locker danger" data-delete-locker-id="${locker.id}">Delete</button></td></tr>`).join('') : '<tr><td colspan="6" class="empty">No lockers have been added yet.</td></tr>';
    seeMoreButton.hidden = visibleLockerCount >= lockers.filter((locker) => locker.building === selectedBuilding).length;
  };

  document.addEventListener('change', (event) => {
    if (!event.target.matches('[data-locker-id]') || !event.target.value) return;
    const data = new FormData();
    data.append('action', 'update_locker_status'); data.append('locker_id', event.target.dataset.lockerId); data.append('status', event.target.value);
    postAction(data).catch((error) => showToast(error.message));
  });
  document.addEventListener('click', (event) => {
    const buildingTab = event.target.closest('[data-building]');
    if (buildingTab) {
      selectedBuilding = buildingTab.dataset.building;
      visibleLockerCount = 10;
      renderLockers();
      return;
    }
    if (event.target.closest('[data-see-more]')) {
      visibleLockerCount += 10;
      renderLockers();
      return;
    }
    const reservation = event.target.closest('[data-reservation-id]');
    const report = event.target.closest('[data-report-id]');
    const editLocker = event.target.closest('[data-edit-locker]');
    const deleteLocker = event.target.closest('[data-delete-locker-id]');
    const unassign = event.target.closest('[data-unassign-id]');
    const maintain = event.target.closest('[data-maintain-locker-id]');
    const completeMaintenance = event.target.closest('[data-complete-maintenance-id]');
    if (event.target.closest('[data-open-assigned]')) {
      assignedModal.hidden = false;
      return;
    }
    if (event.target.closest('[data-close-assigned]')) {
      assignedModal.hidden = true;
      return;
    }
    if (unassign) {
      const reason = window.prompt('Reason for unassigning this locker:');
      if (reason === null || !reason.trim()) return;
      if (!window.confirm('Unassign this locker? The student will receive a 24-hour vacate notice.')) return;
      const data = new FormData();
      data.append('action', 'unassign_locker'); data.append('assignment_id', unassign.dataset.unassignId); data.append('reason', reason.trim());
      postAction(data).catch((error) => showToast(error.message));
      return;
    }
    if (maintain) {
      const reason = window.prompt('Maintenance reason (for example, cleaning or regular maintenance):');
      if (reason === null || !reason.trim()) return;
      if (!window.confirm('Put this locker on maintenance? The student will receive a 24-hour vacate notice.')) return;
      const data = new FormData();
      data.append('action', 'maintain_assigned_locker'); data.append('locker_id', maintain.dataset.maintainLockerId); data.append('reason', reason.trim());
      postAction(data).catch((error) => showToast(error.message));
      return;
    }
    if (completeMaintenance) {
      if (!window.confirm('Mark maintenance as done and notify the student that the locker is ready?')) return;
      const data = new FormData();
      data.append('action', 'complete_assigned_maintenance'); data.append('locker_id', completeMaintenance.dataset.completeMaintenanceId);
      postAction(data).catch((error) => showToast(error.message));
      return;
    }
    if (editLocker) {
      const locker = JSON.parse(editLocker.dataset.editLocker);
      const lockerNumber = window.prompt('Locker number:', locker.locker_number);
      if (lockerNumber === null) return;
      const building = window.prompt('Building:', locker.building);
      if (building === null) return;
      const floor = window.prompt('Floor:', locker.floor);
      if (floor === null) return;
      const area = window.prompt('Area (optional):', locker.area || '');
      if (area === null) return;
      const size = window.prompt('Size (small, medium, or large):', locker.size);
      if (size === null) return;
      const data = new FormData();
      data.append('action', 'edit_locker'); data.append('locker_id', locker.id); data.append('locker_number', lockerNumber.trim()); data.append('building', building.trim()); data.append('floor', floor.trim()); data.append('area', area.trim()); data.append('size', size.trim().toLowerCase());
      postAction(data).catch((error) => showToast(error.message));
    }
    if (deleteLocker) {
      if (!window.confirm('Delete this locker? This is allowed only when it has no reservations, assignments, devices, access logs, or maintenance history.')) return;
      const data = new FormData();
      data.append('action', 'delete_locker'); data.append('locker_id', deleteLocker.dataset.deleteLockerId);
      postAction(data).catch((error) => showToast(error.message));
    }
    if (reservation) {
      const data = new FormData();
      data.append('action', 'update_reservation'); data.append('reservation_id', reservation.dataset.reservationId); data.append('status', reservation.dataset.reservationStatus);
      const duration = document.querySelector(`[data-reservation-duration="${reservation.dataset.reservationId}"]`);
      if (duration) data.append('duration', duration.value);
      postAction(data).catch((error) => showToast(error.message));
    }
    if (report) { const data = new FormData(); data.append('action', 'resolve_report'); data.append('report_id', report.dataset.reportId); postAction(data).catch((error) => showToast(error.message)); }
    if (event.target.matches('[data-action="logout"]')) { fetch('../../php/logout.php', { method: 'POST' }).finally(() => { window.location.href = '../login/login.html'; }); }
  });
  document.querySelector('[data-create-locker]').addEventListener('submit', (event) => {
    event.preventDefault();
    const data = new FormData(event.target);
    data.append('action', 'create_locker');
    postAction(data).then(() => event.target.reset()).catch((error) => showToast(error.message));
  });
  document.querySelector('[data-refresh]').addEventListener('click', () => loadData().then(() => showToast('Data refreshed.')).catch(() => showToast('Unable to refresh data.')));
  reportFilters.addEventListener('submit', (event) => {
    event.preventDefault();
    loadInsights().catch((error) => showToast(error.message));
  });
  document.querySelector('[data-print-report]').addEventListener('click', () => {
    if (!insights) {
      showToast('Generate a report before printing.');
      return;
    }
    const content = `${document.querySelector('[data-report-metrics]').outerHTML}${document.querySelector('.analytics-grid').outerHTML}${document.querySelector('.report-top-lockers').outerHTML}`;
    openPrintView('SmartLocker Operations Report', content);
  });
  document.querySelector('[data-print-audit]').addEventListener('click', () => {
    if (!insights) {
      showToast('Load the audit log before printing.');
      return;
    }
    const content = document.querySelector('[data-audit-logs]').closest('table').outerHTML;
    openPrintView('SmartLocker Administrator Audit Log', content);
  });
  document.querySelector('[data-download-report]').addEventListener('click', () => {
    if (!insights) {
      showToast('Generate a report before downloading it.');
      return;
    }
    downloadReportPdf();
  });
  document.querySelector('[data-download-audit]').addEventListener('click', () => {
    if (!insights) {
      showToast('Load the audit log before downloading it.');
      return;
    }
    downloadAuditPdf();
  });
  const today = new Date();
  const monthAgo = new Date(today);
  monthAgo.setDate(monthAgo.getDate() - 29);
  reportFilters.elements.from.value = localDateString(monthAgo);
  reportFilters.elements.to.value = localDateString(today);
  loadData().catch(() => { window.location.href = '../login/login.html'; });
});
