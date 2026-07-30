/* ============================================================
 * 海龟汤馆 · 灵之残响 · 极简前端
 * 仅保留：登录/注册、大厅、创建房间、房间（残响+理智+聊天）
 * 所有交互通过聊天框 "/" 指令完成
 * ============================================================ */

const API_BASE = window.API_BASE || "/index.php?r=";

const CHARACTERS = [
  { name: "减排除", dept: "灵探", skill: "排除" },
  { name: "许复元", dept: "灵探", skill: "破局" },
  { name: "辛笙",   dept: "灵探", skill: "心声" },
  { name: "意马",   dept: "灵契", skill: "以意化灵" },
  { name: "柳双鱼", dept: "灵契", skill: "拷贝" },
  { name: "柳千渊", dept: "灵者", skill: "现" },
  { name: "孙沐阳", dept: "灵者", skill: "以心为眼" },
];

const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

function escapeHtml(str) {
  return String(str ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}

function escapeJs(str) {
  return JSON.stringify(str ?? "").slice(1, -1);
}

function renderMd(md) {
  if (!md) return "";
  if (typeof marked !== "undefined") {
    if (!renderMd._inited) {
      marked.setOptions({ gfm: true, breaks: true, headerIds: false, mangle: false });
      renderMd._inited = true;
    }
    let src = String(md)
      .replace(/\*\*([^*\n]+)\*\*/g, "<strong>$1</strong>")
      .replace(/\.\/海龟汤图片\//g, "/soups-img/");
    let html = marked.parse(src);
    if (typeof DOMPurify !== "undefined") {
      html = DOMPurify.sanitize(html, {
        ALLOWED_TAGS: [
          "span", "em", "strong", "img", "br", "p", "a", "code", "pre",
          "blockquote", "ul", "ol", "li", "h1", "h2", "h3", "h4", "h5", "h6",
          "table", "thead", "tbody", "tr", "th", "td", "del", "sup", "sub",
          "hr", "div", "dl", "dt", "dd",
        ],
        ALLOWED_ATTR: [
          "style", "alt", "src", "class", "href", "target", "colspan", "rowspan",
          "align", "valign",
        ],
        ALLOW_DATA_ATTR: false,
      });
    }
    return html;
  }
  return escapeHtml(md).replace(/\n/g, "<br>");
}

function fmtTime(iso) {
  if (!iso) return "";
  const d = new Date(iso.replace(" ", "T"));
  if (isNaN(d)) return iso.slice(11, 16);
  return `${d.getHours().toString().padStart(2, "0")}:${d.getMinutes().toString().padStart(2, "0")}`;
}

function toast(msg, type = "") {
  const t = $("#toast");
  if (!t) return;
  t.textContent = msg;
  t.className = "toast show " + type;
  clearTimeout(toast._t);
  toast._t = setTimeout(() => (t.className = "toast " + type), 2600);
}

// ---------- API ----------
const store = {
  user: null,
  csrfToken: "",
  room: null,
  started: false,
  lastMsgId: 0,
  msgTimer: null,
  roomTimer: null,
  soups: [],
  selectedSoupId: 0,
};

const API = {
  resolve(path) {
    return path.startsWith("http") ? path : API_BASE + path;
  },
  async request(path, opts = {}) {
    const headers = { "Content-Type": "application/json", ...(opts.headers || {}) };
    if (store.csrfToken) headers["X-CSRF-Token"] = store.csrfToken;
    const r = await fetch(this.resolve(path), {
      credentials: "same-origin",
      headers,
      ...opts,
    });
    let data = {};
    try {
      data = await r.json();
    } catch {}
    if (r.status === 401 && store.user) {
      store.user = null;
      location.hash = "#/auth";
    }
    return { ok: r.ok, status: r.status, data };
  },
  get(path) {
    return this.request(path, { method: "GET" });
  },
  post(path, body) {
    return this.request(path, { method: "POST", body: JSON.stringify(body ?? {}) });
  },
  put(path, body) {
    return this.request(path, { method: "PUT", body: JSON.stringify(body ?? {}) });
  },
  del(path) {
    return this.request(path, { method: "DELETE" });
  },
};

// ---------- Boot / Route ----------
async function boot() {
  const { data } = await API.get("/api/auth/me");
  if (data && data.user) {
    store.user = data.user;
    store.csrfToken = data.csrf_token || "";
  }
  window.addEventListener("hashchange", route);
  route();
}

function stopPoll() {
  if (store.msgTimer) { clearInterval(store.msgTimer); store.msgTimer = null; }
  if (store.roomTimer) { clearInterval(store.roomTimer); store.roomTimer = null; }
}

function route() {
  stopPoll();
  const hash = location.hash.replace(/^#/, "") || "/";
  if (hash === "/" || hash === "/lobby") return renderLobby();
  if (hash === "/auth") return renderAuth();
  if (hash === "/create") return renderCreate();
  if (hash.startsWith("/room/")) return renderRoom(hash.slice("/room/".length).toUpperCase());
  renderLobby();
}

// ---------- Auth ----------
function renderAuth() {
  if (store.user) { location.hash = "#/lobby"; return; }
  $("#app").innerHTML = `
    <div class="page auth-page">
      <div class="header" style="justify-content:center">
        <div class="logo">海龟汤馆 · 灵之残响</div>
      </div>
      <div class="form">
        <div class="tabs" id="authTabs">
          <button class="tab active" data-mode="login">登录</button>
          <button class="tab" data-mode="register">注册</button>
        </div>
        <div id="authForm"></div>
      </div>
    </div>
  `;
  setAuthMode("login");
  $$("#authTabs .tab").forEach((b) =>
    b.addEventListener("click", () => setAuthMode(b.dataset.mode))
  );
}

window.setAuthMode = (mode) => {
  $$("#authTabs .tab").forEach((b) => b.classList.toggle("active", b.dataset.mode === mode));
  const form = $("#authForm");
  if (mode === "login") {
    form.innerHTML = `
      <div class="field"><label>用户名 / 邮箱</label><input id="acc" type="text" /></div>
      <div class="field"><label>密码</label><input id="pwd" type="password" /></div>
      <button class="btn-primary" style="width:100%" onclick="doLogin()">登录</button>
    `;
  } else {
    form.innerHTML = `
      <div class="field"><label>用户名</label><input id="regUser" type="text" placeholder="2-32 位中英文/数字/下划线" /></div>
      <div class="field"><label>邮箱</label><input id="regEmail" type="email" /></div>
      <div class="field">
        <label>验证码</label>
        <div style="display:flex;gap:8px">
          <input id="regCode" type="text" placeholder="6 位数字" style="flex:1" />
          <button onclick="sendRegCode()" style="flex-shrink:0">获取验证码</button>
        </div>
        <div id="codeHint" style="font-size:.75rem;color:var(--text-3);margin-top:4px"></div>
      </div>
      <div class="field"><label>密码</label><input id="regPwd" type="password" placeholder="至少 8 位" /></div>
      <button class="btn-primary" style="width:100%" onclick="doRegister()">注册</button>
    `;
  }
};

window.doLogin = async () => {
  const account = $("#acc").value.trim();
  const password = $("#pwd").value;
  if (!account || !password) { toast("请填写账号和密码", "err"); return; }
  const { ok, data } = await API.post("/api/auth/login", { account, password });
  if (!ok) { toast(data.error || "登录失败", "err"); return; }
  store.user = data.user;
  store.csrfToken = data.csrf_token;
  location.hash = "#/lobby";
};

window.sendRegCode = async () => {
  const email = $("#regEmail").value.trim().toLowerCase();
  if (!email) { toast("请填写邮箱", "err"); return; }
  const { ok, data } = await API.post("/api/auth/send-code", { email });
  if (!ok) { toast(data.error || "发送失败", "err"); return; }
  const hint = $("#codeHint");
  if (data.dev_mode) {
    hint.innerHTML = `开发模式验证码：<strong style="color:var(--accent)">${escapeHtml(data.msg.split(": ")[1] || "")}</strong>（token 已自动填充到控制台）`;
    console.log("dev code token", data.token);
  } else {
    hint.textContent = data.msg || "验证码已发送";
  }
  if (data.token) store.regToken = data.token;
};

window.doRegister = async () => {
  const username = $("#regUser").value.trim();
  const email = $("#regEmail").value.trim().toLowerCase();
  const code = $("#regCode").value.trim();
  const password = $("#regPwd").value;
  if (!username || !email || !code || !password) { toast("所有字段都不能为空", "err"); return; }
  const { ok, data } = await API.post("/api/auth/register", {
    username, email, code, password, token: store.regToken || "",
  });
  if (!ok) { toast(data.error || "注册失败", "err"); return; }
  store.user = data.user;
  store.csrfToken = data.csrf_token;
  location.hash = "#/lobby";
};

window.logout = async () => {
  await API.post("/api/auth/logout", {});
  store.user = null;
  store.csrfToken = "";
  location.hash = "#/auth";
};

// ---------- Lobby ----------
async function renderLobby() {
  if (!store.user) { location.hash = "#/auth"; return; }
  $("#app").innerHTML = `
    <div class="page">
      <header class="header">
        <div class="logo">海龟汤馆</div>
        <div class="header-actions">
          <span style="color:var(--text-2);font-size:.85rem">${escapeHtml(store.user.username)}</span>
          <button class="btn-ghost" onclick="logout()">退出</button>
        </div>
      </header>
      <div class="lobby">
        <div class="lobby-section">
          <h2>加入房间</h2>
          <div class="card" style="display:flex;gap:8px">
            <input id="joinCode" placeholder="输入 6 位房间号" maxlength="8" style="flex:1;text-transform:uppercase" />
            <button onclick="joinRoom()">加入</button>
          </div>
        </div>
        <div class="lobby-section">
          <h2>公开房间</h2>
          <div id="roomList"><div class="card" style="color:var(--text-3)">加载中…</div></div>
        </div>
        <button class="btn-primary" style="width:100%" onclick="location.hash='#/create'">创建房间</button>
      </div>
    </div>
  `;
  const { ok, data } = await API.get("/api/rooms");
  const list = $("#roomList");
  if (!ok || !data.rooms || !data.rooms.length) {
    list.innerHTML = `<div class="card" style="color:var(--text-3)">暂无进行中的房间，快来创建一个吧</div>`;
    return;
  }
  list.innerHTML = data.rooms.map((r) => `
    <div class="card">
      <div class="card-title">房间 ${escapeHtml(r.code)} · ${escapeHtml(r.soup_title || "未命名汤")}</div>
      <div class="card-meta">
        房主：${escapeHtml(r.host?.username || "-")} · ${r.member_count || 0}/4
        ${r.game_started ? "· 游戏中" : "· 等待中"}
      </div>
      <div style="margin-top:10px">
        <button onclick="joinRoom('${escapeJs(r.code)}')" ${r.member_count >= 4 || r.game_started ? "disabled" : ""}>加入</button>
      </div>
    </div>
  `).join("");
}

window.joinRoom = async (code) => {
  code = (code || $("#joinCode")?.value || "").trim().toUpperCase();
  if (!code) { toast("请输入房间号", "err"); return; }
  const { ok, data } = await API.post(`/api/rooms/${code}/join`, {});
  if (!ok) { toast(data.error || "加入失败", "err"); return; }
  location.hash = `#/room/${code}`;
};

// ---------- Create Room ----------
async function renderCreate() {
  if (!store.user) { location.hash = "#/auth"; return; }
  $("#app").innerHTML = `
    <div class="page">
      <header class="header">
        <div class="logo">创建房间</div>
        <button class="btn-ghost" onclick="location.hash='#/lobby'">返回</button>
      </header>
      <div class="lobby">
        <div class="field">
          <label>选择汤题</label>
          <input id="soupSearch" placeholder="搜索标题…" />
        </div>
        <div id="soupList" class="soup-grid" style="margin-bottom:12px"></div>
        <div class="card" style="display:flex;flex-direction:column;gap:10px">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
            <input type="checkbox" id="aiEnabled" checked /> 启用 AI 主持人
          </label>
          <div class="field" style="margin:0">
            <label>AI 提问上限（0 = 不限制）</label>
            <input type="number" id="aiLimit" value="0" min="0" />
          </div>
          <button class="btn-primary" onclick="createRoom()">创建 4 人房间</button>
        </div>
      </div>
    </div>
  `;
  const { ok, data } = await API.get("/api/soups");
  if (!ok) { toast("汤题加载失败", "err"); return; }
  store.soups = (data.soups || []).filter((s) => s.title && s.id);
  if (store.soups.length) store.selectedSoupId = store.soups[0].id;
  renderSoupList();
  $("#soupSearch")?.addEventListener("input", renderSoupList);
}

function renderSoupList() {
  const q = ($("#soupSearch")?.value || "").toLowerCase();
  const items = store.soups.filter((s) =>
    !q || (s.title || "").toLowerCase().includes(q) || (s.season || "").toLowerCase().includes(q)
  );
  const el = $("#soupList");
  if (!items.length) { el.innerHTML = `<div style="color:var(--text-3)">无匹配汤题</div>`; return; }
  el.innerHTML = items.map((s) => `
    <div class="soup-item ${store.selectedSoupId === s.id ? "active" : ""}" onclick="selectSoup(${s.id})">
      <div class="card-title">${escapeHtml(s.title)}</div>
      <div class="card-meta">${escapeHtml(s.season || "官方")}${s.episode ? " · " + escapeHtml(s.episode) : ""}</div>
    </div>
  `).join("");
}

window.selectSoup = (id) => { store.selectedSoupId = id; renderSoupList(); };

window.createRoom = async () => {
  if (!store.selectedSoupId) { toast("请选择汤题", "err"); return; }
  const ai_enabled = $("#aiEnabled").checked;
  const ai_question_limit = parseInt($("#aiLimit").value || "0", 10) || 0;
  const { ok, data } = await API.post("/api/rooms", {
    soup_id: store.selectedSoupId,
    ai_enabled,
    ai_question_limit,
  });
  if (!ok) { toast(data.error || "创建失败", "err"); return; }
  location.hash = `#/room/${data.code}`;
};

// ---------- Room ----------
async function renderRoom(code) {
  if (!store.user) { location.hash = "#/auth"; return; }
  const { ok, data } = await API.get(`/api/rooms/${code}`);
  if (!ok) { toast(data.error || "房间加载失败", "err"); location.hash = "#/lobby"; return; }
  store.room = data;
  store.started = data.game_started;

  const me = myMember(data) || {};
  const mySanity = me.sanity ?? data.initial_sanity ?? 100;

  $("#app").innerHTML = `
    <div class="page">
      <header class="header">
        <div class="hud-info" style="min-width:0">
          <div class="hud-title">${escapeHtml(data.soup_title || "海龟汤")}</div>
          <div class="hud-sub" id="roomStatus">
            房间 ${escapeHtml(data.code)} · ${data.member_count || 0}/4 ${data.game_started ? "· 游戏中" : "· 等待中"}
          </div>
        </div>
        <div class="hud-sanity" style="text-align:right;flex-shrink:0">
          <div class="val" id="hudSanityVal" style="color:${mySanity <= 0 ? "var(--danger)" : "var(--accent)"}">${mySanity}</div>
          <div class="label">剩余理智</div>
        </div>
      </header>

      <div class="resonance">
        <div class="resonance-label">当前残响</div>
        <div id="resonanceBody">${renderMd(data.current_resonance || "（等待房主播报…）")}</div>
      </div>

      ${data.is_host ? renderHostTools(data) : ""}

      ${!data.game_started ? renderPreGame(data) : ""}

      <div class="chat-wrap">
        <div class="chat-messages" id="chatMessages"></div>
        <div class="chat-input">
          <input type="text" id="chatInput" autocomplete="off" />
          <button onclick="sendInput()">发送</button>
        </div>
      </div>
    </div>
  `;

  updateInputState();
  await loadMessages(code, true);
  startPoll(code);

  $("#chatInput")?.addEventListener("keydown", (e) => {
    if (e.key === "Enter") sendInput();
  });
}

function renderHostTools(room) {
  return `
    <div class="host-tools" id="hostTools">
      <button onclick="showSoup()">查看汤底</button>
      <button onclick="setResonancePrompt()">更新残响</button>
      <button onclick="releaseMutePrompt()">解除禁言</button>
      <button onclick="dissolveRoom()" style="color:var(--danger)">解散房间</button>
    </div>
  `;
}

function renderPreGame(room) {
  const me = myMember(room) || {};
  const canStart = room.is_host && room.member_count === 4 && room.members.every((m) => m.character);
  return `
    <div class="lobby" id="preGamePanel" style="border-bottom:1px solid var(--line);padding-bottom:12px">
      <div class="card">
        <h3 style="margin:0 0 10px">成员与角色</h3>
        <div id="membersList">${renderMembers(room)}</div>
      </div>
      <div class="card">
        <h3 style="margin:0 0 10px">选择角色</h3>
        <div class="character-grid">
          ${CHARACTERS.map((c) => `
            <div class="character-card ${me.character === c.name ? "selected" : ""}" onclick="selectChar('${escapeJs(c.name)}')">
              <div class="dept">${escapeHtml(c.dept)}</div>
              <div style="font-weight:600">${escapeHtml(c.name)}</div>
              <div style="font-size:.7rem;color:var(--text-3);margin-top:2px">${escapeHtml(c.skill)}</div>
            </div>
          `).join("")}
        </div>
      </div>
      ${room.is_host ? `
        <button class="btn-primary" id="startBtn" style="width:100%" onclick="startGame()" ${canStart ? "" : "disabled"}>
          ${canStart ? "开始游戏（4 人已满）" : "等待 4 人并选好角色"}
        </button>
      ` : ""}
      <div style="font-size:.75rem;color:var(--text-3);margin-top:8px">
        指令：/character 角色名 · /ask 问题 · /skill 角色名 参数
      </div>
    </div>
  `;
}

function renderMembers(room) {
  return room.members.map((m) => `
    <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid var(--line)">
      <span>${escapeHtml(m.username)} ${m.role === "host" ? "（房主）" : ""}</span>
      <span style="color:var(--text-2);font-size:.85rem">
        ${m.character ? `${escapeHtml(m.character)} · ${escapeHtml(m.dept || "-")}` : "未选角色"}
        ${room.is_host && m.role !== "host" ? `<button class="btn-ghost" style="margin-left:8px;padding:4px 8px;font-size:.7rem" onclick="kickMember(${m.user_id}, '${escapeJs(m.username)}')">踢出</button>` : ""}
      </span>
    </div>
  `).join("") || "暂无成员";
}

function myMember(room) {
  return (room?.members || []).find((m) => m.user_id === store.user?.id);
}

function updateInputState() {
  const input = $("#chatInput");
  if (!input) return;
  const room = store.room;
  if (!room) return;
  const me = myMember(room) || {};
  const muted = me.muted_until && new Date(me.muted_until.replace(" ", "T")) > new Date();
  if (!room.game_started) {
    input.placeholder = "游戏尚未开始";
    input.disabled = true;
  } else if (muted) {
    input.placeholder = "幻灵禁言中，无法发言";
    input.disabled = true;
  } else {
    input.placeholder = "输入消息，/ask 提问，/skill 使用技能";
    input.disabled = false;
  }
}

// ---------- Polling ----------
function startPoll(code) {
  store.msgTimer = setInterval(() => loadMessages(code), 2000);
  store.roomTimer = setInterval(() => syncRoom(code), 5000);
}

async function loadMessages(code, reset = false) {
  if (reset) store.lastMsgId = 0;
  const { ok, data } = await API.get(`/api/rooms/${code}/messages?since=${store.lastMsgId}`);
  if (!ok || !data.messages) return;
  const box = $("#chatMessages");
  const msgs = data.messages;
  if (reset && box) box.innerHTML = "";
  msgs.forEach((m) => {
    store.lastMsgId = Math.max(store.lastMsgId, m.id);
    appendMessage(m);
  });
}

function appendMessage(m) {
  const box = $("#chatMessages");
  if (!box) return;
  const self = m.user_id && m.user_id === store.user?.id;
  let cls = "msg-other";
  let html = "";
  switch (m.msg_type) {
    case "system":
      cls = "msg-system";
      html = escapeHtml(m.content);
      break;
    case "chat":
      cls = self ? "msg-self" : "msg-other";
      html = `<div class="msg-author">${escapeHtml(m.username || "-")}</div>${escapeHtml(m.content)}<div class="msg-time">${fmtTime(m.created_at)}</div>`;
      break;
    case "host_question":
      cls = "msg-host";
      html = `<div class="msg-author">${escapeHtml(m.username || "-")} 提问</div>${escapeHtml(m.content)}<div class="msg-time">${fmtTime(m.created_at)}</div>`;
      break;
    case "host_answer":
      cls = "msg-host";
      html = `<div class="msg-author">主持人</div>${escapeHtml(m.content)}<div class="msg-time">${fmtTime(m.created_at)}</div>`;
      break;
    case "ai":
      cls = "msg-ai";
      html = `<div class="msg-author">AI 主持人</div>${escapeHtml(m.content)}<div class="msg-time">${fmtTime(m.created_at)}</div>`;
      break;
    case "skill":
      cls = "msg-system";
      html = `${escapeHtml(m.username || "-")} 使用技能：${escapeHtml(m.content)}${m.meta?.result ? `<br><em style="color:var(--text-2)">${escapeHtml(m.meta.result)}</em>` : ""}`;
      break;
    case "fragment":
      cls = "msg-system";
      html = `💠 ${escapeHtml(m.content)}`;
      break;
    default:
      html = escapeHtml(m.content);
  }
  const div = document.createElement("div");
  div.className = `msg ${cls}`;
  div.innerHTML = html;
  box.appendChild(div);
  box.scrollTop = box.scrollHeight;
}

async function syncRoom(code) {
  const { ok, data } = await API.get(`/api/rooms/${code}`);
  if (!ok) return;
  const oldStarted = store.started;
  store.room = data;
  store.started = data.game_started;

  const me = myMember(data) || {};
  const sanityEl = $("#hudSanityVal");
  if (sanityEl) {
    sanityEl.textContent = me.sanity ?? data.initial_sanity ?? 100;
    sanityEl.style.color = (me.sanity ?? 100) <= 0 ? "var(--danger)" : "var(--accent)";
  }
  const statusEl = $("#roomStatus");
  if (statusEl) statusEl.textContent = `房间 ${escapeHtml(data.code)} · ${data.member_count || 0}/4 ${data.game_started ? "· 游戏中" : "· 等待中"}`;
  const resEl = $("#resonanceBody");
  if (resEl) resEl.innerHTML = renderMd(data.current_resonance || "（等待房主播报…）");

  if (!data.game_started) {
    const membersEl = $("#membersList");
    if (membersEl) membersEl.innerHTML = renderMembers(data);
    const startBtn = $("#startBtn");
    if (startBtn) {
      const canStart = data.is_host && data.member_count === 4 && data.members.every((m) => m.character);
      startBtn.disabled = !canStart;
      startBtn.textContent = canStart ? "开始游戏（4 人已满）" : "等待 4 人并选好角色";
    }
  } else if (!oldStarted && data.game_started) {
    renderRoom(code);
    return;
  }
  updateInputState();
}

// ---------- Commands ----------
window.sendInput = async () => {
  const input = $("#chatInput");
  const text = input?.value.trim();
  if (!text) return;
  input.value = "";
  const room = store.room;
  if (!room) return;
  const code = room.code;

  if (text.startsWith("/")) {
    const line = text.slice(1);
    const idx = line.indexOf(" ");
    const cmd = (idx === -1 ? line : line.slice(0, idx)).toLowerCase();
    const arg = idx === -1 ? "" : line.slice(idx + 1).trim();
    await runCommand(code, cmd, arg);
    return;
  }

  if (!room.game_started) { toast("游戏尚未开始，无法发言", "err"); return; }
  const { ok, data } = await API.post(`/api/rooms/${code}/messages`, { content: text });
  if (!ok) toast(data.error || "发送失败", "err");
};

async function runCommand(code, cmd, arg) {
  const room = store.room;
  if (cmd === "ask") {
    if (!arg) { toast("请输入问题：/ask 问题", "err"); return; }
    const { ok, data } = await API.post(`/api/rooms/${code}/ask`, { question: arg });
    if (!ok) { toast(data.error || "提问失败", "err"); return; }
    if (data.from_ai) appendMessage({ id: Date.now(), msg_type: "ai", username: "AI主持人", content: data.answer, created_at: new Date().toISOString() });
    return;
  }
  if (cmd === "skill") {
    if (!arg) { toast("格式：/skill 角色名 参数", "err"); return; }
    const { ok, data } = await API.post(`/api/rooms/${code}/skill`, { content: "/skill " + arg });
    if (!ok) { toast(data.error || "技能发动失败", "err"); return; }
    toast(data.result || "技能已发动");
    return;
  }
  if (cmd === "character") {
    if (!arg) { toast("格式：/character 角色名", "err"); return; }
    const { ok, data } = await API.post(`/api/rooms/${code}/character`, { character: arg });
    if (!ok) { toast(data.error || "选择失败", "err"); return; }
    toast("已选择角色");
    syncRoom(code);
    return;
  }
  if (cmd === "start") {
    const { ok, data } = await API.post(`/api/rooms/${code}/start`, {});
    if (!ok) { toast(data.error || "开始失败", "err"); return; }
    toast("游戏开始");
    renderRoom(code);
    return;
  }
  if (cmd === "answer") {
    if (!room.is_host) { toast("只有房主可以回答", "err"); return; }
    if (!arg) { toast("格式：/answer 是/否/无关", "err"); return; }
    const { ok, data } = await API.post(`/api/rooms/${code}/answer`, { answer: arg });
    if (!ok) { toast(data.error || "回答失败", "err"); return; }
    return;
  }
  if (cmd === "resonance") {
    if (!room.is_host) { toast("只有房主可以更新残响", "err"); return; }
    if (!arg) { toast("格式：/resonance 残响内容", "err"); return; }
    const { ok, data } = await API.post(`/api/rooms/${code}/resonance`, { resonance: arg });
    if (!ok) { toast(data.error || "更新失败", "err"); return; }
    return;
  }
  if (cmd === "kick") {
    if (!room.is_host) { toast("只有房主可以踢人", "err"); return; }
    const target = findMemberByName(arg);
    if (!target) { toast("未找到该玩家", "err"); return; }
    const { ok, data } = await API.post(`/api/rooms/${code}/kick`, { user_id: target.user_id });
    if (!ok) { toast(data.error || "踢出失败", "err"); return; }
    return;
  }
  if (cmd === "unmute") {
    if (!room.is_host) { toast("只有房主可以解除禁言", "err"); return; }
    const target = findMemberByName(arg);
    if (!target) { toast("未找到该玩家", "err"); return; }
    const { ok, data } = await API.post(`/api/rooms/${code}/release-mute`, { user_id: target.user_id });
    if (!ok) { toast(data.error || "解除失败", "err"); return; }
    return;
  }
  if (cmd === "end" || cmd === "dissolve") {
    if (!room.is_host) { toast("只有房主可以解散房间", "err"); return; }
    if (!confirm("确定要解散房间吗？")) return;
    const { ok, data } = await API.del(`/api/rooms/${code}`);
    if (!ok) { toast(data.error || "解散失败", "err"); return; }
    location.hash = "#/lobby";
    return;
  }
  if (cmd === "soup") {
    if (!room.is_host) { toast("只有房主可以查看汤底", "err"); return; }
    showSoup();
    return;
  }
  if (cmd === "help") {
    toast("/ask /skill /character /start /answer /resonance /kick /unmute /soup /end");
    return;
  }
  toast("未知指令：/" + cmd, "err");
}

function findMemberByName(name) {
  return (store.room?.members || []).find((m) => m.username === name || (m.character && m.character === name));
}

// ---------- Host actions ----------
window.selectChar = async (name) => {
  if (!store.room) return;
  const { ok, data } = await API.post(`/api/rooms/${store.room.code}/character`, { character: name });
  if (!ok) { toast(data.error || "选择失败", "err"); return; }
  toast("已选择角色");
  syncRoom(store.room.code);
};

window.startGame = async () => {
  if (!store.room) return;
  const { ok, data } = await API.post(`/api/rooms/${store.room.code}/start`, {});
  if (!ok) { toast(data.error || "开始失败", "err"); return; }
  toast("游戏开始");
  renderRoom(store.room.code);
};

window.kickMember = async (userId, username) => {
  if (!store.room) return;
  if (!confirm(`确定踢出 ${username}？`)) return;
  const { ok, data } = await API.post(`/api/rooms/${store.room.code}/kick`, { user_id: userId });
  if (!ok) { toast(data.error || "踢出失败", "err"); return; }
  syncRoom(store.room.code);
};

window.showSoup = async () => {
  if (!store.room || !store.room.is_host) { toast("只有房主可以查看", "err"); return; }
  const { ok, data } = await API.get(`/api/rooms/${store.room.code}/host-soup`);
  if (!ok) { toast(data.error || "加载失败", "err"); return; }
  openModal("汤底", `
    <div style="margin-bottom:12px"><strong>汤面</strong><div class="md-body" style="margin-top:4px">${renderMd(data.surface)}</div></div>
    <div style="margin-bottom:12px"><strong>汤底</strong><div class="md-body" style="margin-top:4px">${renderMd(data.base)}</div></div>
    ${data.host_manual ? `<div style="margin-bottom:12px"><strong>主持人手册</strong><div class="md-body" style="margin-top:4px">${renderMd(data.host_manual)}</div></div>` : ""}
    ${data.extra ? `<div><strong>其他内容</strong><div class="md-body" style="margin-top:4px">${renderMd(data.extra)}</div></div>` : ""}
  `);
};

window.setResonancePrompt = async () => {
  if (!store.room || !store.room.is_host) return;
  const text = prompt("更新当前残响", store.room.current_resonance || "");
  if (text === null) return;
  const { ok, data } = await API.post(`/api/rooms/${store.room.code}/resonance`, { resonance: text });
  if (!ok) { toast(data.error || "更新失败", "err"); return; }
  syncRoom(store.room.code);
};

window.releaseMutePrompt = async () => {
  if (!store.room || !store.room.is_host) return;
  const name = prompt("输入要解除禁言的玩家用户名");
  if (!name) return;
  const target = findMemberByName(name);
  if (!target) { toast("未找到玩家", "err"); return; }
  const { ok, data } = await API.post(`/api/rooms/${store.room.code}/release-mute`, { user_id: target.user_id });
  if (!ok) { toast(data.error || "解除失败", "err"); return; }
  syncRoom(store.room.code);
};

window.dissolveRoom = async () => {
  if (!store.room) return;
  if (!confirm("确定解散房间？")) return;
  const { ok, data } = await API.del(`/api/rooms/${store.room.code}`);
  if (!ok) { toast(data.error || "解散失败", "err"); return; }
  location.hash = "#/lobby";
};

// ---------- Modal ----------
function openModal(title, bodyHtml) {
  let root = $("#modalRoot");
  if (!root) {
    root = document.createElement("div");
    root.id = "modalRoot";
    document.body.appendChild(root);
  }
  root.innerHTML = `
    <div class="modal-overlay" onclick="closeModal(event)">
      <div class="modal" onclick="event.stopPropagation()">
        <button class="modal-close" onclick="closeModal()">&times;</button>
        <h3>${escapeHtml(title)}</h3>
        <div>${bodyHtml}</div>
      </div>
    </div>
  `;
}

window.closeModal = (e) => {
  if (e && e.target !== e.currentTarget) return;
  const root = $("#modalRoot");
  if (root) root.innerHTML = "";
};

// ---------- Init ----------
document.addEventListener("DOMContentLoaded", boot);
