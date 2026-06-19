const DB_KEY = "zemi_accounts";
const SESSION_KEY = "zemi_current_user";

function readAccounts() {
  try {
    return JSON.parse(localStorage.getItem(DB_KEY)) ?? [];
  } catch {
    return [];
  }
}

function writeAccounts(accounts) {
  localStorage.setItem(DB_KEY, JSON.stringify(accounts));
}

async function hashPassword(password) {
  const encoded = new TextEncoder().encode(password);
  const hashBuffer = await crypto.subtle.digest("SHA-256", encoded);
  return Array.from(new Uint8Array(hashBuffer))
    .map((value) => value.toString(16).padStart(2, "0"))
    .join("");
}

function setMessage(element, text, type = "") {
  if (!element) {
    return;
  }

  element.textContent = text;
  element.className = `message ${type}`.trim();
}

function getCurrentUser() {
  try {
    return JSON.parse(sessionStorage.getItem(SESSION_KEY));
  } catch {
    return null;
  }
}

function setCurrentUser(user) {
  sessionStorage.setItem(SESSION_KEY, JSON.stringify(user));
}

function clearCurrentUser() {
  sessionStorage.removeItem(SESSION_KEY);
}

function ensureLoggedIn() {
  const user = getCurrentUser();
  if (!user) {
    window.location.href = "index.html";
    return null;
  }

  return user;
}

async function registerAccount(nickname, password) {
  const accounts = readAccounts();
  const exists = accounts.some((account) => account.nickname === nickname);

  if (exists) {
    throw new Error("そのニックネームはすでに使われています。");
  }

  const passwordHash = await hashPassword(password);
  const account = {
    id: crypto.randomUUID(),
    nickname,
    passwordHash,
    createdAt: new Date().toISOString(),
  };

  accounts.push(account);
  writeAccounts(accounts);
  return account;
}

async function loginAccount(nickname, password) {
  const accounts = readAccounts();
  const account = accounts.find((item) => item.nickname === nickname);

  if (!account) {
    throw new Error("アカウントが見つかりません。");
  }

  const passwordHash = await hashPassword(password);
  if (account.passwordHash !== passwordHash) {
    throw new Error("パスワードが違います。");
  }

  const user = { id: account.id, nickname: account.nickname };
  setCurrentUser(user);
  return user;
}

function logout() {
  clearCurrentUser();
  window.location.href = "index.html";
}