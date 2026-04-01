/**
 * Sistema de Requisição de Chaves
 * @author  Pedro Santos
 * @year    2026
 * @project Prova de Aptidão Profissional (PAP)
 * @license Todososdireitosreservados
 */

const modeBadge = document.getElementById("modeBadge");
const rangeSelect = document.getElementById("range");
const hint = document.getElementById("hint");
const btnAluno = document.getElementById("btnAluno");
const btnColab = document.getElementById("btnColab");
const btnAdmin = document.getElementById("btnAdmin");
const btnAdminMenu = document.getElementById("btnAdminMenu");
const btnExportar = document.getElementById("btnExportar");
const btnEmergenciaNav = document.getElementById("btnEmergencia");
const adminToolbar = document.getElementById("adminToolbar");
const adminSecurityForm = document.getElementById("adminSecurityForm");
const adminEmailInput = document.getElementById("adminEmail");
const adminKeyAddForm = document.getElementById("adminKeyAddForm");
const adminUserAddForm = document.getElementById("adminUserAddForm");
const adminKeysTableBody = document.querySelector("#adminKeysTable tbody");
const adminUsersTableBody = document.querySelector("#adminUsersTable tbody");
let adminAuthenticated = false;

async function logoutAdminIfNeeded() {
  if (!adminAuthenticated) return;

  try {
    await apiLogoutAdmin();
  } catch (_) {
    // Mesmo que haja erro, prosseguir com logout localmente
  }

  const offcanvasElement = document.getElementById("adminOffcanvas");
  if (offcanvasElement && window.bootstrap?.Offcanvas) {
    const offcanvas = window.bootstrap.Offcanvas.getInstance(offcanvasElement);
    if (offcanvas) offcanvas.hide();
  }

  setAdminState(false);
}

async function initApp() {
  if (btnExportar) {
    btnExportar.classList.add("d-none");
  }

  const status = await apiCheckAdmin().catch(() => ({ isAdmin: false }));
  setAdminState(status.isAdmin);

  if (status.isAdmin) {
    if (adminEmailInput && typeof status.email === 'string') {
      adminEmailInput.value = status.email;
    }
    await renderAdminKeys();
    await renderAdminUsers();
    await loadProfessorPin();
    await renderBackups();
  }

  rangeSelect.value = "24h";
  await renderTable();
}

function setActiveButtons(tipo) {
  if (!btnAluno || !btnColab) return;

  btnAluno.classList.remove("btn-success", "btn-outline-success");
  btnColab.classList.remove("btn-success", "btn-outline-success");

  if (tipo === "PROFESSOR") {
    btnColab.classList.add("btn-success");
    btnAluno.classList.add("btn-outline-success");
    return;
  }

  btnAluno.classList.add("btn-success");
  btnColab.classList.add("btn-outline-success");
}

function formatStatusBadge(tipo) {
  if (tipo === "ALUNO") return "text-bg-success";
  return "text-bg-dark";
}

