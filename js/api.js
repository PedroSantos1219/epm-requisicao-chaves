/**
 * Sistema de Requisição de Chaves
 * @author  Pedro Santos
 * @year    2026
 * @project Prova de Aptidão Profissional (PAP)
 * @license Todos os direitos reservados
 */

async function apiRequest(action, payload = {}) {
  const url = `../api.php?action=${encodeURIComponent(action)}`;
  const response = await fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json;charset=utf-8'
    },
    body: JSON.stringify(payload)
  });

  const responseText = await response.text();
  let data;
  try {
    data = responseText ? JSON.parse(responseText) : null;
  } catch (error) {
    throw new Error(`Resposta inválida do servidor (${response.status}): ${responseText}`);
  }

  if (!response.ok || data?.success === false) {
    throw new Error(data?.error || `Erro na comunicação com o servidor (${response.status}).`);
  }

  return data;
}

async function apiCheckAdmin() {
  return apiRequest('checkAdmin');
}

async function apiLoginAdmin(email, password) {
  return apiRequest('loginAdmin', { email, password });
}

async function apiLogoutAdmin() {
  return apiRequest('logoutAdmin');
}

async function apiListUsers(tipo) {
  return apiRequest('listUsers', { tipo });
}

async function apiAddUser(nome, tipo, turma, telefone) {
  return apiRequest('addUser', { nome, tipo, turma, telefone });
}

async function apiEditUser(id, nome, turma) {
  return apiRequest('editUser', { id, nome, turma });
}

async function apiDeleteUser(id) {
  return apiRequest('deleteUser', { id });
}

async function apiListChaves() {
  return apiRequest('listChaves');
}

async function apiAddChave(codigo, nome, restricao) {
  return apiRequest('addChave', { codigo, nome, restricao });
}

async function apiDeleteChave(id) {
  return apiRequest('deleteChave', { id });
}

async function apiListRequisicoes(range = '24h') {
  return apiRequest('listRequisicoes', { range });
}

async function apiCreateRequisicao(user_id, chave_id) {
  return apiRequest('createRequisicao', { user_id, chave_id });
}

async function apiDevolverRequisicao(id, telefone) {
  return apiRequest('devolverRequisicao', { id, telefone });
}

async function apiDevolverRequisicaoProfessor(id) {
  return apiRequest('devolverRequisicaoProfessor', { id });
}

async function apiAdminDevolverRequisicao(id) {
  return apiRequest('adminDevolverRequisicao', { id });
}

async function apiUpdateAdmin(email, password) {
  return apiRequest('updateAdmin', { email, password });
}

async function apiVerifyProfessorPin(pin) {
  return apiRequest('verifyProfessorPin', { pin });
}

async function apiGetProfessorPin() {
  return apiRequest('getProfessorPin');
}

async function apiUpdateProfessorPin(pin) {
  return apiRequest('updateProfessorPin', { pin });
}

function formatDatePT(iso) {
  return new Date(iso).toLocaleString('pt-PT');
}

function escapeHtml(str) {
  return String(str ?? '').replace(/[&<>"']/g, (s) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;'
  }[s]));
}
