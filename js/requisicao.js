function getUserTipo() {
  return localStorage.getItem("requisicao_tipo") || "ALUNO";
}

function setUserTipo(tipo) {
  localStorage.setItem("requisicao_tipo", tipo);
}

async function allowedKeysFor(tipo) {
  const result = await apiListChaves();
  const expected = String(tipo || '').trim().toUpperCase();
  return (result.chaves || []).filter((c) => c.restricao === expected);
}

async function allowedUsersFor(tipo) {
  const result = await apiListUsers(tipo);
  return result.users || [];
}

async function isChaveEmUso(chaveId) {
  const result = await apiListRequisicoes('100d');
  const requisicoes = result.requisicoes || [];
  return requisicoes.some((req) => req.estado === "ATIVA" && req.chave_id === chaveId);
}

async function createRequisicao(data) {
  return apiCreateRequisicao(data.user_id, data.chave_id);
}
