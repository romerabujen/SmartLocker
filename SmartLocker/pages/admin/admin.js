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
  const seeMoreButton = document.querySelector('[data-see-more]');
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

    document.querySelector('[data-reservations]').innerHTML = result.reservations.length ? result.reservations.map((reservation) => `<article class="queue-item"><div><strong>${escapeHtml(reservation.locker_number)} · ${escapeHtml(reservation.first_name)} ${escapeHtml(reservation.last_name)}</strong><p>${escapeHtml(reservation.student_id)} · Requested ${formatDate(reservation.requested_at)}${reservation.ends_at ? ` · ${formatDate(reservation.starts_at)} to ${formatDate(reservation.ends_at)}` : ''}</p></div><div class="queue-actions">${reservation.status === 'pending' ? `<select data-reservation-duration="${reservation.id}" aria-label="Reservation duration"><option value="">Duration</option><option value="1_week">1 Week</option><option value="2_weeks">2 Weeks</option><option value="3_weeks">3 Weeks</option><option value="1_month">1 Month</option></select><button data-reservation-id="${reservation.id}" data-reservation-status="approved">Approve</button><button class="danger" data-reservation-id="${reservation.id}" data-reservation-status="rejected">Reject</button>` : `<span class="status ${escapeHtml(reservation.status)}">${escapeHtml(reservation.status)}</span>`}</div></article>`).join('') : '<p class="empty">No reservation history yet.</p>';

    document.querySelector('[data-reports]').innerHTML = result.reports.length ? result.reports.map((report) => `<article class="queue-item"><div><strong>${escapeHtml(report.locker_number)} · ${escapeHtml(report.issue_type)} <span class="priority ${report.priority}">${escapeHtml(report.priority)}</span></strong><p>${escapeHtml(report.description)}</p></div><button data-report-id="${report.id}">Resolve</button></article>`).join('') : '<p class="empty">No open maintenance reports.</p>';

    document.querySelector('[data-activity]').innerHTML = result.access_logs.length ? result.access_logs.map((log) => `<tr><td>${formatDate(log.occurred_at)}</td><td>${escapeHtml(log.locker_number)}</td><td>${escapeHtml(log.student_id || 'System')}</td><td>${escapeHtml(log.event_type)}</td><td><span class="result ${log.was_successful ? 'success' : 'failed'}">${log.was_successful ? 'Success' : 'Denied'}</span></td></tr>`).join('') : '<tr><td colspan="5" class="empty">No access activity recorded.</td></tr>';
    document.querySelector('[data-users]').innerHTML = result.recent_users.length ? result.recent_users.map((user) => `<div class="student-row"><span class="student-avatar">${escapeHtml((user.first_name?.[0] || '') + (user.last_name?.[0] || ''))}</span><div><strong>${escapeHtml(user.first_name)} ${escapeHtml(user.last_name)}</strong><small>${escapeHtml(user.student_id)} · ${escapeHtml(user.email)}</small></div></div>`).join('') : '<p class="empty">No student accounts yet.</p>';
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
  loadData().catch(() => { window.location.href = '../login/login.html'; });
});