async function renderTable() {
  const tipo = getUserTipo();
  modeBadge.textContent = tipo;
  setActiveButtons(tipo);
  modeBadge.classList.remove("text-bg-success", "text-bg-dark");
  modeBadge.classList.add(formatStatusBadge(tipo));

  const range = rangeSelect.value || "24h";
  const result = await apiListRequisicoes(range);
  const reqs = result.requisicoes || [];

  const tbody = document.querySelector("#tbl tbody");
  tbody.innerHTML = "";

  reqs.forEach((r) => {
    const tr = document.createElement("tr");
    const tipo = getUserTipo();
    const isProfessorKey = (r.user_type || r.user_tipo) === 'PROFESSOR';
    const isAlunoKey = (r.user_type || r.user_tipo) === 'ALUNO';
    const rawIp = String(r.ip_address || '').trim();
    const isLocalIp = rawIp === '::1' || rawIp === '127.0.0.1' || rawIp === '::ffff:127.0.0.1';
    const displayIp = isLocalIp ? '...' : rawIp;
    const ipLabel = adminAuthenticated && rawIp ? `<span class="ip-label">IP: ${escapeHtml(displayIp)}</span>` : '';

    // Devolver: admin pode tudo, professor pode devolver chaves de professor, aluno só chaves de aluno
    let actionCell = '-';
    const chaveLabel = r.chave_codigo ? `${r.chave_codigo} • ${r.chave_nome}` : '';
    if (r.estado === 'ATIVA') {
      if (adminAuthenticated) {
        actionCell = `<button class="btn btn-sm btn-success" data-action="devolver" data-id="${r.id}" data-keytipo="${escapeHtml(r.user_type || r.user_tipo)}" data-chave="${escapeHtml(chaveLabel)}">Devolver</button>`;
      } else if (isProfessorKey && tipo === 'PROFESSOR') {
        actionCell = `<button class="btn btn-sm btn-success" data-action="devolver" data-id="${r.id}" data-keytipo="PROFESSOR" data-chave="${escapeHtml(chaveLabel)}">Devolver</button>`;
      } else if (isAlunoKey) {
        actionCell = `<button class="btn btn-sm btn-success" data-action="devolver" data-id="${r.id}" data-keytipo="ALUNO" data-chave="${escapeHtml(chaveLabel)}">Devolver</button>`;
      }
    }

    tr.innerHTML = `
      <td>${escapeHtml(r.user_nome)}${ipLabel}</td>
      <td>${escapeHtml(r.user_type || r.user_tipo)}</td>
      <td>${escapeHtml(r.chave_nome || "—")}</td>
      <td>${escapeHtml(formatDatePT(r.inicio))}</td>
      <td>${escapeHtml(r.estado === "DEVOLVIDA" && r.fim ? (`Devolvida (${formatDatePT(r.fim)})`) : r.estado)}</td>
      <td>${actionCell}</td>
    `;
    tbody.appendChild(tr);
  });

  hint.textContent = `A mostrar ${reqs.length} registos (${range}).`;
}

function showConfirmModal(title, message) {
  return new Promise((resolve) => {
    document.getElementById('confirmModalTitle').textContent = title;
    document.getElementById('confirmModalBody').textContent = message;
    const modal = new bootstrap.Modal(document.getElementById('confirmModal'));
    const okBtn = document.getElementById('confirmModalOk');
    const handler = () => { resolve(true); modal.hide(); okBtn.removeEventListener('click', handler); };
    okBtn.addEventListener('click', handler);
    document.getElementById('confirmModal').addEventListener('hidden.bs.modal', function onHide() {
      resolve(false);
      okBtn.removeEventListener('click', handler);
      document.getElementById('confirmModal').removeEventListener('hidden.bs.modal', onHide);
    });
    modal.show();
  });
}

async function handleDevolver(id, keyTipo, chaveInfo) {
  if (adminAuthenticated) {
    if (!(await showConfirmModal('Admin', 'Devolver esta chave?'))) return;
    await apiAdminDevolverRequisicao(id);
  } else if (keyTipo === 'PROFESSOR') {
    if (!(await showConfirmModal('Professor', 'Devolver esta chave?'))) return;
    await apiDevolverRequisicaoProfessor(id);
  } else {
    const params = new URLSearchParams({ id });
    if (chaveInfo) params.set('chave', chaveInfo);
    window.location.href = 'devolver.html?' + params.toString();
    return;
  }
  await renderTable();
}

document.querySelector("#tbl tbody").addEventListener("click", (e) => {
  const btn = e.target.closest("button[data-action='devolver']");
  if (!btn) return;

  const id = parseInt(btn.getAttribute("data-id"), 10);
  if (!Number.isFinite(id)) return;
  const keyTipo = btn.getAttribute("data-keytipo") || 'ALUNO';
  const chaveInfo = btn.getAttribute("data-chave") || '';

  handleDevolver(id, keyTipo, chaveInfo);
});

document.getElementById("btnAluno").addEventListener("click", async () => {
  await logoutAdminIfNeeded();
  setUserTipo("ALUNO");
  await renderTable();
});

document.getElementById("btnColab").addEventListener("click", async () => {
  window.location.href = "professor-pin.html";
});

document.getElementById("btnAdmin").addEventListener("click", async () => {
  if (adminAuthenticated) {
    // Já está logado, abre painel
    return;
  }
  // Redireciona para a página de login
  window.location.href = "admin-login.html";
});

function setAdminState(enabled) {
  adminAuthenticated = enabled;
  rangeSelect.disabled = !enabled;

  if (adminToolbar) adminToolbar.classList.toggle("d-none", !enabled);
  if (btnExportar) btnExportar.classList.toggle("d-none", !enabled);

  btnAdmin.classList.toggle("btn-success", enabled);
  btnAdmin.classList.toggle("btn-outline-success", !enabled);

}

