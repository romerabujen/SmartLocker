document.addEventListener('DOMContentLoaded', async () => {
  const reservationId = new URLSearchParams(window.location.search).get('reservation_id');
  const loading = document.querySelector('[data-payment-loading]');
  const content = document.querySelector('[data-payment-content]');
  const submitButton = document.querySelector('[data-submit-payment]');
  const errorElement = document.querySelector('[data-payment-error]');
  const paymentForm = document.querySelector('[data-payment-form]');
  const showError = (message) => {
    errorElement.textContent = message;
    errorElement.hidden = false;
  };

  if (!reservationId) {
    loading.textContent = 'Payment details could not be found. Return to the dashboard and try again.';
    return;
  }

  try {
    const response = await fetch(`../../php/payment_data.php?reservation_id=${encodeURIComponent(reservationId)}`);
    const result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message || 'Unable to load payment details.');

    const reservation = result.reservation;
    if (reservation.reservation_status === 'pending' && reservation.payment_status === 'paid') {
      loading.hidden = true;
      document.querySelector('[data-success-message]').textContent = 'Your payment has been submitted and is awaiting administrator approval. You will receive an email notification after a decision.';
      document.querySelector('[data-payment-success]').hidden = false;
      return;
    }
    if (reservation.reservation_status !== 'pending' || reservation.payment_status !== 'pending') {
      throw new Error('This payment session is no longer available. Return to the dashboard to check your locker status.');
    }

    document.querySelector('[data-locker]').textContent = [reservation.building, reservation.floor, reservation.locker_number].filter(Boolean).join(' · ');
    document.querySelector('[data-term]').textContent = ({ '1_week': '1 Week', '1_month': '1 Month', '3_months': '3 Months' })[reservation.duration] || reservation.duration;
    document.querySelector('[data-amount]').textContent = `₱${Number(reservation.amount_pesos).toFixed(2)}`;
    paymentForm.elements.payer_name.value = [reservation.first_name, reservation.last_name].filter(Boolean).join(' ');
    paymentForm.elements.amount_pesos.value = reservation.amount_pesos;
    loading.hidden = true;
    content.hidden = false;
    submitButton.disabled = false;
  } catch (error) {
    loading.textContent = error.message;
  }

  paymentForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!paymentForm.reportValidity()) return;
    submitButton.disabled = true;
    errorElement.hidden = true;
    const formData = new FormData(paymentForm);
    formData.append('reservation_id', reservationId);
    try {
      const response = await fetch('../../php/submit_simulated_payment.php', { method: 'POST', body: formData });
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.message || 'Unable to submit payment.');
      content.hidden = true;
      document.querySelector('[data-success-message]').textContent = result.message;
      document.querySelector('[data-payment-success]').hidden = false;
    } catch (error) {
      showError(error.message);
      submitButton.disabled = false;
    }
  });
});