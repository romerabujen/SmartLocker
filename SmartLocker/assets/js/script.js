// Shared UI interactions and authentication form handlers.
document.addEventListener('DOMContentLoaded', () => {
  const eyeIcon = `
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/>
      <circle cx="12" cy="12" r="3"/>
    </svg>
  `;

  const eyeSlashIcon = `
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/>
      <circle cx="12" cy="12" r="3"/>
      <path d="M4 4l16 16"/>
    </svg>
  `;

  const attachPasswordToggles = () => {
    document.querySelectorAll('[data-toggle-password]').forEach((toggleButton) => {
      const inputSelector = toggleButton.getAttribute('data-toggle-password');
      const passwordInput = document.querySelector(inputSelector);

      if (!passwordInput) return;

      const updateToggleState = () => {
        const isPasswordHidden = passwordInput.type === 'password';
        toggleButton.innerHTML = isPasswordHidden ? eyeIcon : eyeSlashIcon;
        toggleButton.setAttribute('aria-label', isPasswordHidden ? 'Show password' : 'Hide password');
        toggleButton.setAttribute('title', isPasswordHidden ? 'Show password' : 'Hide password');
      };

      toggleButton.addEventListener('click', () => {
        const shouldShowPassword = passwordInput.type === 'password';
        passwordInput.type = shouldShowPassword ? 'text' : 'password';
        updateToggleState();
        passwordInput.focus();
      });

      updateToggleState();
    });
  };

  attachPasswordToggles();

  const loadDashboardUser = () => {
    if (!document.querySelector('[data-user-full-name]')) return;

    fetch('../../php/current_user.php')
      .then(async (response) => {
        const result = await response.json();
        if (!response.ok || result.success !== true) {
          throw new Error(result.message || 'Unable to load your account.');
        }

        const user = result.user;
        const fullName = [user.first_name, user.middle_initial, user.last_name]
          .filter(Boolean)
          .join(' ');
        const initials = [user.first_name, user.middle_initial, user.last_name]
          .filter(Boolean)
          .map((part) => part.charAt(0).toUpperCase())
          .join('')
          .slice(0, 3);

        document.querySelectorAll('[data-user-full-name]').forEach((element) => {
          element.textContent = fullName;
        });
        document.querySelectorAll('[data-user-first-name]').forEach((element) => {
          element.textContent = user.first_name;
        });
        document.querySelectorAll('[data-user-student-id]').forEach((element) => {
          element.textContent = user.student_id;
        });
        document.querySelectorAll('[data-user-avatar]').forEach((element) => {
          element.textContent = initials;
        });
      })
      .catch(() => {
        window.location.href = '../login/login.html';
      });
  };

  loadDashboardUser();

  const loginForm = document.getElementById('loginForm');
  const setGoogleLoginDisabled = (disabled) => {
    const googleButton = document.getElementById('googleLoginBtn');
      const googleSection = googleButton?.closest('.google-auth-section');
    if (!googleButton) return;
    googleButton.classList.toggle('is-disabled', disabled);
    googleButton.setAttribute('aria-disabled', disabled ? 'true' : 'false');
      googleSection?.classList.toggle('is-disabled', disabled);
  };

  if (loginForm) {
    const loginFeedback = document.getElementById('loginFeedback');
    const usernameInput = document.getElementById('username');
    const loginButton = document.getElementById('loginButton');

    const setLoginButtonState = (disabled) => {
      if (!loginButton) return;
      loginButton.disabled = disabled;
      loginButton.setAttribute('aria-disabled', disabled ? 'true' : 'false');
      loginButton.classList.toggle('is-disabled', disabled);
    };

    const checkLoginLockout = async () => {
      const identifier = usernameInput?.value.trim();
      if (!identifier) {
        setGoogleLoginDisabled(false);
        setLoginButtonState(false);
        return;
      }
      const data = new FormData();
      data.append('identifier', identifier);
      const response = await fetch('../../php/login_status.php', { method: 'POST', body: data });
      const result = await response.json();
      if (result.requires_verification) {
        loginForm.hidden = false;
        document.getElementById('loginLockout').hidden = true;
        showLoginFeedback(result.message || 'Please verify your email before logging in.');
        setGoogleLoginDisabled(false);
        setLoginButtonState(true);
        return;
      }
      if (result.locked) {
        loginForm.hidden = true;
        document.getElementById('loginLockout').hidden = false;
        document.getElementById('loginLockoutMessage').textContent = 'Your login attempts are exhausted. Google login is disabled for this account. Send a password reset link to continue.';
        setGoogleLoginDisabled(true);
        setLoginButtonState(true);
        return;
      }
      document.getElementById('loginLockout').hidden = true;
      loginForm.hidden = false;
      setGoogleLoginDisabled(false);
      setLoginButtonState(false);
    };

    usernameInput?.addEventListener('change', checkLoginLockout);

    const showLoginFeedback = (message) => {
      if (!loginFeedback) return;
      loginFeedback.textContent = message;
      loginFeedback.hidden = false;
    };

    loginForm.addEventListener('submit', (event) => {
      event.preventDefault();

      const username = document.getElementById('username')?.value.trim();
      const password = document.getElementById('password')?.value.trim();

      if (!username || !password) {
        alert('Please enter your email/student ID and password.');
        return;
      }

      fetch(loginForm.action, { method: 'POST', body: new FormData(loginForm) })
        .then(async (response) => {
          const responseText = await response.text();
          let result;
          try {
            result = JSON.parse(responseText);
          } catch (error) {
            throw new Error('The login server returned an invalid response. Check that Apache and PHP are running, then try again.');
          }
          if (response.status === 403) {
            loginForm.hidden = false;
            document.getElementById('loginLockout').hidden = true;
            showLoginFeedback(result.message || 'Please verify your email or password change before logging in.');
            setGoogleLoginDisabled(false);
            setLoginButtonState(true);
            return;
          }
          if (result.locked || result.attempts_remaining === 0) {
            loginForm.hidden = true;
            document.getElementById('loginLockout').hidden = false;
            document.getElementById('loginLockoutMessage').textContent = 'You entered the wrong password five times. Your login attempts are exhausted, and Google login is disabled. Send a password reset link to your registered email.';
            setGoogleLoginDisabled(true);
            return;
          }
          if (response.status === 404) {
            showLoginFeedback(result.message || 'That email or student ID does not exist. Please sign up to create an account.');
            return;
          }
          if (response.status === 401 && Number.isInteger(result.attempts_remaining)) {
            showLoginFeedback(`Incorrect password. You have ${result.attempts_remaining} attempt${result.attempts_remaining === 1 ? '' : 's'} left.`);
            return;
          }
          if (!response.ok || result.success !== true) {
            showLoginFeedback(result.message || 'Login failed.');
            return;
          }
          const isAdmin = result.user?.account_type === 'admin';
          window.location.href = isAdmin ? '../admin/admin.html' : '../dashboard/dashboard.html';
        })
        .catch((error) => alert(error.message));
    });

    document.getElementById('resetPasswordButton')?.addEventListener('click', async () => {
      const identifier = document.getElementById('username')?.value.trim();
      if (!identifier) {
        alert('Enter your email or student ID before requesting a reset link.');
        return;
      }
      const formData = new FormData();
      formData.append('identifier', identifier);
      const response = await fetch('../../php/password_reset_request.php', { method: 'POST', body: formData });
      const result = await response.json();
      alert(result.message);
    });

  }

  // Handle Google login button
  const googleLoginBtn = document.getElementById('googleLoginBtn');
  if (googleLoginBtn) {
    const googleClientId = document.querySelector('meta[name="google-client-id"]')?.content || '';
    const handleGoogleLogin = (response) => {
      const formData = new FormData();
      formData.append('credential', response.credential);

      fetch('../../php/google_login.php', { method: 'POST', body: formData })
        .then(async (serverResponse) => {
          const responseText = await serverResponse.text();
          let result;
          try {
            result = JSON.parse(responseText);
          } catch (error) {
            throw new Error('The Google login server returned an invalid response. Please refresh the page and try again.');
          }
          if (serverResponse.status === 429) {
            setGoogleLoginDisabled(true);
          }
          if (serverResponse.status === 404) {
            alert('This Google account is not yet registered. Please sign up first.');
            return;
          }
          if (!serverResponse.ok || result.success !== true) {
            throw new Error(result.message || 'Google login failed.');
          }
          window.location.href = result.user?.account_type === 'admin'
            ? '../admin/admin.html'
            : '../dashboard/dashboard.html';
        })
        .catch((error) => alert(error.message));
    };

    const renderGoogleLogin = () => {
      if (!window.google?.accounts?.id) return;
      if (googleClientId.startsWith('YOUR_')) {
        googleLoginBtn.textContent = 'Google login is not configured';
        return;
      }

      window.google.accounts.id.initialize({
        client_id: googleClientId,
        callback: handleGoogleLogin
      });
      window.google.accounts.id.renderButton(googleLoginBtn, {
        type: 'standard',
        theme: 'outline',
        size: 'large',
        text: 'signin_with',
        shape: 'rectangular',
        width: 360
      });
    };

    const googleScript = document.createElement('script');
    googleScript.src = 'https://accounts.google.com/gsi/client';
    googleScript.async = true;
    googleScript.defer = true;
    googleScript.onload = renderGoogleLogin;
    document.head.appendChild(googleScript);
  }

  // Handle sign-up form submission and password validation
  let googleCredential = '';
  const signupForm = document.getElementById('signupForm');
  if (signupForm) {
    const passwordInput = document.getElementById('password');
    const confirmPasswordInput = document.getElementById('confirmPassword');
    const signupButton = document.getElementById('signupButton');
    const passwordRequirements = document.getElementById('passwordRequirements');
    const emailInput = document.getElementById('email');
    const firstNameInput = document.getElementById('firstName');
    const middleInitialInput = document.getElementById('middleInitial');
    const lastNameInput = document.getElementById('lastName');
    const studentIdInput = document.getElementById('studentId');
    const umakEmailPattern = /^[^\s@]+@umak\.edu\.ph$/i;

    // Email-provider callbacks can return verified identity fields in the URL.
    const providerData = new URLSearchParams(window.location.search);
    const providerEmail = providerData.get('email');
    const providerFirstName = providerData.get('first_name');
    const providerMiddleInitial = providerData.get('middle_initial');
    const providerLastName = providerData.get('last_name');

    if (providerEmail && providerFirstName && providerLastName) {
      emailInput.value = providerEmail.toLowerCase();
      firstNameInput.value = providerFirstName;
      middleInitialInput.value = providerMiddleInitial || '';
      lastNameInput.value = providerLastName;
      [emailInput, firstNameInput, lastNameInput].forEach((input) => {
        input.readOnly = true;
      });
    }

    const togglePasswordRequirements = (shouldShow) => {
      if (passwordRequirements) {
        passwordRequirements.classList.toggle('is-visible', shouldShow);
      }
    };

    // Function to validate password
    const validatePassword = (password) => {
      const requirements = {
        length: password.length >= 8,
        uppercase: /[A-Z]/.test(password),
        special: /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(password),
        number: /\d/.test(password)
      };
      return requirements;
    };

    // Function to update requirement visual feedback
    const updateRequirementsFeedback = (password) => {
      const requirements = validatePassword(password);

      document.getElementById('requirement-length')?.classList.toggle('met', requirements.length);
      document.getElementById('requirement-uppercase')?.classList.toggle('met', requirements.uppercase);
      document.getElementById('requirement-special')?.classList.toggle('met', requirements.special);
      document.getElementById('requirement-number')?.classList.toggle('met', requirements.number);

      // Enable/disable signup button based on all requirements met and passwords matching
      const allRequirementsMet = Object.values(requirements).every(req => req);
      const passwordsMatch = password === (confirmPasswordInput?.value || '');
      signupButton.disabled = !(allRequirementsMet && passwordsMatch && password.length > 0);
    };

    // Show password requirements only when the field is focused or used
    if (passwordInput) {
      passwordInput.addEventListener('focus', () => togglePasswordRequirements(true));
      passwordInput.addEventListener('blur', () => {
        if (!passwordInput.value.trim()) {
          togglePasswordRequirements(false);
        }
      });
      passwordInput.addEventListener('input', () => {
        togglePasswordRequirements(true);
        updateRequirementsFeedback(passwordInput.value);
      });
    }

    // Check passwords match on confirm password input
    if (confirmPasswordInput) {
      confirmPasswordInput.addEventListener('input', () => {
        updateRequirementsFeedback(passwordInput?.value || '');
      });
    }

    // Handle form submission
    signupForm.addEventListener('submit', (event) => {
      if (googleCredential) return;
      event.preventDefault();

      const email = emailInput?.value.trim().toLowerCase();
      const firstName = firstNameInput?.value.trim();
      const lastName = lastNameInput?.value.trim();
      const studentId = studentIdInput?.value.trim();
      const password = passwordInput?.value || '';
      const confirmPassword = confirmPasswordInput?.value || '';

      // Validate form fields
      if (!email || !firstName || !lastName || !studentId || !password || !confirmPassword) {
        alert('Please fill in all fields.');
        return;
      }

      // Validate email format
      const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      if (!emailRegex.test(email) || !umakEmailPattern.test(email)) {
        alert('Only a valid @umak.edu.ph email address is allowed.');
        return;
      }

      // Validate password requirements
      const requirements = validatePassword(password);
      if (!Object.values(requirements).every(req => req)) {
        alert('Password does not meet all requirements.');
        return;
      }

      // Validate passwords match
      if (password !== confirmPassword) {
        alert('Passwords do not match.');
        return;
      }

      fetch(signupForm.action, { method: 'POST', body: new FormData(signupForm) })
        .then(async (response) => {
          const result = await response.json();
          if (!response.ok) throw new Error(result.message || 'Account creation failed.');
          alert(result.message);
          window.location.href = '../login/login.html';
        })
        .catch((error) => alert(error.message));
    });

    // Initialize button state
    signupButton.disabled = true;
  }

  const googleSignupBtn = document.getElementById('googleSignupBtn');
  if (googleSignupBtn) {
    const signupForm = document.getElementById('signupForm');
    const googleClientId = document.querySelector('meta[name="google-client-id"]')?.content || '';
    const handleGoogleSignup = (response) => {
      googleCredential = response.credential;
      const payload = JSON.parse(atob(googleCredential.split('.')[1].replace(/-/g, '+').replace(/_/g, '/')));
      const email = (payload.email || '').toLowerCase();

      if (!email.endsWith('@umak.edu.ph')) {
        googleCredential = '';
        alert('Only verified @umak.edu.ph Google accounts are allowed.');
        return;
      }

      const identityFields = {
        email,
        first_name: payload.given_name || '',
        middle_initial: '',
        last_name: payload.family_name || ''
      };

      Object.entries(identityFields).forEach(([field, value]) => {
        const input = document.querySelector(`[name="${field}"]`);
        if (input) {
          input.value = value;
          input.readOnly = field !== 'middle_initial';
        }
      });
      googleSignupBtn.textContent = 'Google account selected';
      document.getElementById('studentId')?.focus();
    };

    const renderGoogleSignup = () => {
      if (!window.google?.accounts?.id) return;
      if (googleClientId.startsWith('YOUR_')) {
        googleSignupBtn.textContent = 'Google signup is not configured';
        return;
      }

      window.google.accounts.id.initialize({
        client_id: googleClientId,
        callback: handleGoogleSignup
      });
      window.google.accounts.id.renderButton(googleSignupBtn, {
        type: 'standard',
        theme: 'outline',
        size: 'large',
        text: 'signup_with',
        shape: 'rectangular',
        width: 360
      });
    };

    signupForm.addEventListener('submit', (event) => {
      if (!googleCredential) return;
      event.preventDefault();
      const formData = new FormData(signupForm);
      formData.append('credential', googleCredential);
      fetch('../../php/google_signup.php', { method: 'POST', body: formData })
        .then(async (response) => {
          const result = await response.json();
          if (!response.ok) throw new Error(result.message || 'Google signup failed.');
          alert(result.message);
          window.location.href = '../login/login.html';
        })
        .catch((error) => alert(error.message));
    });

    const googleScript = document.createElement('script');
    googleScript.src = 'https://accounts.google.com/gsi/client';
    googleScript.async = true;
    googleScript.defer = true;
    googleScript.onload = renderGoogleSignup;
    document.head.appendChild(googleScript);
  }

  const modalElement = document.getElementById('lockerActionModal');
  const modalMessage = document.getElementById('lockerActionMessage');

  // Trigger simulated locker actions from dashboard quick action buttons.
  document.querySelectorAll('[data-action]').forEach((button) => {
    button.addEventListener('click', () => {
      const action = button.dataset.action;

      if (action === 'logout') {
        window.location.href = '../login/login.html';
        return;
      }

      if (action === 'unlock') {
        if (modalMessage && modalElement) {
          modalMessage.textContent = 'Locker L-001 unlocked successfully.';
          const bsModal = new bootstrap.Modal(modalElement);
          bsModal.show();
        }
        return;
      }

      if (action === 'reserve') {
        document.getElementById('locker')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        return;
      }

      if (action === 'problem') {
        if (modalMessage && modalElement) {
          modalMessage.textContent = 'Your locker issue has been reported. A facilities officer will review it soon.';
          const bsModal = new bootstrap.Modal(modalElement);
          bsModal.show();
        }
        return;
      }

      if (action === 'history') {
        const historySection = document.getElementById('history');
        if (historySection) {
          historySection.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      }
    });
  });
});