async function renderAdminKeys() {
  if (!adminKeysTableBody) return;

  const result = await apiListChaves();
  const chaves = result.chaves || [];
  adminKeysTableBody.innerHTML = "";

  chaves.forEach((chave) => {
    const tr = document.createElement("tr");
    tr.innerHTML = `
      <td>${escapeHtml(chave.codigo)}</td>
      <td>${escapeHtml(chave.nome)}</td>
      <td>${escapeHtml(chave.restricao)}</td>
      <td><button class="btn btn-sm btn-danger" data-action="delete-key" data-id="${chave.id}">Remover</button></td>
    `;
    adminKeysTableBody.appendChild(tr);
  });
}

let allUsers = [];

async function renderAdminUsers() {
  if (!adminUsersTableBody) return;

  const result = await apiListUsers();
  allUsers = result.users || [];
  filterAdminUsers();
}

function filterAdminUsers() {
  if (!adminUsersTableBody) return;
  const searchInput = document.getElementById('adminUserSearch');
  const query = (searchInput ? searchInput.value : '').toLowerCase().trim();

  adminUsersTableBody.innerHTML = "";

  allUsers
    .filter(u => !query || u.nome.toLowerCase().includes(query))
    .forEach((user) => {
      const tr = document.createElement("tr");
      tr.innerHTML = `
        <td>${escapeHtml(user.nome)}</td>
        <td>${escapeHtml(user.tipo)}</td>
        <td>${escapeHtml(user.turma || '-')}</td>
        <td>${escapeHtml(user.telefone || '-')}</td>
        <td>
          <button class="btn btn-sm btn-outline-primary me-1" data-action="edit-user" data-id="${user.id}" data-nome="${escapeHtml(user.nome)}" data-tipo="${escapeHtml(user.tipo)}" data-turma="${escapeHtml(user.turma || '')}" title="Editar utilizador">&#9998;</button>
          <button class="btn btn-sm btn-outline-danger" data-action="delete-user" data-id="${user.id}" title="Remover utilizador">&times;</button>
        </td>
      `;
      adminUsersTableBody.appendChild(tr);
    });
}

async function addAdminKey(data) {
  if (!adminAuthenticated) {
    alert("Apenas o administrador pode adicionar chaves.");
    return;
  }

  await apiAddChave(data.codigo, data.nome, data.restricao);
  await renderAdminKeys();
  await renderTable();
}

async function addAdminUser(data) {
  if (!adminAuthenticated) {
    alert("Apenas o administrador pode adicionar Utilizadores.");
    return;
  }

  await apiAddUser(data.nome, data.tipo, data.turma, data.telefone);
  await renderAdminUsers();
}

async function deleteAdminUser(id) {
  if (!confirm('Remover este utilizador?')) return;
  try {
    await apiDeleteUser(id);
    await renderAdminUsers();
  } catch (error) {
    alert(error.message);
  }
}

async function deleteAdminKey(id) {
  try {
    await apiDeleteChave(id);
    await renderAdminKeys();
    await renderTable();
  } catch (error) {
    alert(error.message);
  }
}

if (adminKeyAddForm) {
  adminKeyAddForm.addEventListener("submit", async (event) => {
    event.preventDefault();

    const codigo = document.getElementById("adminKeyCode").value.trim();
    const nome = document.getElementById("adminKeyName").value.trim();
    const restricao = document.getElementById("adminKeyRestriction").value;

    if (!codigo || !nome || !restricao) {
      alert("Preencha os dados da chave antes de gravar.");
      return;
    }

    await addAdminKey({ codigo, nome, restricao });
    adminKeyAddForm.reset();
    const collapse = new bootstrap.Collapse(document.getElementById("addKeyForm"));
    collapse.hide();
  });
}

