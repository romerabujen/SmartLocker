document.addEventListener('DOMContentLoaded', () => {
  const lockersElement = document.querySelector('[data-lockers]');
  const reservationsElement = document.querySelector('[data-reservations]');
  const buildingTabsElement = document.querySelector('[data-building-tabs]');
  const seeMoreButton = document.querySelector('[data-see-more]');
  const modalElement = document.getElementById('lockerActionModal');
  const modalMessage = document.getElementById('lockerActionMessage');
  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[character]));
  const formatDate = (value) => value ? new Date(value.replace(' ', 'T')).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : '—';
  let lockers = [];
  let selectedBuilding = '';
  let visibleLockerCount = 10;
  const showMessage = (message) => {
    modalMessage.textContent = message;
    new bootstrap.Modal(modalElement).show();
  };

  const renderLockers = () => {
    const buildings = [...new Set(lockers.map((locker) => locker.building).filter(Boolean))].sort();
    if (!selectedBuilding || !buildings.includes(selectedBuilding)) selectedBuilding = buildings[0] || '';
    buildingTabsElement.innerHTML = buildings.map((building) => `<button class="building-tab ${building === selectedBuilding ? 'active' : ''}" data-building="${escapeHtml(building)}" type="button" role="tab" aria-selected="${building === selectedBuilding}">${escapeHtml(building)}</button>`).join('');
    const buildingLockers = lockers.filter((locker) => locker.building === selectedBuilding);
    const visibleLockers = buildingLockers.slice(0, visibleLockerCount);
    lockersElement.innerHTML = visibleLockers.length ? visibleLockers.map((locker) => {
      const status = locker.current_status || locker.status;
      const isAvailable = status === 'available';
      const label = status === 'pending' ? 'Pending Reservation' : status.charAt(0).toUpperCase() + status.slice(1);
      const lockerLabel = [locker.building, locker.floor, locker.locker_number].filter(Boolean).join(' · ');
      return `<article class="locker-card ${escapeHtml(status)}"><div class="locker-top"><span class="locker-name">${escapeHtml(lockerLabel)}</span><span class="locker-status-pill ${isAvailable ? 'available-pill' : 'occupied-pill'}">${escapeHtml(label)}</span></div><p>${escapeHtml(locker.area || locker.size || 'Locker')}</p>${isAvailable ? `<button class="reserve-button" data-locker-id="${locker.id}" data-locker-name="${escapeHtml(lockerLabel)}" type="button">Request reservation</button>` : ''}</article>`;
    }).join('') : '<p class="locker-empty">No lockers are available in this building.</p>';
    seeMoreButton.hidden = visibleLockerCount >= buildingLockers.length;
  };

  const loadReservations = async () => {
    const response = await fetch('../../php/student_data.php');
    const result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message || 'Unable to load reservations.');

    lockers = result.lockers;
    renderLockers();

    reservationsElement.innerHTML = result.reservations.length ? result.reservations.map((reservation) => `<tr><td>${escapeHtml(reservation.locker_number)}</td><td>${formatDate(reservation.requested_at)}</td><td>${formatDate(reservation.starts_at)}</td><td>${formatDate(reservation.ends_at)}</td><td><span class="status-badge ${escapeHtml(reservation.status)}">${escapeHtml(reservation.status)}</span></td></tr>`).join('') : '<tr><td colspan="5">No reservation requests yet.</td></tr>';
    const currentReservation = result.reservations.find((reservation) => ['approved', 'active'].includes(reservation.status));
    const currentLocker = currentReservation && result.lockers.find((locker) => String(locker.id) === String(currentReservation.locker_id));
    document.querySelector('[data-current-status]').textContent = currentReservation ? 'Reserved' : 'No active locker';
    document.querySelector('[data-current-building]').textContent = currentLocker?.building || '—';
    document.querySelector('[data-current-floor]').textContent = currentLocker?.floor || '—';
    document.querySelector('[data-current-locker]').textContent = currentReservation?.locker_number || '—';
    document.querySelector('[data-current-status-text]').textContent = currentReservation?.status?.toUpperCase() || '—';
    document.querySelector('[data-current-reservation]').textContent = currentReservation ? 'Active' : '—';
    document.querySelector('[data-current-expiration]').textContent = formatDate(currentReservation?.ends_at);
  };

  document.addEventListener('click', async (event) => {
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
    const button = event.target.closest('[data-locker-id]');
    if (!button) return;
    if (!window.confirm(`Submit a reservation request for ${button.dataset.lockerName}?`)) return;
    button.disabled = true;
    const data = new FormData();
    data.append('locker_id', button.dataset.lockerId);
    try {
      const response = await fetch('../../php/student_action.php', { method: 'POST', body: data });
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.message || 'Reservation request failed.');
      showMessage(result.message);
      await loadReservations();
    } catch (error) {
      button.disabled = false;
      showMessage(error.message);
    }
  });

  loadReservations().catch((error) => showMessage(error.message));
});