if (adminUsersTableBody && adminUserAddForm) {
  adminUserAddForm.addEventListener("submit", async (event) => {
    event.preventDefault();

    const nome = document.getElementById("adminUserName").value.trim();
    const tipo = document.getElementById("adminUserType").value;
    const turma = document.getElementById("adminUserTurma").value.trim();
    const telefoneInput = document.getElementById("adminUserTelefone");
    const telefone = telefoneInput ? telefoneInput.value.trim() : "";

    if (!nome || !tipo) {
      alert("Preencha o nome e escolha o tipo de Utilizador.");
      return;
    }

    if (tipo === "ALUNO") {
      if (!telefone) {
        alert("Introduza o número de telefone do aluno.");
        return;
      }
      if (!/^\d{9,15}$/.test(telefone.replace(/\s+/g, ""))) {
        alert("Número de telefone inválido (9-15 dígitos).");
        return;
      }
    }

    await addAdminUser({ nome, tipo, turma, telefone });
    adminUserAddForm.reset();
    const collapse = new bootstrap.Collapse(document.getElementById("addUserForm"));
    collapse.hide();
  });
}

const adminUserType = document.getElementById("adminUserType");
const adminUserPhoneWrap = document.getElementById("adminUserPhoneWrap");
const adminUserTelefone = document.getElementById("adminUserTelefone");

function syncAdminUserPhoneVisibility() {
  if (!adminUserType || !adminUserPhoneWrap || !adminUserTelefone) return;
  const isAluno = adminUserType.value === "ALUNO";
  adminUserPhoneWrap.classList.toggle("d-none", !isAluno);
  adminUserTelefone.required = isAluno;
}

if (adminUserType) {
  adminUserType.addEventListener("change", syncAdminUserPhoneVisibility);
  syncAdminUserPhoneVisibility();
}

if (adminKeysTableBody) {
  adminKeysTableBody.addEventListener("click", (event) => {
    const button = event.target.closest("button[data-action='delete-key']");
    if (!button) return;

    const id = parseInt(button.getAttribute("data-id"), 10);
    if (!Number.isFinite(id)) return;

    deleteAdminKey(id);
  });
}

if (adminUsersTableBody) {
  adminUsersTableBody.addEventListener("click", (event) => {
    const editBtn = event.target.closest("button[data-action='edit-user']");
    if (editBtn) {
      const id = parseInt(editBtn.getAttribute("data-id"), 10);
      if (!Number.isFinite(id)) return;
      document.getElementById('editUserId').value = id;
      document.getElementById('editUserNome').value = editBtn.getAttribute('data-nome');
      document.getElementById('editUserTipo').value = editBtn.getAttribute('data-tipo');
      document.getElementById('editUserTurma').value = editBtn.getAttribute('data-turma');
      new bootstrap.Modal(document.getElementById('editUserModal')).show();
      return;
    }

    const deleteBtn = event.target.closest("button[data-action='delete-user']");
    if (!deleteBtn) return;

    const id = parseInt(deleteBtn.getAttribute("data-id"), 10);
    if (!Number.isFinite(id)) return;

    deleteAdminUser(id);
  });
}

const editUserSaveBtn = document.getElementById('editUserSave');
if (editUserSaveBtn) {
  editUserSaveBtn.addEventListener('click', async () => {
    const id = parseInt(document.getElementById('editUserId').value, 10);
    const nome = document.getElementById('editUserNome').value.trim();
    const turma = document.getElementById('editUserTurma').value.trim();
    if (!nome) { alert('Nome é obrigatório.'); return; }
    try {
      await apiEditUser(id, nome, turma);
      bootstrap.Modal.getInstance(document.getElementById('editUserModal')).hide();
      await renderAdminUsers();
    } catch (error) {
      alert(error.message);
    }
  });
}

const adminUserSearch = document.getElementById('adminUserSearch');
if (adminUserSearch) {
  adminUserSearch.addEventListener('input', () => filterAdminUsers());
}

async function exportarRelatorio() {
  const range = rangeSelect.value || "24h";
  const result = await apiListRequisicoes(range);
  const requisicoes = result.requisicoes || [];

  const linhas = requisicoes.map((r) => {
    const fimTexto = r.fim ? formatDatePT(r.fim) : "-";
    return [
      `Nome: ${escapeHtml(r.user_nome)}`,
      `Tipo: ${escapeHtml(r.user_tipo)}`,
      r.user_tipo === "PROFESSOR" ? `Disciplina: ${escapeHtml(r.user_turma || "-")}` : `Turma: ${escapeHtml(r.user_turma || "-")}`,
      `Chave: ${escapeHtml(r.chave_nome || "-")}`,
      `Início: ${formatDatePT(r.inicio)}`,
      `Fim: ${fimTexto}`,
      `Estado: ${escapeHtml(r.estado)}`,
      r.user_tipo === 'ALUNO' && r.telefone ? `Telefone: ${escapeHtml(r.telefone)}` : null
    ].filter(Boolean).join(" | ");
    

  });

  const conteudo = linhas.join("\n");
  const blob = new Blob([conteudo], { type: "text/plain" });
  const link = document.createElement("a");

  link.href = URL.createObjectURL(blob);
  link.download = `relatorio_requisicoes_${range}.txt`;
  link.click();
}

if (btnExportar) {
  btnExportar.addEventListener("click", exportarRelatorio);
}

rangeSelect.addEventListener("change", renderTable);

const backupsList = document.getElementById("backupsList");
const backupMsg = document.getElementById("backupMsg");
const backupErr = document.getElementById("backupErr");
const btnCreateBackup = document.getElementById("btnCreateBackup");

const professorPinInput = document.getElementById("professorPinInput");
const btnSavePin = document.getElementById("btnSavePin");
const pinMsg = document.getElementById("pinMsg");

async function loadProfessorPin() {
  if (!professorPinInput) return;
  try {
    const result = await apiGetProfessorPin();
    professorPinInput.value = result.pin || '';
  } catch (e) { /* ignore */ }
}

if (btnSavePin) {
  btnSavePin.addEventListener("click", async () => {
    const pin = (professorPinInput.value || '').trim();
    if (!/^\d{4,8}$/.test(pin)) {
      alert("O PIN deve ter entre 4 e 8 dígitos.");
      return;
    }
    try {
      await apiUpdateProfessorPin(pin);
      if (pinMsg) {
        pinMsg.textContent = "PIN atualizado com sucesso.";
        pinMsg.classList.remove("d-none");
        setTimeout(() => pinMsg.classList.add("d-none"), 4000);
      }
    } catch (e) {
      alert("Erro ao atualizar PIN: " + (e.message || e));
    }
  });
}

function showBackupMsg(el, text) {
  el.textContent = text;
  el.classList.remove("d-none");
  setTimeout(() => el.classList.add("d-none"), 5000);
}

async function renderBackups() {
  if (!backupsList) return;

  try {
    const result = await apiRequest("listBackups");
    const backups = result.backups || [];

    if (backups.length === 0) {
      backupsList.innerHTML = '<p class="small text-muted">Nenhum backup disponível.</p>';
      return;
    }

    const reasonLabels = {
      'manual': 'Manual',
      'auto': 'Automático (1h)',
      'delete-chave': 'Antes de apagar chave',
      'pre-restore': 'Antes de restaurar',
    };

    let html = '<div class="list-group list-group-flush">';
    backups.forEach((b) => {
      const label = reasonLabels[b.reason] || b.reason;
      html += `
        <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-2">
          <div>
            <small class="d-block fw-bold">${escapeHtml(b.created_at)}</small>
            <small class="text-muted">${escapeHtml(label)} — ${escapeHtml(b.size)}</small>
          </div>
          <button class="btn btn-sm btn-outline-warning" data-action="restore-backup" data-filename="${escapeHtml(b.filename)}">
            Restaurar
          </button>
        </div>`;
    });
    html += '</div>';
    backupsList.innerHTML = html;
  } catch (error) {
    backupsList.innerHTML = '<p class="small text-danger">Erro ao carregar backups.</p>';
  }
}

if (btnCreateBackup) {
  btnCreateBackup.addEventListener("click", async () => {
    try {
      const res = await apiRequest("createBackup");
      showBackupMsg(backupMsg, res.message || "Backup criado!");
      await renderBackups();
    } catch (error) {
      showBackupMsg(backupErr, error.message || "Erro ao criar backup.");
    }
  });
}

if (backupsList) {
  backupsList.addEventListener("click", async (e) => {
    const btn = e.target.closest("button[data-action='restore-backup']");
    if (!btn) return;

    const filename = btn.getAttribute("data-filename");
    if (!confirm("Tem a certeza que quer restaurar este backup?\n\nTodos os dados atuais serão substituídos pelo backup selecionado.")) {
      return;
    }

    try {
      const res = await apiRequest("restoreBackup", { filename });
      showBackupMsg(backupMsg, res.message || "Backup restaurado!");
      await renderAdminKeys();
      await renderAdminUsers();
      await renderTable();
      await renderBackups();
    } catch (error) {
      showBackupMsg(backupErr, error.message || "Erro ao restaurar backup.");
    }
  });
}

initApp();
